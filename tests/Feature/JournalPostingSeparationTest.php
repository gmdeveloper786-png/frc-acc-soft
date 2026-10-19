<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\JournalPostingService;
use App\Services\JournalWorkflowService;
use App\Services\OpeningBalancePostingService;
use App\Services\TrialBalanceService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create();
    $this->accountant = postingUser(SystemRole::Accountant, $this->branch);
    $this->secondAccountant = postingUser(SystemRole::Accountant, $this->branch);
    $this->approver = postingUser(SystemRole::Approver, $this->branch);
    $this->admin = postingUser(SystemRole::Administrator, $this->branch);
    $this->director = postingUser(SystemRole::Director, $this->branch);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'name' => 'January 2026', 'period_number' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->income = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->workflow = app(JournalWorkflowService::class);
    $this->posting = app(JournalPostingService::class);
});

function postingUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

/** A manual journal prepared by $maker and approved by $checker, not yet posted. */
function approvedManualJournal(object $test, string $amount, User $checker, ?User $maker = null): JournalEntry
{
    $maker ??= $test->accountant;
    $test->actingAs($maker);
    $journal = $test->workflow->create([
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-20',
        'fiscal_year_id' => $test->year->id, 'accounting_period_id' => $test->period->id, 'branch_id' => $test->branch->id,
        'narration' => 'Posting separation fixture '.$amount, 'reference_number' => 'UAT-P4-SEP',
        'lines' => [['account_id' => $test->expense->id, 'debit' => $amount, 'credit' => '0'], ['account_id' => $test->income->id, 'debit' => '0', 'credit' => $amount]],
    ], $maker);
    $test->workflow->submit($journal, $maker);
    $test->actingAs($checker);
    $test->workflow->approve($journal->fresh(), $checker);

    return $journal->fresh();
}

function expectNotPosted(JournalEntry $journal): void
{
    expect($journal->fresh()->status)->toBe(JournalStatus::Approved)
        ->and($journal->fresh()->voucher_number)->toBeNull()
        ->and($journal->fresh()->posted_by)->toBeNull();
}

test('the preparer cannot post their own approved journal through the service the screen or a forged request', function () {
    $journal = approvedManualJournal($this, '1000.0000', $this->approver);

    expect($this->posting->canPost($journal, $this->accountant))->toBeFalse()
        ->and(fn () => $this->posting->post($journal, $this->accountant))->toThrow(ValidationException::class);
    $this->actingAs($this->accountant)->get(route('journals.show', $journal))->assertOk()->assertDontSee('Post Journal')->assertSee('Posting needs a third user');
    $this->post(route('journals.post', $journal))->assertSessionHasErrors('approval');
    expectNotPosted($journal);
});

test('the approver cannot post what they approved, whether director or administrator', function () {
    $high = approvedManualJournal($this, '150000.0100', $this->director);
    $this->actingAs($this->director)->get(route('journals.show', $high))->assertOk()->assertDontSee('Post Journal');
    $this->post(route('journals.post', $high))->assertSessionHasErrors('approval');

    $low = approvedManualJournal($this, '1000.0000', $this->admin);
    $this->actingAs($this->admin)->get(route('journals.show', $low))->assertOk()->assertDontSee('Post Journal');
    $this->post(route('journals.post', $low))->assertSessionHasErrors('approval');
    expect(fn () => $this->posting->post($low->fresh(), $this->admin))->toThrow(ValidationException::class);

    expectNotPosted($high);
    expectNotPosted($low);
});

