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
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\Payee;
use App\Models\PayeeType;
use App\Models\PaymentPurpose;
use App\Models\User;
use App\Services\OperationalTransactionService;
use App\Services\Payments\UnifiedPaymentService;
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
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'direct_payment_eligible' => true]);
    $this->income = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->cashA = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branchA, 'account_id' => Account::factory()]);
    $this->cashB = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branchB, 'account_id' => Account::factory()]);
    $this->mcb = FinancialAccount::factory()->create(['type' => 'bank', 'name' => 'MCB Shared', 'branch_id' => $this->branchA, 'scope' => 'organization', 'account_id' => Account::factory()]);
    $this->pettyA = fundedPettyCashAccount($this->branchA->id, '1000.0000');
    $type = PayeeType::query()->create(['name' => 'Supplier (other)', 'code' => 'supplier_other', 'source_kind' => 'directory', 'is_active' => true]);
    $this->payee = ['payee_type_id' => $type->id, 'payee_id' => Payee::query()->create(['payee_type_id' => $type->id, 'name' => 'Corner Shop', 'entity_kind' => 'organization', 'is_active' => true])->id];
    $this->engine = app(OperationalTransactionService::class);
    $this->actingAs($this->maker);

    $unified = fn (string $source, FinancialAccount $account, Branch $branch, string $reference) => app(UnifiedPaymentService::class)->create(['payment_source' => $source,
        'payment_purpose_id' => PaymentPurpose::query()->where('code', 'direct_expense')->value('id'), 'branch_id' => $branch->id, 'transaction_date' => '2026-01-20',
        'financial_account_id' => $account->id, 'amount' => '100', 'debit_account_id' => $this->expense->id, 'reference_number' => $reference, ...$this->payee], $this->maker);
    $legacy = fn (OperationalTransactionType $type, FinancialAccount $account, Branch $branch, string $reference, int $contra) => $this->engine->create(['type' => $type->value,
        'branch_id' => $branch->id, 'transaction_date' => '2026-01-18', 'amount' => '50', 'financial_account_id' => $account->id, 'contra_account_id' => $contra,
        'party_name' => 'Legacy Party '.$reference, 'reference_number' => $reference, 'narration' => 'Legacy fixture'], $this->maker);

    $this->sharedB = $unified('bank', $this->mcb, $this->branchB, 'UNI-MCB-B');
    $this->pettyUnified = $unified('petty_cash', $this->pettyA, $this->branchA, 'UNI-PETTY-A');
    $this->legacyCashA = $legacy(OperationalTransactionType::CashPayment, $this->cashA, $this->branchA, 'LEG-CASH-A', $this->expense->id);
    $this->legacyCashB = $legacy(OperationalTransactionType::CashPayment, $this->cashB, $this->branchB, 'LEG-CASH-B', $this->expense->id);
    $this->receipt = $legacy(OperationalTransactionType::CashReceipt, $this->cashA, $this->branchA, 'RCPT-A', $this->income->id);

    // The legacy branch A cash payment goes all the way to posted and is then reversed.
    $this->engine->submit($this->legacyCashA, $this->maker);
    $this->engine->approve($this->legacyCashA->fresh(), $this->checker);
    $this->engine->disburse($this->legacyCashA->fresh(), $this->checker);
    $this->engine->post($this->legacyCashA->fresh(), $this->checker);
    $this->engine->reverse($this->legacyCashA->fresh(), $this->period, '2026-01-25', 'Duplicate', $this->checker);
});

