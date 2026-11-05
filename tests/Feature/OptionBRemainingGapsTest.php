<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\AccountType;
use App\Enums\FiscalYearStatus;
use App\Enums\PayrollRunStatus;
use App\Enums\PurchaseBillStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\AppSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\Fund;
use App\Models\PayrollComponent;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VoucherType;
use App\Services\Funds\FundBalanceSummary;
use App\Services\Funds\FundIncomeExpenditureReport;
use App\Services\JournalWorkflowService;
use App\Services\Payroll\PayrollRunService;
use App\Services\PurchaseBillService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->seed([
        AppSettingSeeder::class,
        RolePermissionSeeder::class,
        VoucherTypeSeeder::class,
    ]);

    $this->branch = Branch::factory()->create(['status' => 'publish', 'name' => 'Main Branch']);
    $this->accountant = User::factory()->create(['is_administrator' => false, 'is_active' => true]);
    $this->accountant->assignRole(SystemRole::Accountant->value);
    $this->accountant->branches()->attach($this->branch);

    $this->approver = User::factory()->create(['is_administrator' => false, 'is_active' => true]);
    $this->approver->assignRole(SystemRole::Director->value);
    $this->approver->branches()->attach($this->branch);

    $this->poster = User::factory()->create(['is_administrator' => false, 'is_active' => true]);
    $this->poster->assignRole(SystemRole::Administrator->value);
    $this->poster->branches()->attach($this->branch);

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

    $this->cashAccount = Account::factory()->create(['code' => '111101', 'name' => 'Cash in Hand', 'account_type' => AccountType::Asset, 'normal_balance' => 'debit']);
    $this->bankAccount = Account::factory()->create(['code' => '11203', 'name' => 'Bank Account', 'account_type' => AccountType::Asset, 'normal_balance' => 'debit']);
    $this->payableAccount = Account::factory()->create(['code' => '21101', 'name' => 'Accounts Payable', 'account_type' => AccountType::Liability, 'normal_balance' => 'credit']);
    $this->salaryExpenseAccount = Account::factory()->create(['code' => '51101', 'name' => 'Salaries Expense', 'account_type' => AccountType::Expense, 'normal_balance' => 'debit']);
    $this->salaryPayableAccount = Account::factory()->create(['code' => '21201', 'name' => 'Salaries Payable', 'account_type' => AccountType::Liability, 'normal_balance' => 'credit']);
    $this->taxPayableAccount = Account::factory()->create(['code' => '21301', 'name' => 'Income Tax Withheld', 'account_type' => AccountType::Liability, 'normal_balance' => 'credit']);

    $this->officeSuppliesExpense = Account::factory()->create([
        'code' => '53105',
        'name' => 'Office Supplies',
        'account_type' => AccountType::Expense,
        'normal_balance' => 'debit',
        'financial_statement' => 'income_expenditure',
        'purchase_bill_debit_eligible' => true,
    ]);
    $this->rehabSuppliesExpense = Account::factory()->create([
        'code' => '52202',
        'name' => 'Rehab Equipment',
        'account_type' => AccountType::Expense,
        'normal_balance' => 'debit',
        'financial_statement' => 'income_expenditure',
        'purchase_bill_debit_eligible' => true,
    ]);
    $this->disallowedExpenseAccount = Account::factory()->create([
        'code' => '52101',
        'name' => 'Teaching Materials',
        'account_type' => AccountType::Expense,
        'normal_balance' => 'debit',
        'financial_statement' => 'income_expenditure',
        'purchase_bill_debit_eligible' => true,
    ]);

    AccountMapping::query()->updateOrCreate(['mapping_key' => AccountMappingKey::AccountsPayable->value], ['account_id' => $this->payableAccount->id]);
    AccountMapping::query()->updateOrCreate(['mapping_key' => AccountMappingKey::SalaryExpense->value], ['account_id' => $this->salaryExpenseAccount->id]);
    AccountMapping::query()->updateOrCreate(['mapping_key' => AccountMappingKey::SalaryPayable->value], ['account_id' => $this->salaryPayableAccount->id]);

    $this->vendor = Vendor::factory()->create(['is_active' => true, 'name' => 'Al-Madina Suppliers']);

    $this->genFund = Fund::factory()->create([
        'code' => 'GEN',
        'name' => 'General Fund',
        'type' => Fund::TYPE_UNRESTRICTED,
        'category' => 'general',
        'is_active' => true,
    ]);

    $this->dnrParent = Fund::factory()->restricted('donor')->create([
        'code' => 'DNR',
        'name' => 'Donor-Restricted Fund',
        'is_active' => true,
    ]);

    $this->projectFund = Fund::factory()->restricted('project')->create([
        'parent_id' => $this->dnrParent->id,
        'code' => 'DNR-UAT1',
        'name' => 'DEMO UAT Rehabilitation Project',
        'is_active' => true,
    ]);
    $this->projectFund->permittedAccounts()->attach([$this->rehabSuppliesExpense->id]);

    // Approve payroll policy
    AppSetting::query()->where('key', 'payroll.calculation_policy_approved')->update(['value' => '1']);
});