test('an independent third user posts with the correct voucher audit and ledger impact', function () {
    $high = approvedManualJournal($this, '150000.0100', $this->director);
    $this->actingAs($this->admin)->get(route('journals.show', $high))->assertOk()->assertSee('Post Journal');
    $this->post(route('journals.post', $high))->assertRedirect()->assertSessionHasNoErrors();

    // A Director is an eligible independent poster of a journal approved by someone else.
    $low = approvedManualJournal($this, '1000.0000', $this->approver);
    $this->actingAs($this->director)->get(route('journals.show', $low))->assertOk()->assertSee('Post Journal');
    $posted = $this->posting->post($low, $this->director);

    $high->refresh();
    expect($high->status)->toBe(JournalStatus::Posted)->and($high->voucher_number)->toStartWith('JV-')
        ->and([$high->created_by, $high->approved_by, $high->posted_by])->toBe([$this->accountant->id, $this->director->id, $this->admin->id])
        ->and($posted->voucher_number)->not->toBe($high->voucher_number)->and($posted->posted_by)->toBe($this->director->id)
        ->and(AuditLog::query()->where('event', 'journal.posted')->where('auditable_id', $high->id)->value('properties'))
        ->toMatchArray(['voucher_number' => $high->voucher_number, 'total_debit' => '150000.0100', 'approved_by' => $this->director->id, 'approval_tier' => 'director', 'authority_basis' => 'director_role'])
        ->and(AuditLog::query()->where('event', 'journal.posted')->where('auditable_id', $low->id)->value('properties'))->toMatchArray(['approval_tier' => 'standard', 'authority_basis' => 'standard']);

    $trial = app(TrialBalanceService::class)->report($this->year->id, '2026-01-31', [$this->branch->id]);
    expect($trial['balanced'])->toBeTrue()->and($trial['total_debit'])->toBe('151000.0100');
});

test('a high value journal cannot be posted once the approving director loses authority or the branch', function () {
    $journal = approvedManualJournal($this, '200000.0000', $this->director);

    $this->director->removeRole(SystemRole::Director->value);
    expect($this->posting->canPost($journal, $this->admin))->toBeFalse()
        ->and(fn () => $this->posting->post($journal->fresh(), $this->admin))->toThrow(ValidationException::class);
    $this->actingAs($this->admin)->get(route('journals.show', $journal))->assertOk()->assertDontSee('Post Journal');

    $this->director->assignRole(SystemRole::Director->value);
    $this->director->branches()->detach($this->branch);
    expect(fn () => $this->posting->post($journal->fresh(), $this->admin))->toThrow(ValidationException::class);

    $this->director->branches()->attach($this->branch);
    $this->director->update(['is_active' => false]);
    expect(fn () => $this->posting->post($journal->fresh(), $this->admin))->toThrow(ValidationException::class);
    expectNotPosted($journal);

    // The controlled path back: return the journal for a fresh approval.
    $this->director->update(['is_active' => true]);
    expect($this->posting->post($journal->fresh(), $this->admin)->status)->toBe(JournalStatus::Posted);
});

test('a low value approval stops counting when the approver loses the approval permission', function () {
    $journal = approvedManualJournal($this, '1000.0000', $this->approver);
    $this->approver->removeRole(SystemRole::Approver->value);

    expect(fn () => $this->posting->post($journal, $this->admin))->toThrow(ValidationException::class);
    expectNotPosted($journal);
});

test('journals approved before this rule obey it at posting time', function () {
    // Approved under the old rules: a high-value journal approved by a non-Director, and one with no recorded approver.
    $legacyHigh = approvedManualJournal($this, '1000.0000', $this->approver);
    DB::table('journal_lines')->where('journal_entry_id', $legacyHigh->id)->where('line_number', 1)->update(['debit' => '300000.0000']);
    DB::table('journal_lines')->where('journal_entry_id', $legacyHigh->id)->where('line_number', 2)->update(['credit' => '300000.0000']);
    DB::table('journal_entries')->where('id', $legacyHigh->id)->update(['total_debit' => '1000.0000', 'total_credit' => '1000.0000']);
    $noApprover = approvedManualJournal($this, '1000.0000', $this->approver);
    DB::table('journal_entries')->where('id', $noApprover->id)->update(['approved_by' => null]);

    foreach (['high value approved by a non-Director' => $legacyHigh, 'no recorded approver' => $noApprover] as $case => $journal) {
        expect($this->posting->canPost($journal->fresh(), $this->admin))->toBeFalse($case)
            ->and(fn () => $this->posting->post($journal->fresh(), $this->admin))->toThrow(ValidationException::class, 'no longer valid');
        expectNotPosted($journal);
    }
});