test('every outgoing payment, new and legacy, posted or reversed, is listed exactly once and receipts are not', function () {
    $journals = JournalEntry::query()->count();
    $html = $this->get(route('payments.index'))->assertOk()->assertSee('href="'.route('payments.create').'"', false)->assertSee('Create Payment')->getContent();

    foreach ([$this->sharedB, $this->pettyUnified, $this->legacyCashA, $this->legacyCashB] as $payment) {
        expect(substr_count($html, 'data-payment-row="'.$payment->id.'"'))->toBe(1);
    }
    expect($html)->not->toContain('data-payment-row="'.$this->receipt->id.'"')->not->toContain('RCPT-A')
        ->and($this->legacyCashA->fresh()->status)->toBe(OperationalTransactionStatus::Reversed)
        ->and($html)->toContain('Corner Shop')->toContain('Direct expense')->toContain('Legacy Party LEG-CASH-B')->toContain('Not recorded')->toContain('Petty Cash')
        ->and(JournalEntry::query()->count())->toBe($journals);

    // The shared MCB payment stays under the branch that made it.
    $this->get(route('payments.index', ['branch_id' => $this->branchB->id]))->assertSee('UNI-MCB-B')->assertDontSee('UNI-PETTY-A');
    $this->get(route('payments.index', ['branch_id' => $this->branchA->id]))->assertDontSee('UNI-MCB-B')->assertSee('LEG-CASH-A');
    $this->get(route('payments.index', ['source' => 'petty_cash']))->assertSee('UNI-PETTY-A')->assertDontSee('LEG-CASH-B')->assertDontSee('UNI-MCB-B');
    $this->get(route('payments.index', ['source' => 'bank']))->assertSee('UNI-MCB-B')->assertDontSee('LEG-CASH-B');
    $this->get(route('payments.index', ['status' => 'reversed']))->assertSee('LEG-CASH-A')->assertDontSee('LEG-CASH-B');
    $this->get(route('payments.index', ['search' => 'Corner']))->assertSee('UNI-MCB-B')->assertDontSee('LEG-CASH-B');
    $this->get(route('payments.index', ['purpose_id' => PaymentPurpose::query()->where('code', 'direct_expense')->value('id')]))->assertSee('UNI-PETTY-A')->assertDontSee('LEG-CASH-B');
    $voucher = $this->legacyCashA->fresh()->journalEntry->voucher_number;
    $this->get(route('payments.index', ['search' => $voucher]))->assertSee('LEG-CASH-A')->assertDontSee('LEG-CASH-B');
});

test('a branch-limited user sees only their branch payments, whatever the filters', function () {
    $clerk = User::factory()->create();
    $clerk->assignRole(SystemRole::Accountant->value);
    $clerk->branches()->attach($this->branchA);
    $this->actingAs($clerk);

    $this->get(route('payments.index'))->assertOk()->assertSee('All accessible branches')->assertDontSee('Beta Branch')->assertSee('LEG-CASH-A')->assertDontSee('LEG-CASH-B')->assertDontSee('UNI-MCB-B');
    $this->get(route('payments.index', ['branch_id' => $this->branchB->id]))->assertOk()->assertDontSee('LEG-CASH-B')->assertDontSee('UNI-MCB-B');
    $this->get(route('payments.index', ['search' => 'LEG-CASH-B']))->assertOk()->assertDontSee('data-payment-row', false);
    $this->get(route('payments.index', ['search' => 'Corner', 'source' => 'bank', 'page' => 2]))->assertOk()->assertDontSee('UNI-MCB-B');
    $this->get(route('transactions.show', $this->legacyCashB))->assertForbidden();

    $this->actingAs(User::factory()->create())->get(route('payments.index'))->assertForbidden();
});

test('the sidebar shows one Payments entry, keeps imprest and receipts, and legacy routes still work', function () {
    $html = $this->get(route('payments.index'))->assertOk()->getContent();
    expect($html)->toContain('<span class="sidebar-text">Payments</span>')->toContain('<span class="sidebar-text">Petty Cash Imprest</span>')
        ->toContain('<span class="sidebar-text">Cash Receipts</span>')->toContain('<span class="sidebar-text">Expenses</span>')
        ->not->toContain('<span class="sidebar-text">Cash Payments</span>')->not->toContain('<span class="sidebar-text">Bank Payments</span>')
        ->not->toContain('<span class="sidebar-text">Vendor Payments</span>')->not->toContain('<span class="sidebar-text">Petty Cash</span>')
        ->not->toContain('<span class="sidebar-text">Create Payment</span>');

    foreach (['cash_payment', 'bank_payment', 'vendor_payment', 'petty_cash'] as $type) {
        $this->get(route('transactions.index', $type))->assertOk();
    }
    $this->get(route('transactions.show', $this->legacyCashA))->assertOk();
    $this->get(route('payments.create'))->assertOk();
    $this->get(route('imprest.index'))->assertOk();
});

