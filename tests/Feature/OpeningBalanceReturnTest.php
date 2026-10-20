<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Services\OpeningBalancePostingService;
use App\Services\OpeningBalanceService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create();
    $this->otherBranch = Branch::factory()->create();
    $this->maker = returnUser(SystemRole::Accountant, $this->branch);
    $this->poster = returnUser(SystemRole::Accountant, $this->branch);
    $this->approver = returnUser(SystemRole::Approver, $this->branch);
    $this->secondApprover = returnUser(SystemRole::Approver, $this->branch);
    $this->admin = returnUser(SystemRole::Administrator, $this->branch);
    $year = FiscalYear::factory()->create(['name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    AccountingPeriod::factory()->create(['fiscal_year_id' => $year->id, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $asset = Account::factory()->create(['code' => '11102']);
    $equity = Account::factory()->create(['code' => '31101', 'account_type' => 'equity', 'normal_balance' => 'credit']);
    $this->actingAs($this->maker);
    $this->batch = app(OpeningBalanceService::class)->saveDraft([
        'fiscal_year_id' => $year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01', 'source_reference' => 'Import #77',
        'lines' => [['account_id' => $asset->id, 'debit' => '1000.00', 'credit' => '0', 'source_reference' => 'Row 2'], ['account_id' => $equity->id, 'debit' => '0', 'credit' => '1000.00']],
    ], $this->maker);
    $this->service = app(OpeningBalancePostingService::class);
    $this->actingAs($this->approver);
    $this->service->approve($this->batch, $this->approver);
    $this->batch->refresh();
});

function returnUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

function openingSnapshot(OpeningBalanceBatch $batch): array
{
    $batch->refresh();

    return [
        'header' => $batch->only(['fiscal_year_id', 'branch_id', 'total_debit', 'total_credit', 'source_reference', 'created_by', 'updated_by']),
        'lines' => $batch->lines()->get(['account_id', 'debit', 'credit', 'source_reference'])->toArray(),
    ];
}