test('someone who edited the journal counts as a preparer and cannot post it', function () {
    $this->actingAs($this->accountant);
    $payload = [
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-20',
        'fiscal_year_id' => $this->year->id, 'accounting_period_id' => $this->period->id, 'branch_id' => $this->branch->id, 'narration' => 'Edited journal',
        'lines' => [['account_id' => $this->expense->id, 'debit' => '500.0000', 'credit' => '0'], ['account_id' => $this->income->id, 'debit' => '0', 'credit' => '500.0000']],
    ];
    $journal = $this->workflow->create($payload, $this->accountant);
    $this->actingAs($this->secondAccountant);
    $this->workflow->update($journal, [...$payload, 'narration' => 'Edited by a second accountant'], $this->secondAccountant);
    $this->actingAs($this->accountant);
    $this->workflow->submit($journal->fresh(), $this->accountant);
    $this->actingAs($this->approver);
    $this->workflow->approve($journal->fresh(), $this->approver);

    expect(fn () => $this->posting->post($journal->fresh(), $this->secondAccountant))->toThrow(ValidationException::class);
    expect($this->posting->post($journal->fresh(), $this->admin)->status)->toBe(JournalStatus::Posted);
});

test('users without posting permission or branch access are refused and double posting stays blocked', function () {
    $journal = approvedManualJournal($this, '1000.0000', $this->approver);
    $cashier = postingUser(SystemRole::Cashier, $this->branch);
    $auditor = postingUser(SystemRole::Auditor, $this->branch);
    $outsider = postingUser(SystemRole::Accountant, Branch::factory()->create());

    $this->actingAs($cashier)->post(route('journals.post', $journal))->assertForbidden();
    $this->actingAs($auditor)->post(route('journals.post', $journal))->assertForbidden();
    $this->actingAs($this->approver)->post(route('journals.post', $journal))->assertForbidden();
    $this->actingAs($outsider)->post(route('journals.post', $journal))->assertForbidden();
    expect(fn () => $this->posting->post($journal->fresh(), $outsider))->toThrow(HttpException::class);
    expectNotPosted($journal);

    $this->actingAs($this->secondAccountant)->post(route('journals.post', $journal))->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('journals.post', $journal))->assertSessionHasErrors('status');
    expect(fn () => $this->posting->post($journal->fresh(), $this->director))->toThrow(ValidationException::class)
        ->and(JournalEntry::query()->whereKey($journal->id)->value('posted_by'))->toBe($this->secondAccountant->id)
        ->and(AuditLog::query()->where('event', 'journal.posted')->where('auditable_id', $journal->id)->count())->toBe(1);
});

test('system journals keep their own controls and are not subject to the manual three person rule', function () {
    $equity = Account::factory()->create(['account_type' => 'equity', 'normal_balance' => 'credit']);
    $asset = Account::factory()->create(['account_type' => 'asset']);
    $batch = OpeningBalanceBatch::factory()->create(['branch_id' => $this->branch, 'fiscal_year_id' => $this->year, 'opening_date' => '2026-01-01', 'status' => 'approved', 'total_debit' => '5000.0000', 'total_credit' => '5000.0000', 'created_by' => $this->accountant, 'approved_by' => $this->admin, 'approved_at' => now()]);
    $batch->lines()->create(['account_id' => $asset->id, 'debit' => '5000.0000', 'credit' => '0.0000']);
    $batch->lines()->create(['account_id' => $equity->id, 'debit' => '0.0000', 'credit' => '5000.0000']);

    // The opening balance workflow lets its approver post, as before.
    $posted = app(OpeningBalancePostingService::class)->post($batch, $this->admin);
    expect($posted->status)->toBe('posted')
        ->and(JournalEntry::query()->findOrFail($posted->journal_entry_id)->status)->toBe(JournalStatus::Posted);
});
