<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountType;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\Fund;
use App\Models\JournalLine;
use App\Models\PayableObligation;
use App\Models\User;
use App\Services\Funds\FundBalanceSummary;
use App\Services\Funds\GrantUtilisationReport;
use App\Services\OperationalTransactionService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;

beforeEach(function (): void {
    $this->seed([
        AppSettingSeeder::class,
        RolePermissionSeeder::class,
        VoucherTypeSeeder::class,
    ]);

    $this->branch = Branch::factory()->create(['status' => 'publish', 'name' => 'Main Branch']);
    $this->maker = User::factory()->create(['is_administrator' => false, 'is_active' => true]);
    $this->maker->assignRole(SystemRole::Accountant->value);
    $this->maker->branches()->attach($this->branch);

    $this->approver = User::factory()->create(['is_administrator' => false, 'is_active' => true]);
    $this->approver->assignRole(SystemRole::Administrator->value);
    $this->approver->branches()->attach($this->branch);

    $this->year = FiscalYear::factory()->create([
        'name' => 'FY 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => FiscalYearStatus::Open,
        'is_current' => true,
    ]);

    $this->period = AccountingPeriod::factory()->create([
        'fiscal_year_id' => $this->year,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => AccountingPeriodStatus::Open,
    ]);

    $this->cashLedger = Account::factory()->create([
        'code' => '11101',
        'name' => 'Cash in Hand',
        'account_type' => AccountType::Asset,
        'level' => 'ledger',
        'financial_statement' => 'balance_sheet',
    ]);
    $this->cash = FinancialAccount::factory()->create([
        'type' => 'cash',
        'branch_id' => $this->branch->id,
        'account_id' => $this->cashLedger->id,
        'is_active' => true,
    ]);

    $this->therapyExpense = Account::factory()->create([
        'code' => '52201',
        'name' => 'Therapy Materials & Consumables',
        'account_type' => AccountType::Expense,
        'level' => 'ledger',
        'financial_statement' => 'income_expenditure',
        'is_postable' => true,
        'is_active' => true,
        'direct_payment_eligible' => true,
    ]);

    $this->payableLedger = Account::factory()->create([
        'code' => '21101',
        'name' => 'Trade Creditors / Suppliers',
        'account_type' => AccountType::Liability,
        'level' => 'ledger',
        'financial_statement' => 'balance_sheet',
        'normal_balance' => 'credit',
        'is_postable' => true,
        'is_active' => true,
    ]);

    $this->grantFund = Fund::factory()->create([
        'code' => 'GRT-UAT1',
        'name' => 'DEMO UAT Rehabilitation Grant',
        'type' => Fund::TYPE_RESTRICTED,
        'category' => Fund::CATEGORY_GRANT,
        'grant_amount' => '10000.0000',
        'is_active' => true,
    ]);
    $this->grantFund->permittedAccounts()->attach($this->therapyExpense);

    $this->service = app(OperationalTransactionService::class);
});

test('posted accrued grant expense sets restricted fund on debit expense and NULL on credit liability', function (): void {
    $expense = $this->service->create([
        'type' => OperationalTransactionType::Expense->value,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '1000.0000',
        'contra_account_id' => $this->therapyExpense->id,
        'payable_account_id' => $this->payableLedger->id,
        'settlement_type' => 'accrued',
        'fund_id' => $this->grantFund->id,
        'narration' => 'Therapy materials accrued for GRT-UAT1',
    ], $this->maker);

    $this->service->submit($expense, $this->maker);
    $this->service->approve($expense->fresh(), $this->approver);
    $this->service->post($expense->fresh(), $this->approver);

    $journal = $expense->fresh()->journalEntry;
    expect($journal)->not->toBeNull();

    $expenseLine = $journal->lines->firstWhere('account_id', $this->therapyExpense->id);
    $payableLine = $journal->lines->firstWhere('account_id', $this->payableLedger->id);

    // Debit expense line carries restricted grant fund
    expect($expenseLine)->not->toBeNull()
        ->and($expenseLine->fund_id)->toBe($this->grantFund->id)
        ->and($expenseLine->debit)->toBe('1000.0000')
        ->and($expenseLine->credit)->toBe('0.0000');

    // Credit payable liability line MUST be NULL (organizational liability, consistent with Purchase Bills & Payroll)
    expect($payableLine)->not->toBeNull()
        ->and($payableLine->fund_id)->toBeNull()
        ->and($payableLine->debit)->toBe('0.0000')
        ->and($payableLine->credit)->toBe('1000.0000');
});

