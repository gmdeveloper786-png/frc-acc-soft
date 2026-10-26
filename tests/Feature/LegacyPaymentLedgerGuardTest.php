<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\PayrollComponentCategory;
use App\Enums\PayrollComponentType;
use App\Enums\StaffAdvanceStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\PayrollComponent;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Services\OperationalTransactionService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    AccountingPeriod::factory()->create(['fiscal_year_id' => $year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $liability = fn () => Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->apLedger = $liability();
    $this->salaryLedger = $liability();
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::AccountsPayable->value, 'account_id' => $this->apLedger->id, 'updated_by' => $this->maker->id]);
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::SalaryPayable->value, 'account_id' => $this->salaryLedger->id, 'updated_by' => $this->maker->id]);
    $this->advanceLedger = Account::factory()->create(['account_type' => 'asset']);
    $this->advance = StaffAdvance::factory()->create(['branch_id' => $this->branch, 'status' => StaffAdvanceStatus::Approved,
        'payroll_component_id' => PayrollComponent::factory()->deduction()->create(['account_id' => $this->advanceLedger->id])]);
    $this->approved = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'direct_payment_eligible' => true]);
    $this->unapproved = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'direct_payment_eligible' => false]);
    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch, 'account_id' => Account::factory()]);
    $this->bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch, 'account_id' => Account::factory()]);
    $this->engine = app(OperationalTransactionService::class);
    $this->actingAs($this->maker);
});

function legacyLedgerPayment(object $test, OperationalTransactionType $type, FinancialAccount $account, Account $contra, array $overrides = []): array
{
    return ['type' => $type->value, 'branch_id' => $test->branch->id, 'transaction_date' => '2026-01-20', 'amount' => '100', 'financial_account_id' => $account->id,
        'contra_account_id' => $contra->id, 'party_name' => 'Legacy payee', 'narration' => 'Legacy guard test', ...$overrides];
}

test('generic legacy payments cannot debit payable, salary payable, staff advance or unapproved expense ledgers', function () {
    $cases = [
        [OperationalTransactionType::BankPayment, $this->bank, $this->apLedger, 'payable ledger'],
        [OperationalTransactionType::CashPayment, $this->cash, $this->salaryLedger, 'Salary payable is paid only'],
        [OperationalTransactionType::BankPayment, $this->bank, $this->advanceLedger, 'staff advance ledger'],
        [OperationalTransactionType::CashPayment, $this->cash, $this->unapproved, 'not approved for direct payment'],
        [OperationalTransactionType::PettyCash, fundedPettyCashAccount($this->branch->id, '1000.0000'), $this->unapproved, 'not approved for direct payment'],
    ];
    $journals = JournalEntry::query()->count();
    foreach ($cases as [$type, $account, $contra, $message]) {
        expect(fn () => $this->engine->create(legacyLedgerPayment($this, $type, $account, $contra), $this->maker))->toThrow(ValidationException::class, $message);
    }
    // An approved expense ledger stays payable from the legacy screens.
    expect($this->engine->create(legacyLedgerPayment($this, OperationalTransactionType::CashPayment, $this->cash, $this->approved), $this->maker)->status)->toBe(OperationalTransactionStatus::Draft)
        ->and(OperationalTransaction::query()->count())->toBe(1)->and(JournalEntry::query()->count())->toBe($journals);
});

test('tampered legacy requests cannot claim a payroll run or staff advance, and old drafts are stopped before approval', function () {
    $tampered = fn (Account $contra, string $source) => $this->post(route('transactions.store'), legacyLedgerPayment($this, OperationalTransactionType::BankPayment, $this->bank, $contra,
        ['source_reference' => $source, 'payment_purpose_id' => 1, 'staff_advance_id' => $this->advance->id]));
    $tampered($this->salaryLedger, 'payroll_run:1')->assertSessionHasErrors('contra_account_id');
    $tampered($this->advanceLedger, 'staff_advance:'.$this->advance->id)->assertSessionHasErrors('contra_account_id');
    expect(OperationalTransaction::query()->count())->toBe(0);

    // A service-made reference is still checked against the advance: wrong branch or ledger is refused.
    $other = StaffAdvance::factory()->create(['status' => StaffAdvanceStatus::Approved, 'payroll_component_id' => $this->advance->payroll_component_id]);
    expect(fn () => $this->engine->create(legacyLedgerPayment($this, OperationalTransactionType::BankPayment, $this->bank, $this->advanceLedger, ['source_reference' => 'staff_advance:'.$other->id]), $this->maker))
        ->toThrow(ValidationException::class, 'staff advance ledger');

    // A draft saved before this rule (written directly here) cannot be submitted, and no journal is made.
    $old = OperationalTransaction::factory()->create(['type' => OperationalTransactionType::BankPayment, 'status' => OperationalTransactionStatus::Draft, 'branch_id' => $this->branch->id,
        'amount' => '100', 'financial_account_id' => $this->bank->id, 'contra_account_id' => $this->apLedger->id, 'created_by' => $this->maker->id]);
    expect(fn () => $this->engine->submit($old, $this->maker))->toThrow(ValidationException::class, 'payable ledger')
        ->and($old->fresh()->status)->toBe(OperationalTransactionStatus::Draft)->and($old->fresh()->journal_entry_id)->toBeNull();
});

