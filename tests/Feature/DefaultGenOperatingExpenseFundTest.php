<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\AccountType;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\Fund;
use App\Models\ImprestFloat;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\Funds\FundBalanceSummary;
use App\Services\Funds\FundIncomeExpenditureReport;
use App\Services\OperationalTransactionService;
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

    $this->admin = User::factory()->create(['is_administrator' => true, 'is_active' => true]);
    $this->admin->assignRole(SystemRole::Administrator->value);
    $this->admin->branches()->attach($this->branch);

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
    $this->generalExpenseAccount = Account::factory()->create([
        'code' => '53102',
        'name' => 'Electricity Expense',
        'account_type' => AccountType::Expense,
        'normal_balance' => 'debit',
        'direct_payment_eligible' => true,
    ]);
    $this->projectExpenseAccount = Account::factory()->create([
        'code' => '52201',
        'name' => 'Therapy Materials',
        'account_type' => AccountType::Expense,
        'normal_balance' => 'debit',
        'direct_payment_eligible' => true,
    ]);
    $this->disallowedExpenseAccount = Account::factory()->create([
        'code' => '52101',
        'name' => 'Teaching Materials',
        'account_type' => AccountType::Expense,
        'normal_balance' => 'debit',
        'direct_payment_eligible' => true,
    ]);

    $this->bankFinancialAccount = FinancialAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'account_id' => $this->bankAccount->id,
        'type' => 'bank',
        'is_active' => true,
    ]);

    $this->cashFinancialAccount = FinancialAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'account_id' => $this->cashAccount->id,
        'type' => 'cash',
        'is_active' => true,
    ]);

    $this->imprestFloat = ImprestFloat::factory()->create([
        'branch_id' => $this->branch->id,
        'account_id' => $this->cashAccount->id,
        'source_financial_account_id' => $this->bankFinancialAccount->id,
        'float_amount' => '25000.0000',
        'is_active' => true,
    ]);

    // Fund the imprest float with initial cash
    $openingJv = VoucherType::query()->where('code', 'JV')->firstOrFail();
    $openingJournal = JournalEntry::query()->create([
        'voucher_type_id' => $openingJv->id,
        'transaction_date' => '2026-01-01',
        'fiscal_year_id' => $this->year->id,
        'accounting_period_id' => $this->period->id,
        'branch_id' => $this->branch->id,
        'narration' => 'Initial float funding',
        'status' => JournalStatus::Draft,
        'created_by' => $this->admin->id,
        'total_debit' => '25000.0000',
        'total_credit' => '25000.0000',
    ]);
    $openingJournal->lines()->create(['line_number' => 1, 'account_id' => $this->cashAccount->id, 'debit' => '25000.0000', 'credit' => '0.0000', 'description' => 'Float debit']);
    $openingJournal->lines()->create(['line_number' => 2, 'account_id' => $this->bankAccount->id, 'debit' => '0.0000', 'credit' => '25000.0000', 'description' => 'Bank credit']);
    $openingJournal->update(['status' => JournalStatus::Posted]);

    AccountMapping::query()->updateOrCreate(['mapping_key' => AccountMappingKey::AccountsPayable->value], ['account_id' => $this->payableAccount->id]);

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

    $this->dnrUat1 = Fund::factory()->restricted('donor')->create([
        'code' => 'DNR-UAT1',
        'name' => 'DEMO UAT Rehabilitation Project',
        'parent_id' => $this->dnrParent->id,
        'is_active' => true,
    ]);

    // Permit only projectExpenseAccount (52201) on DNR-UAT1
    $this->dnrUat1->permittedAccounts()->attach($this->projectExpenseAccount);

    $this->service = app(OperationalTransactionService::class);
    $this->balanceSummary = app(FundBalanceSummary::class);
    $this->incomeExpenditure = app(FundIncomeExpenditureReport::class);
});

test('new unrestricted operating expense with no fund defaults to GEN on service creation', function (): void {
    $expense = $this->service->create([
        'type' => OperationalTransactionType::Expense->value,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-02-01',
        'amount' => '1500.0000',
        'contra_account_id' => $this->generalExpenseAccount->id,
        'payable_account_id' => $this->payableAccount->id,
        'settlement_type' => 'accrued',
        'narration' => 'Unrestricted operating expense without fund',
    ], $this->accountant);

    expect($expense->fund_id)->toBe($this->genFund->id);
});

test('new petty cash expense with no fund defaults to GEN', function (): void {
    $expense = $this->service->create([
        'type' => OperationalTransactionType::PettyCash->value,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-02-01',
        'amount' => '350.0000',
        'contra_account_id' => $this->generalExpenseAccount->id,
        'financial_account_id' => $this->cashFinancialAccount->id,
        'narration' => 'Unrestricted petty cash expense without fund',
    ], $this->accountant);

    expect($expense->fund_id)->toBe($this->genFund->id);
});

test('new unrestricted operating expense via HTTP store with empty fund_id defaults to GEN', function (): void {
    $response = $this->actingAs($this->accountant)->post(route('transactions.store'), [
        'type' => OperationalTransactionType::Expense->value,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-02-01',
        'amount' => '2500.0000',
        'contra_account_id' => $this->generalExpenseAccount->id,
        'payable_account_id' => $this->payableAccount->id,
        'settlement_type' => 'accrued',
        'fund_id' => '',
        'narration' => 'HTTP store general expense with empty fund',
    ]);

    $response->assertSessionHasNoErrors();
    $expense = OperationalTransaction::query()->latest('id')->first();
    expect($expense)->not->toBeNull()
        ->and($expense->fund_id)->toBe($this->genFund->id);
});

