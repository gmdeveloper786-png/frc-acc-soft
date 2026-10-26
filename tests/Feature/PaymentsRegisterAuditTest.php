<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\OperationalTransaction;
use App\Models\Payee;
use App\Models\PayeeType;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branchA = Branch::factory()->create(['status' => 'publish', 'name' => 'Alpha Branch']);
    $this->branchB = Branch::factory()->create(['status' => 'publish', 'name' => 'Beta Branch']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);

    $this->expenseAccount = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'direct_payment_eligible' => true]);
    $this->payableAccount = Account::factory()->create(['account_type' => 'liability', 'financial_statement' => 'balance_sheet']);

    $this->cashA = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branchA, 'account_id' => Account::factory()]);
    $this->bankA = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branchA, 'account_id' => Account::factory()]);
    $this->pettyA = fundedPettyCashAccount($this->branchA->id, '2000.0000');

    $this->vendor = Vendor::factory()->create(['name' => 'General Vendor A', 'is_active' => true]);
    $payeeType = PayeeType::query()->create(['name' => 'Supplier', 'code' => 'supplier_test', 'source_kind' => 'directory', 'is_active' => true]);
    $this->payee = ['payee_type_id' => $payeeType->id, 'payee_id' => Payee::query()->create(['payee_type_id' => $payeeType->id, 'name' => 'Alpha Supplier', 'entity_kind' => 'organization', 'is_active' => true])->id];

    $this->createPostedJournal = function (string $voucher, string $amount, string $sourceType, int $sourceId) {
        return DB::table('journal_entries')->insertGetId([
            'voucher_type_id' => 1,
            'voucher_number' => $voucher,
            'branch_id' => $this->branchA->id,
            'transaction_date' => '2026-01-15',
            'fiscal_year_id' => $this->period->fiscal_year_id,
            'accounting_period_id' => $this->period->id,
            'narration' => 'Posted journal fixture for '.$voucher,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'status' => JournalStatus::Posted->value,
            'total_debit' => $amount,
            'total_credit' => $amount,
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };
});