/** Posts an expense through the full maker-checker workflow. */
function legacyPostedExpense(object $test, array $data): OperationalTransaction
{
    $expense = $test->engine->create($data, $test->maker);
    $test->engine->submit($expense, $test->maker);
    $test->engine->approve($expense->fresh(), $test->checker);
    if ($data['settlement_type'] === 'paid') {
        $test->engine->disburse($expense->fresh(), $test->checker);
    }

    return $test->engine->post($expense->fresh(), $test->checker);
}

test('paid expenses cannot debit payable, salary payable, staff advance, obligation payable or unapproved expense ledgers', function () {
    // An accrued expense with its own payable ledger is unaffected and creates an obligation on that ledger.
    $customPayable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $accrued = legacyPostedExpense($this, legacyLedgerPayment($this, OperationalTransactionType::Expense, $this->cash, $this->unapproved,
        ['settlement_type' => 'accrued', 'financial_account_id' => null, 'payable_account_id' => $customPayable->id]));
    expect($accrued->status)->toBe(OperationalTransactionStatus::Posted)
        ->and($accrued->journalEntry->lines[0]->account_id)->toBe($this->unapproved->id)
        ->and($accrued->journalEntry->lines[1]->account_id)->toBe($customPayable->id)
        ->and($accrued->accruedPayableObligation()->exists())->toBeTrue();

    $journals = JournalEntry::query()->count();
    $transactions = OperationalTransaction::query()->count();
    foreach ([
        [$this->apLedger, 'payable ledger'],
        [$customPayable, 'payable ledger'],
        [$this->salaryLedger, 'Salary payable is paid only'],
        [$this->advanceLedger, 'staff advance ledger'],
        [$this->unapproved, 'not approved for direct payment'],
    ] as [$contra, $message]) {
        expect(fn () => $this->engine->create(legacyLedgerPayment($this, OperationalTransactionType::Expense, $this->cash, $contra, ['settlement_type' => 'paid']), $this->maker))
            ->toThrow(ValidationException::class, $message);
    }
    expect(OperationalTransaction::query()->count())->toBe($transactions)->and(JournalEntry::query()->count())->toBe($journals);
});

test('tampered paid expense requests cannot claim a payroll run, staff advance or payable obligation', function () {
    $customPayable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $accrued = legacyPostedExpense($this, legacyLedgerPayment($this, OperationalTransactionType::Expense, $this->cash, $this->approved,
        ['settlement_type' => 'accrued', 'financial_account_id' => null, 'payable_account_id' => $customPayable->id]));
    $obligationId = $accrued->accruedPayableObligation->id;
    $count = OperationalTransaction::query()->count();
    $tampered = fn (Account $contra, array $extra) => $this->post(route('transactions.store'),
        legacyLedgerPayment($this, OperationalTransactionType::Expense, $this->cash, $contra, ['settlement_type' => 'paid', ...$extra]));

    $tampered($this->salaryLedger, ['source_reference' => 'payroll_run:1'])->assertSessionHasErrors(['settlement_type' => 'Direct payments must be created from Payments. Expenses is used to record accrued expenses.']);
    $tampered($this->advanceLedger, ['source_reference' => 'staff_advance:'.$this->advance->id, 'staff_advance_id' => $this->advance->id])->assertSessionHasErrors(['settlement_type' => 'Direct payments must be created from Payments. Expenses is used to record accrued expenses.']);
    $tampered($customPayable, ['payable_obligation_id' => $obligationId, 'settlement_request_token' => (string) Str::uuid()])->assertSessionHasErrors(['settlement_type' => 'Direct payments must be created from Payments. Expenses is used to record accrued expenses.']);
    $tampered($this->unapproved, [])->assertSessionHasErrors(['settlement_type' => 'Direct payments must be created from Payments. Expenses is used to record accrued expenses.']);
    expect(OperationalTransaction::query()->count())->toBe($count);
});