test('purchase bill created without fund defaults to active GEN fund and posts expense to GEN while liability remains unfunded', function (): void {
    $pbService = app(PurchaseBillService::class);

    $bill = $pbService->create([
        'branch_id' => $this->branch->id,
        'vendor_id' => $this->vendor->id,
        'bill_number' => 'PB-TEST-001',
        'invoice_date' => '2026-05-15',
        'due_date' => '2026-05-30',
        'description' => 'Unrestricted Office Supplies Bill',
        // fund_id omitted
        'lines' => [
            [
                'account_id' => $this->officeSuppliesExpense->id,
                'description' => 'Printing papers and stationery',
                'quantity' => '10.0000',
                'unit_amount' => '500.0000',
            ],
        ],
    ], $this->accountant);

    expect($bill->fund_id)->toBe($this->genFund->id);
    expect($bill->lines->first()->fund_id)->toBe($this->genFund->id);
    expect($bill->total_amount)->toBe('5000.0000');

    // Submit and approve
    $pbService->submit($bill, $this->accountant);
    $bill->refresh();
    $pbService->approve($bill, $this->approver);
    $bill->refresh();

    // Post
    $postedBill = $pbService->post($bill, $this->poster);
    expect($postedBill->status)->toBe(PurchaseBillStatus::Posted);
    expect($postedBill->journal_entry_id)->not->toBeNull();

    $journal = $postedBill->journalEntry->load('lines');
    expect($journal->lines)->toHaveCount(2);

    $debitLine = $journal->lines->firstWhere('debit', '5000.0000');
    $creditLine = $journal->lines->firstWhere('credit', '5000.0000');

    expect($debitLine->account_id)->toBe($this->officeSuppliesExpense->id);
    expect($debitLine->fund_id)->toBe($this->genFund->id);

    // Accounts Payable line has null fund (liability)
    expect($creditLine->account_id)->toBe($this->payableAccount->id);
    expect($creditLine->fund_id)->toBeNull();

    // Outstanding amount is preserved
    expect($postedBill->outstandingAmount())->toBe('5000.0000');
});

test('purchase bill with explicit restricted fund preserves fund when permitted and rejects disallowed ledger', function (): void {
    $pbService = app(PurchaseBillService::class);

    // Permitted restricted fund
    $bill = $pbService->create([
        'branch_id' => $this->branch->id,
        'vendor_id' => $this->vendor->id,
        'bill_number' => 'PB-RESTRICTED-001',
        'invoice_date' => '2026-06-10',
        'fund_id' => $this->projectFund->id,
        'lines' => [
            [
                'account_id' => $this->rehabSuppliesExpense->id,
                'description' => 'Rehab therapy equipment',
                'quantity' => '2.0000',
                'unit_amount' => '4000.0000',
            ],
        ],
    ], $this->accountant);

    expect($bill->fund_id)->toBe($this->projectFund->id);
    expect($bill->lines->first()->fund_id)->toBe($this->projectFund->id);

    // Disallowed ledger with restricted fund must throw ValidationException
    expect(fn () => $pbService->create([
        'branch_id' => $this->branch->id,
        'vendor_id' => $this->vendor->id,
        'bill_number' => 'PB-DISALLOWED-001',
        'invoice_date' => '2026-06-10',
        'fund_id' => $this->projectFund->id,
        'lines' => [
            [
                'account_id' => $this->disallowedExpenseAccount->id,
                'description' => 'Teaching materials not permitted for DNR-UAT1',
                'quantity' => '1.0000',
                'unit_amount' => '1500.0000',
            ],
        ],
    ], $this->accountant))->toThrow(ValidationException::class);
});

