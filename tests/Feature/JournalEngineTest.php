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
use App\Models\FrcService;
use App\Models\JournalEntry;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\GeneralLedgerService;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use App\Services\JournalWorkflowService;
use App\Services\OpeningBalancePostingService;
use App\Services\TrialBalanceService;
use App\Services\VoucherTypeService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $this->year = FiscalYear::factory()->create([
        'name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'status' => FiscalYearStatus::Open, 'is_current' => true,
    ]);
    $this->period = AccountingPeriod::factory()->create([
        'fiscal_year_id' => $this->year->id, 'name' => 'January 2026', 'period_number' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open,
    ]);
    $this->branch = Branch::factory()->create();
    $this->debitAccount = Account::factory()->create(['code' => '111001']);
    $this->creditAccount = Account::factory()->create(['code' => '411001', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->voucherType = VoucherType::query()->where('code', 'JV')->firstOrFail();
});

function validJournalPayload(object $test, string $amount = '100.0000'): array
{
    return [
        'voucher_type_id' => $test->voucherType->id,
        'transaction_date' => '2026-01-15',
        'fiscal_year_id' => $test->year->id,
        'accounting_period_id' => $test->period->id,
        'branch_id' => $test->branch->id,
        'narration' => 'Valid isolated journal fixture',
        'reference_number' => 'TEST-REF',
        'lines' => [
            ['account_id' => $test->debitAccount->id, 'debit' => $amount, 'credit' => '0'],
            ['account_id' => $test->creditAccount->id, 'debit' => '0', 'credit' => $amount],
        ],
    ];
}

function approvedJournal(object $test): JournalEntry
{
    $workflow = app(JournalWorkflowService::class);
    $test->actingAs($test->maker);
    $journal = $workflow->create(validJournalPayload($test), $test->maker);
    $workflow->submit($journal, $test->maker);
    $test->actingAs($test->checker);
    $workflow->approve($journal->fresh(), $test->checker);

    return $journal->fresh();
}

function postJournalAt(object $test, string $date, string $amount): JournalEntry
{
    $workflow = app(JournalWorkflowService::class);
    $payload = validJournalPayload($test, $amount);
    $payload['transaction_date'] = $date;
    $test->actingAs($test->maker);
    $journal = $workflow->create($payload, $test->maker);
    $workflow->submit($journal, $test->maker);
    $test->actingAs($test->checker);
    $workflow->approve($journal->fresh(), $test->checker);

    return app(JournalPostingService::class)->post($journal->fresh(), $test->checker);
}

test('journal follows maker checker lifecycle and posts atomically', function () {
    $journal = approvedJournal($this);
    $posted = app(JournalPostingService::class)->post($journal, $this->checker);

    expect($posted->status)->toBe(JournalStatus::Posted)
        ->and($posted->voucher_number)->toBe('JV-2026-00001')
        ->and($posted->total_debit)->toBe('100.0000')
        ->and($posted->total_credit)->toBe('100.0000')
        ->and($posted->created_by)->toBe($this->maker->id)
        ->and($posted->approved_by)->toBe($this->checker->id)
        ->and($posted->posted_by)->toBe($this->checker->id);
});

test('unbalanced one line negative zero and dual sided journals are rejected', function (string $case) {
    $lines = match ($case) {
        'one line' => [['account_id' => $this->debitAccount->id, 'debit' => '10', 'credit' => '0']],
        'unbalanced' => [['account_id' => $this->debitAccount->id, 'debit' => '10', 'credit' => '0'], ['account_id' => $this->creditAccount->id, 'debit' => '0', 'credit' => '9']],
        'negative' => [['account_id' => $this->debitAccount->id, 'debit' => '-10', 'credit' => '0'], ['account_id' => $this->creditAccount->id, 'debit' => '0', 'credit' => '-10']],
        'zero' => [['account_id' => $this->debitAccount->id, 'debit' => '0', 'credit' => '0'], ['account_id' => $this->creditAccount->id, 'debit' => '0', 'credit' => '0']],
        'both sides' => [['account_id' => $this->debitAccount->id, 'debit' => '10', 'credit' => '10'], ['account_id' => $this->creditAccount->id, 'debit' => '10', 'credit' => '10']],
    };
    $payload = validJournalPayload($this);
    $payload['lines'] = $lines;
    $this->actingAs($this->maker);
    $journal = app(JournalWorkflowService::class)->create($payload, $this->maker);

    expect(fn () => app(JournalWorkflowService::class)->submit($journal, $this->maker))->toThrow(ValidationException::class);
})->with([
    'one line',
    'unbalanced',
    'negative',
    'zero',
    'both sides',
]);