test('explicit restricted fund selection remains unchanged and is not replaced by GEN', function (): void {
    $expense = $this->service->create([
        'type' => OperationalTransactionType::Expense->value,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-02-01',
        'amount' => '500.0000',
        'contra_account_id' => $this->projectExpenseAccount->id,
        'payable_account_id' => $this->payableAccount->id,
        'settlement_type' => 'accrued',
        'fund_id' => $this->dnrUat1->id,
        'narration' => 'Project expense explicitly for DNR-UAT1',
    ], $this->accountant);

    expect($expense->fund_id)->toBe($this->dnrUat1->id)
        ->and($expense->fund_id)->not->toBe($this->genFund->id);
});

test('restricted fund with disallowed GL is rejected by validation', function (): void {
    expect(function (): void {
        $this->service->create([
            'type' => OperationalTransactionType::Expense->value,
            'branch_id' => $this->branch->id,
            'transaction_date' => '2026-02-01',
            'amount' => '600.0000',
            'contra_account_id' => $this->disallowedExpenseAccount->id,
            'payable_account_id' => $this->payableAccount->id,
            'settlement_type' => 'accrued',
            'fund_id' => $this->dnrUat1->id,
            'narration' => 'Project expense with disallowed GL',
        ], $this->accountant);
    })->toThrow(ValidationException::class);
});

test('general expense posted to GEN appears in GEN Fund-wise reports and does not inflate No Fund', function (): void {
    // 1. Create simulated historical No Fund entry (PKR 16,000)
    $jv = VoucherType::query()->where('code', 'JV')->firstOrFail();
    $historicalJournal = JournalEntry::query()->create([
        'voucher_type_id' => $jv->id,
        'transaction_date' => '2026-01-10',
        'fiscal_year_id' => $this->year->id,
        'accounting_period_id' => $this->period->id,
        'branch_id' => $this->branch->id,
        'narration' => 'Historical expense prior to Option B',
        'status' => JournalStatus::Draft,
        'created_by' => $this->admin->id,
        'total_debit' => '16000.0000',
        'total_credit' => '16000.0000',
    ]);
    $historicalJournal->lines()->create(['line_number' => 1, 'account_id' => $this->generalExpenseAccount->id, 'fund_id' => null, 'debit' => '16000.0000', 'credit' => '0.0000', 'description' => 'Historical line']);
    $historicalJournal->lines()->create(['line_number' => 2, 'account_id' => $this->payableAccount->id, 'fund_id' => null, 'debit' => '0.0000', 'credit' => '16000.0000', 'description' => 'Historical line']);
    $historicalJournal->update(['status' => JournalStatus::Posted]);

    // Check baseline summary before new expense
    $summaryBefore = $this->balanceSummary->build($this->admin, [
        'branch_id' => $this->branch->id,
        'fiscal_year_id' => $this->year->id,
    ]);
    $noFundRowBefore = collect($summaryBefore['rows'])->firstWhere('label', 'No fund');
    expect($noFundRowBefore['cumulative_expenditure'])->toBe('16000.0000');

    // 2. Post new unrestricted expense with Option B (PKR 4,000)
    $expense = $this->service->create([
        'type' => OperationalTransactionType::Expense->value,
        'branch_id' => $this->branch->id,
        'transaction_date' => '2026-02-15',
        'amount' => '4000.0000',
        'contra_account_id' => $this->generalExpenseAccount->id,
        'payable_account_id' => $this->payableAccount->id,
        'settlement_type' => 'accrued',
        'narration' => 'New operating expense under Option B',
    ], $this->accountant);

    $expense->update(['status' => OperationalTransactionStatus::Approved]);
    $this->service->post($expense, $this->admin);

    expect($expense->fresh()->journal_entry_id)->not->toBeNull();
    $postedJournal = $expense->fresh()->journalEntry;
    expect($postedJournal->lines->firstWhere('account_id', $this->generalExpenseAccount->id)?->fund_id)->toBe($this->genFund->id)
        ->and($postedJournal->lines->firstWhere('account_id', $this->payableAccount->id)?->fund_id)->toBeNull();

    // 3. Verify Fund Balance Summary:
    // GEN expenditure is 4,000.0000
    // Historical No fund expenditure remains EXACTLY 16,000.0000 (unchanged)
    $summaryAfter = $this->balanceSummary->build($this->admin, [
        'branch_id' => $this->branch->id,
        'fiscal_year_id' => $this->year->id,
    ]);

    $genRow = collect($summaryAfter['rows'])->firstWhere('label', $this->genFund->label());
    $noFundRowAfter = collect($summaryAfter['rows'])->firstWhere('label', 'No fund');

    expect($genRow['cumulative_expenditure'])->toBe('4000.0000')
        ->and($noFundRowAfter['cumulative_expenditure'])->toBe('16000.0000');
});

test('creation forms preselect GEN for expenses and petty cash in HTML', function (): void {
    // 1. Expense create form
    $resExpense = $this->actingAs($this->accountant)->get(route('transactions.create', 'expense'));
    $resExpense->assertOk();
    $contentExpense = $resExpense->getContent();
    // HTML has option for GEN selected
    expect($contentExpense)->toContain('value="'.$this->genFund->id.'" selected');

    // 2. Petty cash create form
    $resPetty = $this->actingAs($this->accountant)->get(route('transactions.create', 'petty_cash'));
    $resPetty->assertOk();
    $contentPetty = $resPetty->getContent();
    expect($contentPetty)->toContain('value="'.$this->genFund->id.'" selected');
});
