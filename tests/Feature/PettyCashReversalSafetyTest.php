<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\ImprestFloat;
use App\Models\ImprestReplenishment;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use App\Services\JournalWorkflowService;
use App\Services\OperationalTransactionService;
use App\Services\PettyCash\ImprestFloatService;
use App\Services\PettyCash\ImprestLedgerService;
use App\Services\PettyCash\ImprestReplenishmentService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);

    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);

    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);

    $this->poster = User::factory()->create();
    $this->poster->assignRole(SystemRole::Administrator->value);

    $this->year = FiscalYear::factory()->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => FiscalYearStatus::Open,
        'is_current' => true,
    ]);

    $this->period = AccountingPeriod::factory()->create([
        'fiscal_year_id' => $this->year,
        'period_number' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => AccountingPeriodStatus::Open,
    ]);

    $this->branch = Branch::factory()->create([
        'name' => 'Shahrah-e-Faisal',
        'status' => 'publish',
    ]);

    // Use GL accounts matching actual UAT mappings:
    // 11104 — Petty Cash - Rehab Centre
    // 11203 — Bank - Rehab Centre Account
    // 51101 — Office Supplies Expense
    $this->pettyLedger = Account::factory()->create([
        'code' => '11104',
        'name' => 'Petty Cash - Rehab Centre',
        'account_type' => 'asset',
    ]);

    $this->bankLedger = Account::factory()->create([
        'code' => '11203',
        'name' => 'Bank - Rehab Centre Account',
        'account_type' => 'asset',
    ]);

    $this->expenseLedger = Account::factory()->create([
        'code' => '51101',
        'name' => 'Office Supplies Expense',
        'account_type' => 'expense',
        'financial_statement' => 'income_expenditure',
        'direct_payment_eligible' => true,
    ]);

    $this->pettyCash = FinancialAccount::factory()->create([
        'type' => 'cash',
        'name' => 'Petty Cash Box',
        'branch_id' => $this->branch,
        'account_id' => $this->pettyLedger,
    ]);

    $this->bank = FinancialAccount::factory()->create([
        'type' => 'bank',
        'name' => 'Bank Account',
        'branch_id' => $this->branch,
        'account_id' => $this->bankLedger,
    ]);

    $this->actingAs($this->maker);
});

function createFundedFloat(object $test, string $amount = '15000.0000'): ImprestFloat
{
    $float = app(ImprestFloatService::class)->create([
        'branch_id' => $test->branch->id,
        'account_id' => $test->pettyLedger->id,
        'float_amount' => $amount,
        'source_financial_account_id' => $test->bank->id,
        'custodian_name' => 'Front desk custodian',
    ], $test->maker);

    // Initial replenishment to fund the float to its full amount
    $rep = app(ImprestReplenishmentService::class)->request($float, ['transaction_date' => '2026-01-01'], $test->maker);
    $j = $rep->journalEntry;
    app(JournalWorkflowService::class)->submit($j, $test->maker);
    app(JournalWorkflowService::class)->approve($j->fresh(), $test->checker);
    app(JournalPostingService::class)->post($j->fresh(), $test->poster);

    return $float->fresh();
}

function createPostedPettyCashExpense(object $test, string $amount = '500.0000', string $date = '2026-01-10'): OperationalTransaction
{
    $service = app(OperationalTransactionService::class);
    $voucher = $service->create([
        'type' => OperationalTransactionType::PettyCash->value,
        'branch_id' => $test->branch->id,
        'transaction_date' => $date,
        'amount' => $amount,
        'financial_account_id' => $test->pettyCash->id,
        'contra_account_id' => $test->expenseLedger->id,
        'narration' => 'CP Petty Cash Expense',
    ], $test->maker);

    $service->submit($voucher, $test->maker);
    $service->approve($voucher->fresh(), $test->checker);
    $service->disburse($voucher->fresh(), $test->checker);

    return $service->post($voucher->fresh(), $test->checker);
}

function replenishFloat(object $test, ImprestFloat $float, string $date = '2026-01-15'): ImprestReplenishment
{
    $rep = app(ImprestReplenishmentService::class)->request($float, ['transaction_date' => $date], $test->maker);
    $j = $rep->journalEntry;
    app(JournalWorkflowService::class)->submit($j, $test->maker);
    app(JournalWorkflowService::class)->approve($j->fresh(), $test->checker);
    app(JournalPostingService::class)->post($j->fresh(), $test->poster);

    return $rep->fresh();
}

test('expense reversal is blocked while linked replenishment is posted', function () {
    $float = createFundedFloat($this, '15000.0000');
    $expense = createPostedPettyCashExpense($this, '500.0000');
    $replenishment = replenishFloat($this, $float);

    expect($replenishment->status)->toBe('posted')
        ->and($replenishment->covered_voucher_ids)->toContain($expense->id);

    $service = app(OperationalTransactionService::class);

    // Backend service level block
    expect(fn () => $service->reverse($expense, $this->period, '2026-01-20', 'Unsafe reversal test', $this->maker))
        ->toThrow(ValidationException::class, 'covered by replenishment');

    // UI blocked reason check
    $reason = $service->pettyCashReversalBlockedReason($expense);
    expect($reason)->toContain('covered by replenishment')
        ->and($reason)->toContain($replenishment->journalEntry->voucher_number);

    // UI view check
    $this->get(route('transactions.show', $expense))
        ->assertOk()
        ->assertSee('This petty cash voucher is covered by replenishment')
        ->assertDontSee('Create and post reversing journal?');

    // Ensure expense is still posted
    expect($expense->fresh()->status)->toBe(OperationalTransactionStatus::Posted);
});