test('newly generated payroll run defaults to GEN fund and posting attributes salary expense to GEN while payables have null fund', function (): void {
    $payrollService = app(PayrollRunService::class);

    $basicSalary = PayrollComponent::factory()->create([
        'name' => 'Basic Salary',
        'account_id' => $this->salaryExpenseAccount->id,
        'is_active' => true,
    ]);
    $incomeTax = PayrollComponent::factory()->deduction()->create([
        'name' => 'Income Tax',
        'account_id' => $this->taxPayableAccount->id,
        'is_active' => true,
    ]);

    $employee = Employee::factory()->create([
        'branch_id' => $this->branch->id,
        'employment_status' => 'active',
        'employee_code' => 'EMP-TEST-001',
    ]);
    $employee->salaryItems()->create(['payroll_component_id' => $basicSalary->id, 'amount' => '50000.0000']);
    $employee->salaryItems()->create(['payroll_component_id' => $incomeTax->id, 'amount' => '2000.0000']);

    $run = $payrollService->createFromStructures([
        'branch_id' => $this->branch->id,
        'accounting_period_id' => $this->period->id,
    ], $this->accountant);

    expect($run->fund_id)->toBe($this->genFund->id);
    expect($run->gross_amount)->toBe('50000.0000');
    expect($run->total_deductions)->toBe('2000.0000');
    expect($run->net_amount)->toBe('48000.0000');

    // Validate, submit, approve
    $payrollService->validate($run, $this->accountant);
    $run->refresh();
    $payrollService->submit($run, $this->accountant);
    $run->refresh();
    $payrollService->approve($run, $this->approver);
    $run->refresh();

    // Post
    $postedRun = $payrollService->post($run, $this->poster);
    expect($postedRun->status)->toBe(PayrollRunStatus::Posted);

    $journal = $postedRun->journalEntry->load('lines');
    expect($journal->total_debit)->toBe('50000.0000');
    expect($journal->total_credit)->toBe('50000.0000');

    $expenseLine = $journal->lines->firstWhere('account_id', $this->salaryExpenseAccount->id);
    $payableLine = $journal->lines->firstWhere('account_id', $this->salaryPayableAccount->id);
    $taxLine = $journal->lines->firstWhere('account_id', $this->taxPayableAccount->id);

    // Salary expense gets GEN fund
    expect($expenseLine->fund_id)->toBe($this->genFund->id);
    expect($expenseLine->debit)->toBe('50000.0000');

    // Balance sheet liabilities have null fund
    expect($payableLine->fund_id)->toBeNull();
    expect($payableLine->credit)->toBe('48000.0000');

    expect($taxLine->fund_id)->toBeNull();
    expect($taxLine->credit)->toBe('2000.0000');
});

test('manual journal expense debit without fund defaults to GEN while balance sheet lines retain null fund', function (): void {
    $journalService = app(JournalWorkflowService::class);
    $jv = VoucherType::query()->where('code', 'JV')->firstOrFail();

    $journal = $journalService->create([
        'voucher_type_id' => $jv->id,
        'transaction_date' => '2026-07-20',
        'branch_id' => $this->branch->id,
        'fiscal_year_id' => $this->year->id,
        'accounting_period_id' => $this->period->id,
        'narration' => 'Manual Office Expense Adjustment',
        'lines' => [
            [
                'account_id' => $this->officeSuppliesExpense->id,
                'description' => 'Office stationery adjustment',
                'debit' => '1200.0000',
                'credit' => '0.0000',
                'fund_id' => null, // Omitted, must default to GEN
            ],
            [
                'account_id' => $this->bankAccount->id,
                'description' => 'Settled from bank',
                'debit' => '0.0000',
                'credit' => '1200.0000',
                'fund_id' => null, // Asset/bank line must stay null
            ],
        ],
    ], $this->accountant);

    $journal->refresh()->load('lines');
    expect($journal->lines)->toHaveCount(2);

    $debitLine = $journal->lines->firstWhere('debit', '1200.0000');
    $creditLine = $journal->lines->firstWhere('credit', '1200.0000');

    expect($debitLine->account_id)->toBe($this->officeSuppliesExpense->id);
    expect($debitLine->fund_id)->toBe($this->genFund->id);

    expect($creditLine->account_id)->toBe($this->bankAccount->id);
    expect($creditLine->fund_id)->toBeNull();

    expect($journal->total_debit)->toBe('1200.0000');
    expect($journal->total_credit)->toBe('1200.0000');
});