test('formal payments register displays posted bank, cash, vendor, petty cash, and legacy paid expenses but strictly excludes accrued, draft and reversed records', function () {
    $this->actingAs($this->admin);

    // 1. Posted Bank Payment
    $bankPayment = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::BankPayment,
        'status' => OperationalTransactionStatus::Posted,
        'branch_id' => $this->branchA->id,
        'amount' => '500.0000',
        'financial_account_id' => $this->bankA->id,
        'contra_account_id' => $this->expenseAccount->id,
        'party_name' => 'Bank Beneficiary Ltd',
        'reference_number' => 'BP-POSTED-1',
        'transaction_date' => '2026-01-10',
    ]);
    $j1 = ($this->createPostedJournal)('BP-2026-0001', '500.0000', OperationalTransactionType::BankPayment->value, $bankPayment->id);
    $bankPayment->update(['journal_entry_id' => $j1]);

    // 2. Posted Cash Payment
    $cashPayment = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::CashPayment,
        'status' => OperationalTransactionStatus::Posted,
        'branch_id' => $this->branchA->id,
        'amount' => '300.0000',
        'financial_account_id' => $this->cashA->id,
        'contra_account_id' => $this->expenseAccount->id,
        'party_name' => 'Cash Courier Services',
        'reference_number' => 'CP-POSTED-1',
        'transaction_date' => '2026-01-11',
    ]);
    $j2 = ($this->createPostedJournal)('CP-2026-0001', '300.0000', OperationalTransactionType::CashPayment->value, $cashPayment->id);
    $cashPayment->update(['journal_entry_id' => $j2]);

    // 3. Posted Vendor Payment
    $vendorPayment = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::VendorPayment,
        'status' => OperationalTransactionStatus::Posted,
        'branch_id' => $this->branchA->id,
        'amount' => '750.0000',
        'financial_account_id' => $this->bankA->id,
        'payable_account_id' => $this->payableAccount->id,
        'vendor_id' => $this->vendor->id,
        'reference_number' => 'VP-POSTED-1',
        'transaction_date' => '2026-01-12',
    ]);
    $j3 = ($this->createPostedJournal)('BP-2026-0002', '750.0000', OperationalTransactionType::VendorPayment->value, $vendorPayment->id);
    $vendorPayment->update(['journal_entry_id' => $j3]);

    // 4. Posted Petty Cash Voucher
    $pettyPayment = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::PettyCash,
        'status' => OperationalTransactionStatus::Posted,
        'branch_id' => $this->branchA->id,
        'amount' => '80.0000',
        'financial_account_id' => $this->pettyA->id,
        'contra_account_id' => $this->expenseAccount->id,
        'party_name' => 'Petty Office Refreshments',
        'reference_number' => 'PC-POSTED-1',
        'transaction_date' => '2026-01-13',
    ]);
    $j4 = ($this->createPostedJournal)('CP-2026-0002', '80.0000', OperationalTransactionType::PettyCash->value, $pettyPayment->id);
    $pettyPayment->update(['journal_entry_id' => $j4]);

    // 5. Posted Legacy Paid Expense
    $legacyPaid = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Expense,
        'settlement_type' => 'paid',
        'status' => OperationalTransactionStatus::Posted,
        'branch_id' => $this->branchA->id,
        'amount' => '150.0000',
        'financial_account_id' => $this->cashA->id,
        'contra_account_id' => $this->expenseAccount->id,
        'party_name' => 'Legacy Hardware Store',
        'reference_number' => 'EXP-LEGACY-PAID-1',
        'transaction_date' => '2026-01-14',
    ]);
    $j5 = ($this->createPostedJournal)('JV-2026-0001', '150.0000', OperationalTransactionType::Expense->value, $legacyPaid->id);
    $legacyPaid->update(['journal_entry_id' => $j5]);

    // 6. Posted Accrued Expense (MUST NOT appear in payments register)
    $accrued = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Expense,
        'settlement_type' => 'accrued',
        'status' => OperationalTransactionStatus::Posted,
        'branch_id' => $this->branchA->id,
        'amount' => '9999.0000',
        'financial_account_id' => null,
        'contra_account_id' => $this->expenseAccount->id,
        'party_name' => 'Accrued Utility Liability',
        'reference_number' => 'EXP-ACCRUED-EXCLUDE',
        'transaction_date' => '2026-01-15',
    ]);
    $j6 = ($this->createPostedJournal)('JV-2026-0002', '9999.0000', OperationalTransactionType::Expense->value, $accrued->id);
    $accrued->update(['journal_entry_id' => $j6]);

    // 7. Draft Payment (MUST NOT appear in posted register)
    $draftPayment = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::BankPayment,
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branchA->id,
        'amount' => '400.0000',
        'financial_account_id' => $this->bankA->id,
        'party_name' => 'Draft Payment Beneficiary',
        'reference_number' => 'BP-DRAFT-EXCLUDE',
        'transaction_date' => '2026-01-16',
    ]);

    // 8. Reversed Payment (MUST NOT appear in posted register)
    $reversedPayment = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::CashPayment,
        'status' => OperationalTransactionStatus::Reversed,
        'branch_id' => $this->branchA->id,
        'amount' => '250.0000',
        'financial_account_id' => $this->cashA->id,
        'party_name' => 'Reversed Cash Beneficiary',
        'reference_number' => 'CP-REVERSED-EXCLUDE',
        'transaction_date' => '2026-01-17',
    ]);

    // Execute GET on Formal Payments Register
    $response = $this->get(route('reports.registers', ['kind' => 'payments']))->assertOk();
    $html = $response->getContent();

    // Verify posted payments are present
    expect($html)->toContain('BP-2026-0001')
        ->and($html)->toContain('Bank Beneficiary Ltd')
        ->and($html)->toContain('CP-2026-0001')
        ->and($html)->toContain('Cash Courier Services')
        ->and($html)->toContain('BP-2026-0002')
        ->and($html)->toContain('General Vendor A')
        ->and($html)->toContain('CP-2026-0002')
        ->and($html)->toContain('Petty Office Refreshments')
        ->and($html)->toContain('JV-2026-0001')
        ->and($html)->toContain('Legacy Hardware Store')
        ->and($html)->toContain('Paid Expense (Legacy)');

    // Verify exclusions: Accrued expense, draft, reversed
    expect($html)->not->toContain('EXP-ACCRUED-EXCLUDE')
        ->and($html)->not->toContain('Accrued Utility Liability')
        ->and($html)->not->toContain('JV-2026-0002')
        ->and($html)->not->toContain('BP-DRAFT-EXCLUDE')
        ->and($html)->not->toContain('Draft Payment Beneficiary')
        ->and($html)->not->toContain('CP-REVERSED-EXCLUDE')
        ->and($html)->not->toContain('Reversed Cash Beneficiary');
});