test('expense reversal is blocked while replenishment reversal is still pending approval or posting', function () {
    $float = createFundedFloat($this, '15000.0000');
    $expense = createPostedPettyCashExpense($this, '500.0000');
    $replenishment = replenishFloat($this, $float);

    $reversalService = app(JournalReversalService::class);
    $opService = app(OperationalTransactionService::class);

    // Step 1: Requester requests reversal of replenishment journal
    $reversalRequest = $reversalService->request(
        $replenishment->journalEntry,
        $this->period,
        '2026-01-20',
        'Replenishment entered in error',
        $this->maker
    );

    expect($reversalRequest->status)->toBe(JournalStatus::Submitted)
        ->and($replenishment->journalEntry->fresh()->status)->toBe(JournalStatus::Posted);

    // Attempting to reverse expense while replenishment reversal is submitted must be blocked
    expect(fn () => $opService->reverse($expense, $this->period, '2026-01-20', 'Attempt during pending request', $this->maker))
        ->toThrow(ValidationException::class, 'covered by replenishment');

    // Step 2: Approver approves reversal
    $reversalService->approve($reversalRequest->fresh(), $this->checker);
    expect($reversalRequest->fresh()->status)->toBe(JournalStatus::Approved)
        ->and($replenishment->journalEntry->fresh()->status)->toBe(JournalStatus::Posted);

    // Attempting to reverse expense while replenishment reversal is approved must still be blocked
    expect(fn () => $opService->reverse($expense, $this->period, '2026-01-20', 'Attempt during approved request', $this->maker))
        ->toThrow(ValidationException::class, 'covered by replenishment');
});

test('safe replenishment-first then expense-second reversal workflow succeeds and restores balances', function () {
    $float = createFundedFloat($this, '15000.0000');
    $ledger = app(ImprestLedgerService::class);

    expect($ledger->balance($float)->toString())->toBe('15000.0000');

    $expense = createPostedPettyCashExpense($this, '500.0000');
    expect($ledger->balance($float)->toString())->toBe('14500.0000');

    $replenishment = replenishFloat($this, $float);
    expect($ledger->balance($float)->toString())->toBe('15000.0000')
        ->and($replenishment->status)->toBe('posted');

    $reversalService = app(JournalReversalService::class);
    $opService = app(OperationalTransactionService::class);

    // 1. Request replenishment reversal
    $reversalRequest = $reversalService->request(
        $replenishment->journalEntry,
        $this->period,
        '2026-01-20',
        'Replenishment reversal for correction',
        $this->maker
    );

    // 2. Approve reversal
    $reversalService->approve($reversalRequest->fresh(), $this->checker);

    // 3. Post reversal (three-user workflow)
    $postedReversal = $reversalService->post($reversalRequest->fresh(), $this->poster);

    expect($postedReversal->status)->toBe(JournalStatus::Posted)
        ->and($replenishment->journalEntry->fresh()->status)->toBe(JournalStatus::Reversed)
        ->and($replenishment->fresh()->status)->toBe(ImprestReplenishment::REVERSED);

    // Balance after replenishment reversal dropped back to 14,500
    expect($ledger->balance($float)->toString())->toBe('14500.0000');

    // Covered vouchers must no longer include expense
    expect($ledger->coveredVoucherIds($float))->not->toContain($expense->id);

    // 4. Now reverse the original petty cash expense
    $reversedExpense = $opService->reverse(
        $expense->fresh(),
        $this->period,
        '2026-01-21',
        'Original expense reversal for correction',
        $this->maker
    );

    expect($reversedExpense->status)->toBe(OperationalTransactionStatus::Reversed);

    // Balance restored back to 15,000
    expect($ledger->balance($float)->toString())->toBe('15000.0000');

    // Verify all journals are balanced
    $allJournals = JournalEntry::query()->with('lines')->get();
    foreach ($allJournals as $journal) {
        expect(AccountingDecimal::of($journal->total_debit)->isEqualTo(AccountingDecimal::of($journal->total_credit)))->toBeTrue();
    }

    // Verify audit logs
    expect(AuditLog::query()->where('event', 'petty_cash.reversed')->where('auditable_id', $expense->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'journal.reversed')->where('auditable_id', $replenishment->journalEntry->id)->exists())->toBeTrue();
});