test('manual journal preserves explicit restricted fund and rejects disallowed ledger', function (): void {
    $journalService = app(JournalWorkflowService::class);
    $jv = VoucherType::query()->where('code', 'JV')->firstOrFail();

    // Permitted restricted fund
    $journal = $journalService->create([
        'voucher_type_id' => $jv->id,
        'transaction_date' => '2026-07-22',
        'branch_id' => $this->branch->id,
        'fiscal_year_id' => $this->year->id,
        'accounting_period_id' => $this->period->id,
        'narration' => 'Explicit Project Expense',
        'lines' => [
            [
                'account_id' => $this->rehabSuppliesExpense->id,
                'description' => 'Rehab supplies on DNR-UAT1',
                'debit' => '3500.0000',
                'credit' => '0.0000',
                'fund_id' => $this->projectFund->id,
            ],
            [
                'account_id' => $this->cashAccount->id,
                'description' => 'Cash paid',
                'debit' => '0.0000',
                'credit' => '3500.0000',
                'fund_id' => null,
            ],
        ],
    ], $this->accountant);

    $journal->refresh()->load('lines');
    $debitLine = $journal->lines->firstWhere('debit', '3500.0000');
    expect($debitLine->fund_id)->toBe($this->projectFund->id);

    // Disallowed ledger must be rejected
    expect(fn () => $journalService->create([
        'voucher_type_id' => $jv->id,
        'transaction_date' => '2026-07-22',
        'branch_id' => $this->branch->id,
        'fiscal_year_id' => $this->year->id,
        'accounting_period_id' => $this->period->id,
        'narration' => 'Disallowed Project Expense',
        'lines' => [
            [
                'account_id' => $this->disallowedExpenseAccount->id,
                'description' => 'Disallowed ledger on DNR-UAT1',
                'debit' => '2000.0000',
                'credit' => '0.0000',
                'fund_id' => $this->projectFund->id,
            ],
            [
                'account_id' => $this->cashAccount->id,
                'description' => 'Cash paid',
                'debit' => '0.0000',
                'credit' => '2000.0000',
                'fund_id' => null,
            ],
        ],
    ], $this->accountant))->toThrow(ValidationException::class);
});

test('fund balance summary and income expenditure reports reflect PB and payroll attribution under GEN', function (): void {
    $pbService = app(PurchaseBillService::class);
    $bill = $pbService->create([
        'branch_id' => $this->branch->id,
        'vendor_id' => $this->vendor->id,
        'bill_number' => 'PB-REP-001',
        'invoice_date' => '2026-08-10',
        'lines' => [
            [
                'account_id' => $this->officeSuppliesExpense->id,
                'description' => 'Stationery',
                'quantity' => '1.0000',
                'unit_amount' => '3000.0000',
            ],
        ],
    ], $this->accountant);

    $pbService->submit($bill, $this->accountant);
    $bill->refresh();
    $pbService->approve($bill, $this->approver);
    $bill->refresh();
    $pbService->post($bill, $this->poster);

    $summaryReport = app(FundBalanceSummary::class);
    $report = $summaryReport->build($this->poster, [
        'branch_id' => $this->branch->id,
        'fiscal_year_id' => $this->year->id,
    ]);

    $genRow = collect($report['rows'])->firstWhere('fund.code', 'GEN');
    expect($genRow)->not->toBeNull();
    expect((float) $genRow['period_expenditure'])->toBeGreaterThanOrEqual(3000.0);

    $ineReport = app(FundIncomeExpenditureReport::class);
    $ieData = $ineReport->build($this->poster, [
        'branch_id' => $this->branch->id,
        'fiscal_year_id' => $this->year->id,
        'fund_id' => $this->genFund->id,
    ]);
    expect((float) ($ieData['total_expenses']['fund_'.$this->genFund->id] ?? 0))->toBeGreaterThanOrEqual(3000.0);
});