test('formal payments register export agrees with screen report and includes legacy paid expenses while excluding accrued/draft/reversed', function () {
    $this->actingAs($this->admin);

    $bankPayment = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::BankPayment,
        'status' => OperationalTransactionStatus::Posted,
        'branch_id' => $this->branchA->id,
        'amount' => '500.0000',
        'financial_account_id' => $this->bankA->id,
        'party_name' => 'Export Bank Party',
        'reference_number' => 'BP-EXP-1',
        'transaction_date' => '2026-01-10',
    ]);
    $j1 = ($this->createPostedJournal)('BP-EXP-001', '500.0000', OperationalTransactionType::BankPayment->value, $bankPayment->id);
    $bankPayment->update(['journal_entry_id' => $j1]);

    $legacyPaid = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Expense,
        'settlement_type' => 'paid',
        'status' => OperationalTransactionStatus::Posted,
        'branch_id' => $this->branchA->id,
        'amount' => '175.0000',
        'financial_account_id' => $this->cashA->id,
        'party_name' => 'Export Legacy Paid Expense',
        'reference_number' => 'EXP-EXP-1',
        'transaction_date' => '2026-01-14',
    ]);
    $j2 = ($this->createPostedJournal)('JV-EXP-001', '175.0000', OperationalTransactionType::Expense->value, $legacyPaid->id);
    $legacyPaid->update(['journal_entry_id' => $j2]);

    $accrued = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Expense,
        'settlement_type' => 'accrued',
        'status' => OperationalTransactionStatus::Posted,
        'branch_id' => $this->branchA->id,
        'amount' => '6000.0000',
        'financial_account_id' => null,
        'party_name' => 'Accrued Not In Export',
        'reference_number' => 'EXP-ACCRUED-EXP-1',
        'transaction_date' => '2026-01-15',
    ]);

    $response = $this->get(route('reports.registers.export', ['kind' => 'payments', 'format' => 'csv']))
        ->assertOk();
    $export = $response->streamedContent();

    expect($export)->toContain('BP-EXP-001')
        ->and($export)->toContain('Export Bank Party')
        ->and($export)->toContain('JV-EXP-001')
        ->and($export)->toContain('Export Legacy Paid Expense')
        ->and($export)->toContain('Paid Expense (Legacy)')
        ->and($export)->not->toContain('Accrued Not In Export')
        ->and($export)->not->toContain('EXP-ACCRUED-EXP-1');
});