test('inactive and non postable accounts are rejected', function (array $attributes) {
    $blocked = Account::factory()->create($attributes);
    $payload = validJournalPayload($this);
    $payload['lines'][0]['account_id'] = $blocked->id;
    $this->actingAs($this->maker);
    $journal = app(JournalWorkflowService::class)->create($payload, $this->maker);

    expect(fn () => app(JournalWorkflowService::class)->submit($journal, $this->maker))->toThrow(ValidationException::class);
})->with([
    'inactive' => [['is_active' => false]],
    'non postable' => [['is_postable' => false, 'level' => 'group']],
]);

test('creator cannot approve their own submitted journal', function () {
    $workflow = app(JournalWorkflowService::class);
    $this->actingAs($this->maker);
    $journal = $workflow->create(validJournalPayload($this), $this->maker);
    $workflow->submit($journal, $this->maker);

    expect(fn () => $workflow->approve($journal->fresh(), $this->maker))->toThrow(ValidationException::class);
});

test('rejected journal returns to an editable controlled draft', function () {
    $workflow = app(JournalWorkflowService::class);
    $this->actingAs($this->maker);
    $journal = $workflow->create(validJournalPayload($this), $this->maker);
    $workflow->submit($journal, $this->maker);
    $this->actingAs($this->checker);
    $workflow->reject($journal->fresh(), $this->checker, 'Reference needs correction');
    $this->actingAs($this->maker);
    $updated = $workflow->update($journal->fresh(), [...validJournalPayload($this), 'reference_number' => 'CORRECTED'], $this->maker);

    expect($updated->status)->toBe(JournalStatus::Draft)
        ->and($updated->reference_number)->toBe('CORRECTED')
        ->and($updated->rejection_reason)->toBeNull();
});

test('inactive service and draft fiscal year are rejected by the posting domain', function () {
    $service = FrcService::factory()->create(['status' => 'inactive']);
    $payload = validJournalPayload($this);
    $payload['lines'][0]['service_id'] = $service->id;
    $this->actingAs($this->maker);
    $journal = app(JournalWorkflowService::class)->create($payload, $this->maker);
    expect(fn () => app(JournalWorkflowService::class)->submit($journal, $this->maker))->toThrow(ValidationException::class);

    $approved = approvedJournal($this);
    $this->year->update(['status' => FiscalYearStatus::Draft]);
    expect(fn () => app(JournalPostingService::class)->post($approved, $this->checker))->toThrow(ValidationException::class);
});

test('closed period prevents posting and rolls back voucher allocation', function () {
    $journal = approvedJournal($this);
    $this->period->update(['status' => AccountingPeriodStatus::Closed]);

    expect(fn () => app(JournalPostingService::class)->post($journal, $this->checker))->toThrow(ValidationException::class);
    expect($journal->fresh()->status)->toBe(JournalStatus::Approved)
        ->and($journal->fresh()->voucher_number)->toBeNull();
});

test('posted journal cannot be edited or posted twice and voucher numbers stay unique', function () {
    $first = app(JournalPostingService::class)->post(approvedJournal($this), $this->checker);
    expect(fn () => app(JournalWorkflowService::class)->update($first, validJournalPayload($this), $this->maker))->toThrow(ValidationException::class);
    expect(fn () => app(JournalPostingService::class)->post($first, $this->checker))->toThrow(ValidationException::class);

    $second = app(JournalPostingService::class)->post(approvedJournal($this), $this->checker);
    expect($second->voucher_number)->toBe('JV-2026-00002')
        ->and(JournalEntry::query()->distinct()->count('voucher_number'))->toBe(2);
});

test('posted journal header and lines are immutable even through direct model writes', function () {
    $posted = app(JournalPostingService::class)->post(approvedJournal($this), $this->checker);

    expect(fn () => $posted->update(['narration' => 'Silent mutation']))->toThrow(LogicException::class)
        ->and(fn () => $posted->lines->first()->update(['debit' => '99.0000']))->toThrow(LogicException::class)
        ->and(fn () => $posted->delete())->toThrow(LogicException::class);
});