test('an approved batch returns to draft with its content and preparers intact and the old approval cannot be used', function () {
    $before = openingSnapshot($this->batch);

    $this->actingAs($this->secondApprover)->post(route('opening-balances.return', $this->batch), ['reason' => 'Supporting schedule needs re-checking'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $batch = $this->batch->fresh();
    $log = AuditLog::query()->where('event', 'opening_balance.returned')->where('auditable_id', $batch->id)->sole();
    expect($batch->status)->toBe('draft')->and($batch->approved_by)->toBeNull()->and($batch->approved_at)->toBeNull()
        ->and($batch->rejection_reason)->toBe('Supporting schedule needs re-checking')
        ->and(openingSnapshot($batch))->toBe($before)
        ->and($log->actor_id)->toBe($this->secondApprover->id)->and($log->created_at)->not->toBeNull()
        ->and($log->properties)->toMatchArray(['original_approver_id' => $this->approver->id, 'old_status' => 'approved', 'new_status' => 'draft', 'reason' => 'Supporting schedule needs re-checking', 'approval_was_valid' => true]);

    // The old approval cannot be reused: nothing can be posted until a new approval.
    expect($this->service->canPost($batch, $this->poster))->toBeFalse()
        ->and(fn () => $this->service->post($batch, $this->poster))->toThrow(ValidationException::class)
        ->and(JournalEntry::query()->count())->toBe(0);
    $this->actingAs($this->poster)->get(route('opening-balances.show', $batch))->assertOk()->assertSee('Returned for a new approval:')->assertSee('Supporting schedule needs re-checking');
});

test('a batch stuck with an invalid approval is recovered and goes through a full new approval cycle', function () {
    $this->approver->removeRole(SystemRole::Approver->value);
    expect($this->service->canPost($this->batch, $this->poster))->toBeFalse();
    $this->actingAs($this->poster)->get(route('opening-balances.show', $this->batch))->assertOk()
        ->assertSee('The approval of this opening balance is no longer valid')->assertDontSee('Post through Journal Engine');

    // An independent Administrator recovers it, although the old approver no longer has any approval right.
    $this->actingAs($this->admin)->get(route('opening-balances.show', $this->batch))->assertOk()->assertSee('Return to Draft');
    $this->service->returnToDraft($this->batch, 'Original approver no longer holds the role', $this->admin);
    expect(AuditLog::query()->where('event', 'opening_balance.returned')->value('properties')['approval_was_valid'])->toBeFalse();

    // Returning conferred nothing: the Administrator still cannot post, and the maker still cannot approve.
    expect(fn () => $this->service->approve($this->batch->fresh(), $this->maker))->toThrow(HttpException::class);
    $this->service->approve($this->batch->fresh(), $this->secondApprover);
    expect(fn () => $this->service->post($this->batch->fresh(), $this->secondApprover))->toThrow(HttpException::class)
        ->and(fn () => $this->service->post($this->batch->fresh(), $this->maker))->toThrow(ValidationException::class);

    $posted = $this->service->post($this->batch->fresh(), $this->poster);
    expect($posted->status)->toBe('posted')->and($posted->approved_by)->toBe($this->secondApprover->id)->and($posted->posted_by)->toBe($this->poster->id)
        ->and(JournalEntry::query()->where('source_type', 'opening_balance')->count())->toBe(1)
        ->and(JournalEntry::query()->where('source_type', 'opening_balance')->value('approved_by'))->toBe($this->secondApprover->id);

    // Both approval cycles stay in the history.
    expect(AuditLog::query()->where('auditable_id', $this->batch->id)->where('auditable_type', $this->batch->getMorphClass())->orderBy('id')->pluck('event')->all())
        ->toBe(['opening_balance.draft_saved', 'opening_balance.approved', 'opening_balance.returned', 'opening_balance.approved', 'opening_balance.posted'])
        ->and(AuditLog::query()->where('event', 'opening_balance.approved')->orderBy('id')->pluck('actor_id')->all())->toBe([$this->approver->id, $this->secondApprover->id]);
});

test('preparers unauthorized users and other branches cannot return a batch', function () {
    $cashier = returnUser(SystemRole::Cashier, $this->branch);
    $outsideApprover = returnUser(SystemRole::Approver, $this->otherBranch);
    $form = ['reason' => 'Attempt to withdraw this approval'];

    $this->actingAs($this->maker)->post(route('opening-balances.return', $this->batch), $form)->assertForbidden();
    $this->actingAs($cashier)->post(route('opening-balances.return', $this->batch), $form)->assertForbidden();
    $this->actingAs($outsideApprover)->post(route('opening-balances.return', $this->batch), $form)->assertForbidden();
    $this->actingAs($this->secondApprover)->post(route('opening-balances.return', $this->batch), ['reason' => 'too short'])->assertSessionHasErrors('reason');

    // An Administrator who edited the batch is a preparer and cannot return it either.
    $this->actingAs($this->admin);
    $editedBy = returnUser(SystemRole::Administrator, $this->branch);
    AuditLog::query()->create(['actor_id' => $editedBy->id, 'event' => 'opening_balance.draft_saved', 'auditable_type' => $this->batch->getMorphClass(), 'auditable_id' => $this->batch->id, 'properties' => []]);
    expect($this->service->canReturn($this->batch->fresh(), $editedBy))->toBeFalse()
        ->and(fn () => $this->service->returnToDraft($this->batch->fresh(), 'Editor withdrawing the approval', $editedBy))->toThrow(ValidationException::class);

    $this->actingAs($this->maker)->get(route('opening-balances.show', $this->batch))->assertOk()->assertDontSee('Return to Draft');
    expect($this->batch->fresh()->status)->toBe('approved')->and(AuditLog::query()->where('event', 'opening_balance.returned')->exists())->toBeFalse();
});

test('only an approved unposted batch can be returned and posting and returning cannot interleave', function () {
    // Returned first: the post that follows finds a draft and fails.
    $this->service->returnToDraft($this->batch, 'Withdrawn before posting', $this->secondApprover);
    expect(fn () => $this->service->post($this->batch, $this->poster))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->returnToDraft($this->batch->fresh(), 'Draft cannot be returned', $this->secondApprover))->toThrow(ValidationException::class);

    // Posted first: the return that follows is refused and the journal is untouched.
    $this->service->approve($this->batch->fresh(), $this->approver);
    $posted = $this->service->post($this->batch->fresh(), $this->poster);
    $journal = JournalEntry::query()->findOrFail($posted->journal_entry_id);
    $this->actingAs($this->secondApprover)->post(route('opening-balances.return', $posted), ['reason' => 'Trying to return a posted batch'])->assertSessionHasErrors('status');
    $this->get(route('opening-balances.show', $posted))->assertOk()->assertDontSee('Return to Draft');

    expect($posted->fresh()->status)->toBe('posted')->and($posted->fresh()->approved_by)->toBe($this->approver->id)
        ->and($journal->fresh()->only(['status', 'voucher_number', 'total_debit']))->toBe($journal->only(['status', 'voucher_number', 'total_debit']))
        ->and(JournalEntry::query()->count())->toBe(1);
});

test('the original approver may withdraw their own approval and the maker may then correct and resubmit', function () {
    $this->service->returnToDraft($this->batch, 'I approved this too early', $this->approver);
    $this->actingAs($this->maker);
    app(OpeningBalanceService::class)->saveDraft([
        'fiscal_year_id' => $this->batch->fiscal_year_id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01',
        'lines' => $this->batch->lines()->get(['account_id', 'debit', 'credit'])->map(fn ($line) => ['account_id' => $line->account_id, 'debit' => $line->debit, 'credit' => $line->credit])->all(),
    ], $this->maker);

    expect($this->batch->fresh()->status)->toBe('draft')
        ->and($this->service->canApprove($this->batch->fresh(), $this->maker))->toBeFalse()
        ->and($this->service->canApprove($this->batch->fresh(), $this->secondApprover))->toBeTrue();
    expect($this->service->approve($this->batch->fresh(), $this->secondApprover)->rejection_reason)->toBeNull();
});