test('least-privilege permissions gate voucher links and protect direct endpoints', function () {
    // 1. Create a posted legacy paid expense and bank payment
    $bankPayment = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::BankPayment,
        'status' => OperationalTransactionStatus::Posted,
        'branch_id' => $this->branchA->id,
        'amount' => '500.0000',
        'financial_account_id' => $this->bankA->id,
        'party_name' => 'Bank Recipient',
        'reference_number' => 'BP-PERM-1',
        'transaction_date' => '2026-01-10',
    ]);
    $j1 = ($this->createPostedJournal)('BP-PERM-01', '500.0000', OperationalTransactionType::BankPayment->value, $bankPayment->id);
    $bankPayment->update(['journal_entry_id' => $j1]);

    $legacyPaid = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Expense,
        'settlement_type' => 'paid',
        'status' => OperationalTransactionStatus::Posted,
        'branch_id' => $this->branchA->id,
        'amount' => '200.0000',
        'financial_account_id' => $this->cashA->id,
        'party_name' => 'Legacy Paid Recipient',
        'reference_number' => 'EXP-PERM-1',
        'transaction_date' => '2026-01-14',
    ]);
    $j2 = ($this->createPostedJournal)('JV-PERM-01', '200.0000', OperationalTransactionType::Expense->value, $legacyPaid->id);
    $legacyPaid->update(['journal_entry_id' => $j2]);

    // 2. User has finance_reports.view and payments.view, but NOT expenses.view
    $paymentOnlyUser = User::factory()->create();
    $paymentOnlyUser->givePermissionTo(['finance_reports.view', 'payments.view']);
    $paymentOnlyUser->branches()->attach($this->branchA);

    $this->actingAs($paymentOnlyUser);

    $html = $this->get(route('reports.registers', ['kind' => 'payments']))->assertOk()->getContent();

    // Bank payment link is clickable
    expect($html)->toContain('href="'.route('transactions.show', $bankPayment).'"');
    // Legacy paid expense voucher is rendered as plain text (no 403 link)
    expect($html)->toContain('<span>JV-PERM-01</span>')
        ->and($html)->not->toContain('href="'.route('transactions.show', $legacyPaid).'"');

    // Direct endpoint check: payments user visiting expense show is 403 Forbidden
    $this->get(route('transactions.show', $legacyPaid))->assertForbidden();
    // Direct endpoint check: payments user visiting bank payment show is 200 OK
    $this->get(route('transactions.show', $bankPayment))->assertOk();

    // 3. User with expenses.view but NOT payments.view
    $expenseOnlyUser = User::factory()->create();
    $expenseOnlyUser->givePermissionTo(['finance_reports.view', 'expenses.view']);
    $expenseOnlyUser->branches()->attach($this->branchA);

    $this->actingAs($expenseOnlyUser);
    $htmlExp = $this->get(route('reports.registers', ['kind' => 'payments']))->assertOk()->getContent();

    // Expense voucher is clickable
    expect($htmlExp)->toContain('href="'.route('transactions.show', $legacyPaid).'"');
    // Bank payment voucher is plain text
    expect($htmlExp)->toContain('<span>BP-PERM-01</span>')
        ->and($htmlExp)->not->toContain('href="'.route('transactions.show', $bankPayment).'"');

    // Direct endpoint check
    $this->get(route('transactions.show', $legacyPaid))->assertOk();
    $this->get(route('transactions.show', $bankPayment))->assertForbidden();
});

test('branch isolation is strictly enforced on payments register and detail endpoints', function () {
    // Transaction on Branch A
    $txnBranchA = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::BankPayment,
        'status' => OperationalTransactionStatus::Posted,
        'branch_id' => $this->branchA->id,
        'amount' => '850.0000',
        'financial_account_id' => $this->bankA->id,
        'party_name' => 'Alpha Specific Supplier',
        'reference_number' => 'BP-BRANCH-A',
        'transaction_date' => '2026-01-10',
    ]);
    $j1 = ($this->createPostedJournal)('BP-BR-A-01', '850.0000', OperationalTransactionType::BankPayment->value, $txnBranchA->id);
    $txnBranchA->update(['journal_entry_id' => $j1]);

    // User assigned ONLY to Branch B
    $userB = User::factory()->create();
    $userB->givePermissionTo(['finance_reports.view', 'payments.view']);
    $userB->branches()->attach($this->branchB);

    $this->actingAs($userB);

    // Register query for Branch B user cannot see Branch A transactions
    $html = $this->get(route('reports.registers', ['kind' => 'payments']))->assertOk()->getContent();
    expect($html)->not->toContain('Alpha Specific Supplier')
        ->and($html)->not->toContain('BP-BR-A-01');

    // Direct endpoint to Branch A transaction is 403 Forbidden
    $this->get(route('transactions.show', $txnBranchA))->assertForbidden();
});

test('expense-only user retains accrued expense creation and cannot access unified payments creation', function () {
    $expenseClerk = User::factory()->create();
    $expenseClerk->givePermissionTo(['expenses.create', 'expenses.view']);
    $expenseClerk->branches()->attach($this->branchA);

    $this->actingAs($expenseClerk);

    // Can access accrued expense create form
    $this->get(route('transactions.create', ['type' => 'expense', 'settlement_type' => 'accrued']))
        ->assertOk()
        ->assertSee('Create Expense');

    // Cannot access unified payments create screen (403 Forbidden)
    $this->get(route('payments.create'))->assertForbidden();
});