test('every payment register creates on the unified screen with its source preselected, while other registers and legacy URLs keep working', function () {
    foreach (['bank_payment' => 'bank', 'cash_payment' => 'cash', 'petty_cash' => 'petty_cash', 'vendor_payment' => null] as $type => $source) {
        $this->get(route('transactions.index', $type))->assertOk()->assertSee('Create Payment')
            ->assertSee('href="'.e(route('payments.create', array_filter(['source' => $source]))).'"', false)
            ->assertDontSee('href="'.route('transactions.create', $type).'"', false);
        $this->get(route('transactions.create', $type))->assertOk();
    }
    $this->get(route('transactions.index', 'cash_receipt'))->assertOk()->assertSee('href="'.route('transactions.create', 'cash_receipt').'"', false);

    foreach (['bank', 'cash', 'petty_cash'] as $source) {
        $this->get(route('payments.create', ['source' => $source]))->assertOk()->assertSee('<option value="'.$source.'" selected', false);
    }
    $this->get(route('payments.create', ['source' => 'cheque']))->assertOk()->assertDontSee('" selected>Bank</option>', false);

    // A source the user may not pay from is not preselected, and a user without the create permission sees no button.
    $clerk = User::factory()->create();
    $clerk->givePermissionTo(['payments.create', 'payments.view', 'petty_cash.view']);
    $clerk->branches()->attach($this->branchA);
    $this->actingAs($clerk)->get(route('payments.create', ['source' => 'petty_cash']))->assertOk()->assertDontSee('<option value="petty_cash"', false);
    $this->get(route('transactions.index', 'petty_cash'))->assertOk()->assertDontSee('Create Payment');
    $this->get(route('transactions.index', 'cash_payment'))->assertOk()->assertSee('Create Payment');
});