test('accrued grant expense payable settlement clears liability under NULL fund without residual balance on grant', function (): void {
    // 1. Post accrued grant expense (PKR 1,000)
    $expense = $this->service->create([
        'type' => OperationalTransactionType::Expense->value,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'amount' => '1000.0000',
        'contra_account_id' => $this->therapyExpense->id,
        'payable_account_id' => $this->payableLedger->id,
        'settlement_type' => 'accrued',
        'fund_id' => $this->grantFund->id,
        'narration' => 'Therapy materials accrued for GRT-UAT1',
    ], $this->maker);
    $this->service->submit($expense, $this->maker);
    $this->service->approve($expense->fresh(), $this->approver);
    $this->service->post($expense->fresh(), $this->approver);

    $obligation = PayableObligation::query()->where('source_expense_transaction_id', $expense->id)->firstOrFail();

    // 2. Settle the obligation with a cash payment
    $payment = $this->service->create([
        'type' => OperationalTransactionType::CashPayment->value,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-20',
        'party_name' => 'Supplier Settlement',
        'amount' => '1000.0000',
        'financial_account_id' => $this->cash->id,
        'contra_account_id' => $this->payableLedger->id,
        'payable_obligation_id' => $obligation->id,
        'settlement_request_token' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
        'narration' => 'Settlement of accrued payable for therapy materials',
    ], $this->maker);
    $this->service->submit($payment, $this->maker);
    $this->service->approve($payment->fresh(), $this->approver);
    $this->service->disburse($payment->fresh(), $this->approver);
    $this->service->post($payment->fresh(), $this->approver);

    $settlementJournal = $payment->fresh()->journalEntry;
    expect($settlementJournal)->not->toBeNull();

    $settlePayableLine = $settlementJournal->lines->firstWhere('account_id', $this->payableLedger->id);
    expect($settlePayableLine->fund_id)->toBeNull()
        ->and($settlePayableLine->debit)->toBe('1000.0000');

    // Verify fund-wise balance on Trade Creditors / Suppliers (21101):
    // Under fund_id = NULL: Credit 1000 (from expense) - Debit 1000 (from payment) = Net 0
    $nullCredits = JournalLine::query()->where('account_id', $this->payableLedger->id)->whereNull('fund_id')->sum('credit');
    $nullDebits = JournalLine::query()->where('account_id', $this->payableLedger->id)->whereNull('fund_id')->sum('debit');
    expect((float) $nullCredits)->toBe(1000.0)
        ->and((float) $nullDebits)->toBe(1000.0);

    // Under grant fund: NO journal lines exist on the liability ledger
    $grantLiabilityLines = JournalLine::query()->where('account_id', $this->payableLedger->id)->where('fund_id', $this->grantFund->id)->count();
    expect($grantLiabilityLines)->toBe(0);

    // Grant report still correctly shows 1,000 expenditure and 10% utilisation
    $grantReport = app(GrantUtilisationReport::class)->donorReport($this->approver, $this->grantFund->fresh(), ['branch_id' => $this->branch->id]);
    expect($grantReport['expenditure'])->toBe('1000.0000')
        ->and($grantReport['utilisation'])->toBe('10.00');

    // Fund balance summary shows exactly 1,000 expenditure under GRT-UAT1
    $summary = app(FundBalanceSummary::class)->build($this->approver, ['branch_id' => $this->branch->id, 'fiscal_year_id' => $this->year->id]);
    $grantRow = collect($summary['rows'])->firstWhere('label', $this->grantFund->label());
    expect($grantRow['cumulative_expenditure'])->toBe('1000.0000');
});

test('direct cash payment charged to restricted fund retains fund on cash asset credit line', function (): void {
    $payment = $this->service->create([
        'type' => OperationalTransactionType::CashPayment->value,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Direct Clinic Supplier',
        'amount' => '500.0000',
        'financial_account_id' => $this->cash->id,
        'contra_account_id' => $this->therapyExpense->id,
        'fund_id' => $this->grantFund->id,
        'narration' => 'Direct therapy materials payment from grant cash',
    ], $this->maker);

    $this->service->submit($payment, $this->maker);
    $this->service->approve($payment->fresh(), $this->approver);
    $this->service->disburse($payment->fresh(), $this->approver);
    $this->service->post($payment->fresh(), $this->approver);

    $journal = $payment->fresh()->journalEntry;
    expect($journal)->not->toBeNull();

    $expenseLine = $journal->lines->firstWhere('account_id', $this->therapyExpense->id);
    $cashLine = $journal->lines->firstWhere('account_id', $this->cashLedger->id);

    // Debit expense line carries restricted fund
    expect($expenseLine->fund_id)->toBe($this->grantFund->id);
    // Credit cash line retains fund (asset disbursement from fund)
    expect($cashLine->fund_id)->toBe($this->grantFund->id);
});