test('reversal preserves original and both entries offset in reports', function () {
    $original = app(JournalPostingService::class)->post(approvedJournal($this), $this->checker);
    $reversal = app(JournalReversalService::class)->reverse($original, $this->period, '2026-01-20', 'Entry recorded in error', $this->checker);

    expect($original->fresh()->status)->toBe(JournalStatus::Reversed)
        ->and($reversal->status)->toBe(JournalStatus::Posted)
        ->and($reversal->reversal_of_id)->toBe($original->id)
        ->and($reversal->lines->first()->debit)->toBe($original->lines->first()->credit);
    expect(fn () => app(JournalReversalService::class)->reverse($original->fresh(), $this->period, '2026-01-21', 'Duplicate reversal', $this->checker))->toThrow(ValidationException::class);

    $ledger = app(GeneralLedgerService::class)->report($this->debitAccount, [$this->branch->id], '2026-01-01', '2026-01-31', null);
    $trial = app(TrialBalanceService::class)->report($this->year->id, '2026-01-31', [$this->branch->id]);
    expect($ledger['rows']->total())->toBe(2)->and($ledger['closing_balance'])->toBe('0.0000')
        ->and($trial['balanced'])->toBeTrue()->and($trial['total_debit'])->toBe('0.0000');
});

test('general ledger keeps exact opening and running balances across pagination and excludes drafts', function () {
    postJournalAt($this, '2026-01-05', '25.1234');
    postJournalAt($this, '2026-01-12', '10.0001');
    postJournalAt($this, '2026-01-15', '40.0000');
    $this->actingAs($this->maker);
    app(JournalWorkflowService::class)->create(validJournalPayload($this, '999.9999'), $this->maker);

    LengthAwarePaginator::currentPageResolver(fn () => 1);
    $pageOne = app(GeneralLedgerService::class)->report($this->debitAccount, [$this->branch->id], '2026-01-10', '2026-01-31', null, 1);
    LengthAwarePaginator::currentPageResolver(fn () => 2);
    $pageTwo = app(GeneralLedgerService::class)->report($this->debitAccount, [$this->branch->id], '2026-01-10', '2026-01-31', null, 1);
    LengthAwarePaginator::currentPageResolver(fn () => 1);

    expect($pageOne['opening_balance'])->toBe('25.1234')
        ->and($pageOne['closing_balance'])->toBe('75.1235')
        ->and($pageOne['rows']->first()['running_balance'])->toBe('35.1235')
        ->and($pageTwo['rows']->first()['running_balance'])->toBe('75.1235')
        ->and($pageOne['rows']->total())->toBe(2);
});