test('historical paid expenses are included in payments history with Paid Expense (Legacy) label and correct source while accrued expenses are excluded', function () {
    $journalsBefore = JournalEntry::query()->count();
    $transactionsBefore = OperationalTransaction::query()->count();

    // 1. Create historical paid expense on Branch A (Bank)
    $paidBank = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Expense,
        'settlement_type' => 'paid',
        'status' => OperationalTransactionStatus::Posted,
        'branch_id' => $this->branchA->id,
        'amount' => '350.0000',
        'financial_account_id' => $this->mcb->id,
        'contra_account_id' => $this->expense->id,
        'party_name' => 'Legacy Bank Vendor',
        'reference_number' => 'EXP-PAID-BANK-1',
        'narration' => 'Historical paid bank expense',
        'created_by' => $this->maker->id,
        'transaction_date' => '2026-01-14',
    ]);
    $journalId = DB::table('journal_entries')->insertGetId([
        'voucher_type_id' => 1,
        'voucher_number' => 'JV-2026-BANK-EXP',
        'branch_id' => $this->branchA->id,
        'transaction_date' => '2026-01-14',
        'fiscal_year_id' => $this->period->fiscal_year_id,
        'accounting_period_id' => $this->period->id,
        'narration' => 'Historical expense journal',
        'source_type' => OperationalTransactionType::Expense->value,
        'source_id' => $paidBank->id,
        'status' => JournalStatus::Posted->value,
        'total_debit' => '350.0000',
        'total_credit' => '350.0000',
        'created_by' => $this->maker->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $paidBank->update(['journal_entry_id' => $journalId]);

    // 2. Create historical draft and reversed paid expenses on Branch A (Cash)
    $paidDraft = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Expense,
        'settlement_type' => 'paid',
        'status' => OperationalTransactionStatus::Draft,
        'branch_id' => $this->branchA->id,
        'amount' => '120.0000',
        'financial_account_id' => $this->cashA->id,
        'contra_account_id' => $this->expense->id,
        'party_name' => 'Legacy Draft Vendor',
        'reference_number' => 'EXP-PAID-DRAFT-1',
        'narration' => 'Historical draft cash expense',
        'created_by' => $this->maker->id,
        'transaction_date' => '2026-01-15',
    ]);

    $paidReversed = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Expense,
        'settlement_type' => 'paid',
        'status' => OperationalTransactionStatus::Reversed,
        'branch_id' => $this->branchA->id,
        'amount' => '220.0000',
        'financial_account_id' => $this->cashA->id,
        'contra_account_id' => $this->expense->id,
        'party_name' => 'Legacy Reversed Vendor',
        'reference_number' => 'EXP-PAID-REV-1',
        'narration' => 'Historical reversed cash expense',
        'created_by' => $this->maker->id,
        'transaction_date' => '2026-01-16',
    ]);

    // 3. Create an Accrued Expense (Must NOT appear in payments history)
    $accrued = OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Expense,
        'settlement_type' => 'accrued',
        'status' => OperationalTransactionStatus::Posted,
        'branch_id' => $this->branchA->id,
        'amount' => '999.0000',
        'financial_account_id' => null,
        'contra_account_id' => $this->expense->id,
        'party_name' => 'Accrued Only Creditor',
        'reference_number' => 'EXP-ACCRUED-NO-PAY',
        'narration' => 'Should never be in payments history',
        'created_by' => $this->maker->id,
        'transaction_date' => '2026-01-17',
    ]);

    // 4. View history as administrator (who has all permissions)
    $response = $this->get(route('payments.index'))->assertOk();
    $html = $response->getContent();

    // Verify each legacy paid expense appears exactly once
    expect(substr_count($html, 'data-payment-row="'.$paidBank->id.'"'))->toBe(1)
        ->and(substr_count($html, 'data-payment-row="'.$paidDraft->id.'"'))->toBe(1)
        ->and(substr_count($html, 'data-payment-row="'.$paidReversed->id.'"'))->toBe(1);

    // Verify accrued expense does NOT appear
    expect($html)->not->toContain('EXP-ACCRUED-NO-PAY')
        ->not->toContain('Accrued Only Creditor')
        ->not->toContain('data-payment-row="'.$accrued->id.'"');

    // Verify label "Paid Expense (Legacy)"
    expect($html)->toContain('Paid Expense (Legacy)')
        ->and($html)->toContain('JV-2026-BANK-EXP')
        ->and($html)->toContain('Legacy Bank Vendor')
        ->and($html)->toContain('Not recorded'); // purpose is not invented

    // 5. Filtering verification
    // Source: bank
    $this->get(route('payments.index', ['source' => 'bank']))
        ->assertSee('EXP-PAID-BANK-1')
        ->assertDontSee('EXP-PAID-DRAFT-1');

    // Source: cash
    $this->get(route('payments.index', ['source' => 'cash']))
        ->assertSee('EXP-PAID-DRAFT-1')
        ->assertSee('EXP-PAID-REV-1')
        ->assertDontSee('EXP-PAID-BANK-1');

    // Status: reversed
    $this->get(route('payments.index', ['status' => 'reversed']))
        ->assertSee('EXP-PAID-REV-1')
        ->assertDontSee('EXP-PAID-DRAFT-1');

    // Status: draft
    $this->get(route('payments.index', ['status' => 'draft']))
        ->assertSee('EXP-PAID-DRAFT-1')
        ->assertDontSee('EXP-PAID-REV-1');

    // Search by reference or party
    $this->get(route('payments.index', ['search' => 'EXP-PAID-BANK-1']))->assertSee('EXP-PAID-BANK-1');
    $this->get(route('payments.index', ['search' => 'JV-2026-BANK-EXP']))->assertSee('EXP-PAID-BANK-1');

    // 6. Branch scoping: Branch B cannot see Branch A's legacy expense
    $branchBClerk = User::factory()->create();
    $branchBClerk->givePermissionTo(['payments.view', 'expenses.view']);
    $branchBClerk->branches()->attach($this->branchB);
    $this->actingAs($branchBClerk);

    $this->get(route('payments.index'))
        ->assertDontSee('EXP-PAID-BANK-1')
        ->assertDontSee('EXP-PAID-DRAFT-1');

    // 7. Security: user with payments.view but NOT expenses.view cannot view expense detail
    $paymentOnlyClerk = User::factory()->create();
    $paymentOnlyClerk->givePermissionTo(['payments.view']);
    $paymentOnlyClerk->branches()->attach($this->branchA);
    $this->actingAs($paymentOnlyClerk);

    $clerkHtml = $this->get(route('payments.index'))->assertOk()->getContent();
    // They see the record in history
    expect($clerkHtml)->toContain('EXP-PAID-BANK-1');
    // But they do NOT have a View link for the expense transaction
    expect($clerkHtml)->not->toContain('href="'.route('transactions.show', $paidBank).'"');
    // Direct attempt to visit transactions.show for the expense is 403 Forbidden
    $this->get(route('transactions.show', $paidBank))->assertForbidden();

    // 8. Financial mutation check: No new journals or payments created from viewing history
    expect(JournalEntry::query()->count())->toBe($journalsBefore + 1)
        ->and(OperationalTransaction::query()->count())->toBe($transactionsBefore + 4);
});