test('remaining expense is eligible for replenishment again after CV reversal if expense is not reversed', function () {
    $float = createFundedFloat($this, '15000.0000');
    $ledger = app(ImprestLedgerService::class);

    $expense = createPostedPettyCashExpense($this, '500.0000');
    $replenishment = replenishFloat($this, $float);

    // Reverse the replenishment journal completely
    $reversalService = app(JournalReversalService::class);
    $reversalRequest = $reversalService->request($replenishment->journalEntry, $this->period, '2026-01-20', 'CV reversal reason', $this->maker);
    $reversalService->approve($reversalRequest->fresh(), $this->checker);
    $reversalService->post($reversalRequest->fresh(), $this->poster);

    expect($replenishment->fresh()->status)->toBe('reversed');

    // Expense was NOT reversed, still posted
    expect($expense->fresh()->status)->toBe(OperationalTransactionStatus::Posted);

    // Imprest summary shows the voucher in uncovered/posted vouchers again
    $summary = $ledger->summary($float->fresh());
    expect($summary['balance'])->toBe('14500.0000')
        ->and($summary['due'])->toBe('500.0000')
        ->and($summary['posted_vouchers']->pluck('id')->all())->toBe([$expense->id])
        ->and($summary['posted_total'])->toBe('500.0000');

    // A new replenishment can be requested and posted for this expense
    $newRep = app(ImprestReplenishmentService::class)->request($float->fresh(), ['transaction_date' => '2026-01-22'], $this->maker);
    expect($newRep->amount)->toBe('500.0000')
        ->and($newRep->covered_voucher_ids)->toBe([$expense->id]);

    $j = $newRep->journalEntry;
    app(JournalWorkflowService::class)->submit($j, $this->maker);
    app(JournalWorkflowService::class)->approve($j->fresh(), $this->checker);
    app(JournalPostingService::class)->post($j->fresh(), $this->poster);

    expect($ledger->balance($float->fresh())->toString())->toBe('15000.0000')
        ->and($newRep->fresh()->status)->toBe('posted');
});

test('reversing a replenishment that would cause negative petty cash balance is prevented', function () {
    $float = createFundedFloat($this, '15000.0000');

    // Expense 1: 500
    $expense1 = createPostedPettyCashExpense($this, '500.0000', '2026-01-05');
    // Replenish 500
    $replenishment = replenishFloat($this, $float, '2026-01-08');

    // Now Expense 2: 14800 (balance becomes 200)
    createPostedPettyCashExpense($this, '14800.0000', '2026-01-10');

    $ledger = app(ImprestLedgerService::class);
    expect($ledger->balance($float)->toString())->toBe('200.0000');

    // Attempting to reverse replenishment of 500 would reduce balance from 200 to -300!
    $reversalService = app(JournalReversalService::class);
    expect(fn () => $reversalService->request($replenishment->journalEntry, $this->period, '2026-01-20', 'Dangerous reversal', $this->maker))
        ->toThrow(ValidationException::class, 'become negative');

    // Reason in requestBlockedReason
    expect($reversalService->requestBlockedReason($replenishment->journalEntry))->toContain('become negative');
});

test('reversing an expense voucher that would cause balance to exceed float ceiling is prevented', function () {
    $float = createFundedFloat($this, '15000.0000');
    $expense = createPostedPettyCashExpense($this, '500.0000');

    // Balance is 14,500.
    // If the float ceiling is reduced to 14,000:
    app(ImprestFloatService::class)->update($float, [
        'float_amount' => '14000.0000',
        'source_financial_account_id' => $this->bank->id,
        'custodian_name' => 'Custodian',
        'is_active' => true,
    ], $this->maker);

    // Current balance = 14,500, ceiling = 14,000.
    // Reversing expense (+500) would make balance 15,000 > 14,000!
    $opService = app(OperationalTransactionService::class);
    expect(fn () => $opService->reverse($expense, $this->period, '2026-01-20', 'Over-float reversal', $this->maker))
        ->toThrow(ValidationException::class, 'exceed the authorized imprest float');
});

test('three-user journal reversal segregation is strictly enforced on replenishment reversals', function () {
    $float = createFundedFloat($this, '15000.0000');
    $expense = createPostedPettyCashExpense($this, '500.0000');
    $replenishment = replenishFloat($this, $float);

    $reversalService = app(JournalReversalService::class);

    // Requester requests reversal
    $reversal = $reversalService->request($replenishment->journalEntry, $this->period, '2026-01-20', 'Reason for reversal', $this->maker);

    // Requester cannot approve their own reversal request
    expect(fn () => $reversalService->approve($reversal->fresh(), $this->maker))
        ->toThrow(ValidationException::class);

    // Requester cannot post their own reversal
    expect(fn () => $reversalService->post($reversal->fresh(), $this->maker))
        ->toThrow(ValidationException::class);

    // Another user approves
    $reversalService->approve($reversal->fresh(), $this->checker);

    // Approver cannot post the reversal
    expect(fn () => $reversalService->post($reversal->fresh(), $this->checker))
        ->toThrow(ValidationException::class);

    // A third distinct user posts the reversal successfully
    $posted = $reversalService->post($reversal->fresh(), $this->poster);
    expect($posted->status)->toBe(JournalStatus::Posted);
});