test('trial balance rolls ledger balances into parents without double counting', function () {
    $assetClass = Account::factory()->create(['code' => '1', 'level' => 'class', 'is_postable' => false]);
    $assetGroup = Account::factory()->create(['code' => '11', 'level' => 'group', 'is_postable' => false, 'parent_id' => $assetClass->id]);
    $assetSub = Account::factory()->create(['code' => '111', 'level' => 'sub_group', 'is_postable' => false, 'parent_id' => $assetGroup->id]);
    $incomeClass = Account::factory()->create(['code' => '4', 'level' => 'class', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure', 'is_postable' => false]);
    $incomeGroup = Account::factory()->create(['code' => '41', 'level' => 'group', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure', 'is_postable' => false, 'parent_id' => $incomeClass->id]);
    $incomeSub = Account::factory()->create(['code' => '411', 'level' => 'sub_group', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure', 'is_postable' => false, 'parent_id' => $incomeGroup->id]);
    $this->debitAccount->update(['parent_id' => $assetSub->id]);
    $this->creditAccount->update(['parent_id' => $incomeSub->id]);
    postJournalAt($this, '2026-01-15', '88.7654');

    $report = app(TrialBalanceService::class)->report($this->year->id, '2026-01-31', [$this->branch->id]);
    $rows = $report['rows']->keyBy(fn (array $row) => $row['account']->code);

    expect($rows['1']['debit'])->toBe('88.7654')
        ->and($rows['4']['credit'])->toBe('88.7654')
        ->and($report['total_debit'])->toBe('88.7654')
        ->and($report['total_credit'])->toBe('88.7654')
        ->and($report['balanced'])->toBeTrue();
});

test('voucher configuration changes are audited and posted numbering rules are protected', function () {
    app(JournalPostingService::class)->post(approvedJournal($this), $this->checker);
    $this->actingAs($this->checker);
    $service = app(VoucherTypeService::class);
    $data = [
        'name' => 'General Journal Voucher', 'prefix' => 'JV', 'year_format' => 'Y',
        'padding' => 5, 'reset_period' => 'yearly', 'is_active' => true,
    ];
    $updated = $service->update($this->voucherType, $data, $this->checker);

    expect($updated->name)->toBe('General Journal Voucher')
        ->and(AuditLog::query()->where('event', 'voucher_type.updated')->exists())->toBeTrue();

    expect(fn () => $service->update($updated, [...$data, 'prefix' => 'GJV'], $this->checker))->toThrow(ValidationException::class);
});

test('branch scoping rejects cross branch journal access', function () {
    $restricted = User::factory()->create();
    $restricted->assignRole(SystemRole::Accountant->value);
    $otherBranch = Branch::factory()->create();
    $restricted->branches()->attach($otherBranch);
    $journal = app(JournalWorkflowService::class)->create(validJournalPayload($this), $this->maker);

    $this->actingAs($restricted)->get(route('journals.show', $journal))->assertForbidden();
});

test('approved opening balance posts once through journal engine', function () {
    $batch = OpeningBalanceBatch::factory()->create([
        'fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'created_by' => $this->maker->id,
        'total_debit' => '75.00', 'total_credit' => '75.00', 'difference' => '0.00',
    ]);
    $batch->lines()->createMany([
        ['account_id' => $this->debitAccount->id, 'debit' => '75.00', 'credit' => '0'],
        ['account_id' => $this->creditAccount->id, 'debit' => '0', 'credit' => '75.00'],
    ]);
    $this->actingAs($this->checker);
    $service = app(OpeningBalancePostingService::class);
    $service->approve($batch, $this->checker);
    $posted = $service->post($batch->fresh(), $this->checker);

    expect($posted->status)->toBe('posted')->and($posted->journal_entry_id)->not->toBeNull()
        ->and($posted->journalEntry->status)->toBe(JournalStatus::Posted);
    expect(fn () => $service->post($posted, $this->checker))->toThrow(ValidationException::class);
});

test('unauthorized journal routes are rejected server side', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('journals.index'))->assertForbidden();
    $this->actingAs($user)->get(route('reports.general-ledger'))->assertForbidden();
    $this->actingAs($user)->get(route('reports.trial-balance'))->assertForbidden();
    $this->actingAs($user)->get(route('voucher-types.index'))->assertForbidden();
});

test('a draft journal can be discarded with a reason but a posted one cannot', function () {
    $this->actingAs($this->maker);
    $draft = app(JournalWorkflowService::class)->create(validJournalPayload($this), $this->maker);

    $this->get(route('journals.show', $draft))->assertOk()->assertSee('Discard Draft');
    $this->delete(route('journals.destroy', $draft), ['reason' => 'x'])->assertSessionHasErrors('reason');
    $this->delete(route('journals.destroy', $draft), ['reason' => 'Entered twice by mistake'])->assertRedirect(route('journals.index'));

    expect(JournalEntry::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'journal.discarded')->where('auditable_id', $draft->id)->value('properties')['reason'])->toBe('Entered twice by mistake');

    $posted = app(JournalPostingService::class)->post(approvedJournal($this), $this->checker);
    $this->actingAs($this->maker)->delete(route('journals.destroy', $posted), ['reason' => 'Trying to remove history'])->assertSessionHasErrors('status');
    expect(JournalEntry::query()->whereKey($posted->id)->exists())->toBeTrue();

    $outsider = User::factory()->create();
    $outsider->assignRole(SystemRole::Accountant->value);
    $outsider->branches()->attach(Branch::factory()->create());
    $other = app(JournalWorkflowService::class)->create(validJournalPayload($this), $this->maker);
    $this->actingAs($outsider)->delete(route('journals.destroy', $other), ['reason' => 'Not my branch at all'])->assertForbidden();
});