test('unsafe paid expense records written before the rule cannot be submitted, approved, disbursed or posted', function () {
    $journals = JournalEntry::query()->count();
    $unsafe = fn (OperationalTransactionStatus $status, Account $contra) => OperationalTransaction::factory()->create([
        'type' => OperationalTransactionType::Expense, 'settlement_type' => 'paid', 'status' => $status, 'branch_id' => $this->branch->id, 'amount' => '100',
        'financial_account_id' => $this->cash->id, 'contra_account_id' => $contra->id, 'payable_obligation_id' => null, 'purchase_bill_id' => null,
        'source_reference' => null, 'created_by' => $this->maker->id, 'submitted_by' => $this->maker->id, 'approval_tier' => 'standard', 'journal_entry_id' => null,
    ]);

    $draft = $unsafe(OperationalTransactionStatus::Draft, $this->apLedger);
    expect(fn () => $this->engine->submit($draft, $this->maker))->toThrow(ValidationException::class, 'payable ledger');
    $submitted = $unsafe(OperationalTransactionStatus::Submitted, $this->salaryLedger);
    expect(fn () => $this->engine->approve($submitted, $this->checker))->toThrow(ValidationException::class, 'Salary payable is paid only');
    $approved = $unsafe(OperationalTransactionStatus::Approved, $this->advanceLedger);
    expect(fn () => $this->engine->disburse($approved, $this->checker))->toThrow(ValidationException::class, 'staff advance ledger');
    $disbursed = $unsafe(OperationalTransactionStatus::Disbursed, $this->unapproved);
    expect(fn () => $this->engine->post($disbursed, $this->checker))->toThrow(ValidationException::class, 'not approved for direct payment');

    foreach ([[$draft, OperationalTransactionStatus::Draft], [$submitted, OperationalTransactionStatus::Submitted], [$approved, OperationalTransactionStatus::Approved], [$disbursed, OperationalTransactionStatus::Disbursed]] as [$record, $status]) {
        expect($record->fresh()->status)->toBe($status)->and($record->fresh()->journal_entry_id)->toBeNull();
    }
    expect(JournalEntry::query()->count())->toBe($journals);
});

test('an approved paid expense posts once and its reversal still works after the ledger loses direct payment approval', function () {
    $paid = legacyPostedExpense($this, legacyLedgerPayment($this, OperationalTransactionType::Expense, $this->cash, $this->approved, ['settlement_type' => 'paid']));
    expect($paid->status)->toBe(OperationalTransactionStatus::Posted)
        ->and($paid->journalEntry->lines[0]->account_id)->toBe($this->approved->id)
        ->and($paid->journalEntry->lines[0]->debit)->toBe('100.0000')
        ->and($paid->journalEntry->lines[1]->account_id)->toBe($this->cash->account_id)
        ->and($paid->journalEntry->lines[1]->credit)->toBe('100.0000');

    // Posted history is corrected by reversal; withdrawing the ledger's approval later does not block it.
    $this->approved->update(['direct_payment_eligible' => false]);
    $reversed = $this->engine->reverse($paid->fresh(), AccountingPeriod::query()->firstOrFail(), '2026-01-25', 'Historical paid expense correction', $this->checker);
    expect($reversed->status)->toBe(OperationalTransactionStatus::Reversed)
        ->and(JournalEntry::query()->where('reversal_of_id', $paid->journal_entry_id)->exists())->toBeTrue();
});

test('a configured advance recovery component ledger without historical advances cannot be debited directly, while statutory tax remittances remain payable', function () {
    // 1. Advance recovery component configured with an asset ledger, but ZERO StaffAdvance records exist for it.
    $unlinkedAdvanceLedger = Account::factory()->create(['account_type' => 'asset', 'name' => 'Staff Advances Unlinked']);
    $advanceComponent = PayrollComponent::factory()->create([
        'code' => 'ADV_RECOV_2', 'name' => 'Advance Recovery 2', 'type' => PayrollComponentType::Deduction,
        'category' => PayrollComponentCategory::Advance, 'account_id' => $unlinkedAdvanceLedger->id,
    ]);
    expect(StaffAdvance::query()->where('payroll_component_id', $advanceComponent->id)->count())->toBe(0);

    // Legacy Bank Payment, Cash Payment, and Paid Expense must all be rejected.
    expect(fn () => $this->engine->create(legacyLedgerPayment($this, OperationalTransactionType::BankPayment, $this->bank, $unlinkedAdvanceLedger), $this->maker))
        ->toThrow(ValidationException::class, 'staff advance ledger');
    expect(fn () => $this->engine->create(legacyLedgerPayment($this, OperationalTransactionType::CashPayment, $this->cash, $unlinkedAdvanceLedger), $this->maker))
        ->toThrow(ValidationException::class, 'staff advance ledger');
    expect(fn () => $this->engine->create(legacyLedgerPayment($this, OperationalTransactionType::Expense, $this->cash, $unlinkedAdvanceLedger, ['settlement_type' => 'paid']), $this->maker))
        ->toThrow(ValidationException::class, 'staff advance ledger');

    // 2. Unrelated statutory or tax deduction liability ledger is NOT classified as a staff advance and can be remitted legitimately.
    $taxLiabilityLedger = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit', 'name' => 'Income Tax Withheld']);
    PayrollComponent::factory()->create([
        'code' => 'TAX_WITHHELD', 'name' => 'Tax Withholding', 'type' => PayrollComponentType::Deduction,
        'category' => PayrollComponentCategory::Tax, 'account_id' => $taxLiabilityLedger->id,
    ]);
    $taxRemittance = $this->engine->create(legacyLedgerPayment($this, OperationalTransactionType::BankPayment, $this->bank, $taxLiabilityLedger, ['party_name' => 'FBR Tax Office']), $this->maker);
    expect($taxRemittance->status)->toBe(OperationalTransactionStatus::Draft);
});
