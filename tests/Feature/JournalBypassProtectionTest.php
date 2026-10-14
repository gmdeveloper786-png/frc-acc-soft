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
use App\Models\JournalEntry;
use App\Models\OpeningBalanceBatch;
use App\Models\PurchaseBill;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VoucherType;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use App\Services\JournalWorkflowService;
use App\Services\OpeningBalancePostingService;
use App\Services\OperationalTransactionService;
use App\Services\SplitPaymentDetector;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\DonationCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class, DonationCategorySeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->maker = bypassUser(SystemRole::Accountant, $this->branch);
    $this->checker = bypassUser(SystemRole::Administrator, $this->branch);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->cashLedger = Account::factory()->create();
    $this->bankLedger = Account::factory()->create();
    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch, 'account_id' => $this->cashLedger]);
    $this->bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch, 'account_id' => $this->bankLedger]);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->income = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->equity = Account::factory()->create(['account_type' => 'equity', 'normal_balance' => 'credit']);
    $this->actingAs($this->maker);
});

function bypassUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

function manualJournal(object $test, int $debitAccount, int $creditAccount, string $amount = '500000.0000', array $extra = []): array
{
    return [
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-15',
        'fiscal_year_id' => $test->year->id, 'accounting_period_id' => $test->period->id, 'branch_id' => $test->branch->id,
        'narration' => 'Manual journal fixture',
        'lines' => [['account_id' => $debitAccount, 'debit' => $amount, 'credit' => '0'], ['account_id' => $creditAccount, 'debit' => '0', 'credit' => $amount]],
        ...$extra,
    ];
}

function splitPayment(object $test, string $amount, array $extra = [])
{
    return app(OperationalTransactionService::class)->create([
        'type' => OperationalTransactionType::CashPayment->value, 'branch_id' => $test->branch->id, 'transaction_date' => '2026-01-15', 'amount' => $amount,
        'financial_account_id' => $test->cash->id, 'contra_account_id' => $test->expense->id, 'narration' => 'Split fixture', ...$extra,
    ], $test->maker);
}

test('a manual journal cannot pay money out by crediting cash or bank', function (string $ledger) {
    $workflow = app(JournalWorkflowService::class);
    $journal = $workflow->create(manualJournal($this, $this->expense->id, $this->{$ledger}->id), $this->maker);

    expect(fn () => $workflow->submit($journal, $this->maker))->toThrow(ValidationException::class)
        ->and($journal->fresh()->status)->toBe(JournalStatus::Draft)
        ->and(AuditLog::query()->where('event', 'journal.cash_bank_credit_blocked')->where('auditable_id', $journal->id)->exists())->toBeTrue();

    // Even a journal forced into the approved state cannot be posted or approved.
    $journal->forceFill(['status' => JournalStatus::Approved, 'submitted_by' => $this->maker->id, 'approved_by' => $this->checker->id])->save();
    expect(fn () => app(JournalPostingService::class)->post($journal->fresh(), $this->checker))->toThrow(ValidationException::class);
    $journal->forceFill(['status' => JournalStatus::Submitted])->save();
    expect(fn () => $workflow->approve($journal->fresh(), $this->checker))->toThrow(ValidationException::class)
        ->and($journal->fresh()->voucher_number)->toBeNull()
        ->and(JournalEntry::query()->where('status', JournalStatus::Posted->value)->count())->toBe(0);

    $journal->forceFill(['status' => JournalStatus::Draft])->save();
    $this->post(route('journals.submit', $journal))->assertSessionHasErrors('lines');
})->with(['cash ledger' => 'cashLedger', 'bank ledger' => 'bankLedger']);

test('forged source references in a request cannot make a manual journal trusted', function () {
    $payload = manualJournal($this, $this->expense->id, $this->cashLedger->id, '900000.0000', ['source_system' => 'accounting', 'source_type' => 'journal_reversal', 'source_id' => '1', 'source_key' => 'journal_reversal:1', 'status' => 'approved', 'approved_by' => $this->checker->id, 'voucher_number' => 'JV-FAKE']);
    $this->post(route('journals.store'), $payload)->assertRedirect();
    $journal = JournalEntry::query()->firstOrFail();

    expect($journal->source_type)->toBeNull()
        ->and($journal->source_key)->toBeNull()
        ->and($journal->status)->toBe(JournalStatus::Draft)
        ->and($journal->voucher_number)->toBeNull()
        ->and(fn () => app(JournalWorkflowService::class)->submit($journal, $this->maker))->toThrow(ValidationException::class);

    // A source label written straight into the row gives no exemption on the manual path.
    $journal->forceFill(['source_system' => 'accounting', 'source_type' => 'cash_payment', 'source_key' => 'operational_transaction:999', 'status' => JournalStatus::Approved, 'approved_by' => $this->checker->id])->save();
    expect(fn () => app(JournalPostingService::class)->post($journal->fresh(), $this->checker))->toThrow(ValidationException::class);
    $unlabelled = app(JournalWorkflowService::class)->create(manualJournal($this, $this->expense->id, $this->income->id), $this->maker);
    expect(fn () => app(JournalPostingService::class)->postSystem($unlabelled, $this->checker))->toThrow(LogicException::class);
});

test('legitimate manual journals still post: non cash entries, cash debits and contra transfers', function () {
    $workflow = app(JournalWorkflowService::class);
    $cases = [
        'correction' => manualJournal($this, $this->expense->id, $this->income->id, '1200.0000'),
        'cash debit' => manualJournal($this, $this->cashLedger->id, $this->income->id, '700.0000'),
        'contra' => manualJournal($this, $this->cashLedger->id, $this->bankLedger->id, '900000.0000'),
    ];
    foreach ($cases as $payload) {
        $this->actingAs($this->maker);
        $journal = $workflow->create($payload, $this->maker);
        $workflow->submit($journal, $this->maker);
        $this->actingAs($this->checker);
        $workflow->approve($journal->fresh(), $this->checker);
        expect(app(JournalPostingService::class)->post($journal->fresh(), $this->checker)->status)->toBe(JournalStatus::Posted);
    }
    expect(JournalEntry::query()->where('status', JournalStatus::Posted->value)->count())->toBe(3);
});

test('an authorized reversal of a payment and a posted opening balance are preserved', function () {
    $service = app(OperationalTransactionService::class);
    $payment = splitPayment($this, '1000.0000');
    $service->submit($payment, $this->maker);
    $this->actingAs($this->checker);
    $service->approve($payment->fresh(), $this->checker);
    $service->disburse($payment->fresh(), $this->checker);
    $posted = $service->post($payment->fresh(), $this->checker);
    expect($posted->journalEntry->source_type)->toBe('cash_payment')->and($posted->journalEntry->lines[1]->account_id)->toBe($this->cashLedger->id);

    $reversal = app(JournalReversalService::class)->reverse($posted->journalEntry, $this->period, '2026-01-20', 'Payment recorded twice', $this->checker);
    expect($reversal->status)->toBe(JournalStatus::Posted)
        ->and($reversal->source_type)->toBe('journal_reversal')
        ->and($reversal->reversal_of_id)->toBe($posted->journalEntry->id);

    $batch = OpeningBalanceBatch::factory()->create(['branch_id' => $this->branch, 'fiscal_year_id' => $this->year, 'opening_date' => '2026-01-01', 'status' => 'approved', 'total_debit' => '5000.0000', 'total_credit' => '5000.0000', 'created_by' => $this->maker, 'approved_by' => $this->checker, 'approved_at' => now()]);
    $batch->lines()->create(['account_id' => $this->equity->id, 'debit' => '5000.0000', 'credit' => '0.0000']);
    $batch->lines()->create(['account_id' => $this->bankLedger->id, 'debit' => '0.0000', 'credit' => '5000.0000']);
    $postedBatch = app(OpeningBalancePostingService::class)->post($batch, $this->checker);
    expect($postedBatch->status)->toBe('posted')
        ->and(fn () => app(OpeningBalancePostingService::class)->post($postedBatch, $this->checker))->toThrow(ValidationException::class);
});

test('repeated payments against one bill are flagged as a possible split and overpayment stays blocked', function () {
    $bill = PurchaseBill::factory()->create(['branch_id' => $this->branch, 'vendor_id' => Vendor::factory(), 'status' => 'posted', 'total_amount' => '280000.0000', 'created_by' => $this->maker]);
    $data = ['type' => OperationalTransactionType::VendorPayment->value, 'financial_account_id' => $this->bank->id, 'purchase_bill_id' => $bill->id, 'contra_account_id' => null];
    $first = splitPayment($this, '140000.0000', $data);
    expect(app(SplitPaymentDetector::class)->analyze($first))->toBeNull();

    $second = splitPayment($this, '140000.0000', $data);
    $warning = app(SplitPaymentDetector::class)->analyze($second);
    expect($warning['basis'])->toBe('purchase_bill:'.$bill->id)
        ->and($warning['count'])->toBe(2)
        ->and($warning['aggregate'])->toBe('280000.0000')
        ->and($second->approval_tier)->toBe('standard')
        ->and(AuditLog::query()->where('event', 'vendor_payments.split_warning')->where('auditable_id', $second->id)->exists())->toBeTrue()
        ->and(fn () => splitPayment($this, '0.0001', $data))->toThrow(ValidationException::class);

    $service = app(OperationalTransactionService::class);
    $service->submit($second, $this->maker);
    $this->actingAs($this->checker)->get(route('transactions.show', $second))->assertOk()->assertSee('Possible split payment');
    $approved = $service->approve($second->fresh(), $this->checker);
    expect($approved->status)->toBe(OperationalTransactionStatus::Approved)
        ->and(AuditLog::query()->where('event', 'vendor_payments.approved')->latest('id')->first()->properties['split_warning']['count'])->toBe(2);
});

test('payments split across vouchers to one party are flagged within the detection window only', function () {
    $detector = app(SplitPaymentDetector::class);
    $first = splitPayment($this, '100000.0000', ['party_name' => 'Al Noor Traders']);
    $second = splitPayment($this, '100000.0000', ['party_name' => '  al noor traders ', 'transaction_date' => '2026-01-18', 'type' => OperationalTransactionType::BankPayment->value, 'financial_account_id' => $this->bank->id]);
    $far = splitPayment($this, '100000.0000', ['party_name' => 'Al Noor Traders', 'transaction_date' => '2026-01-30']);
    $other = splitPayment($this, '100000.0000', ['party_name' => 'Different Supplier']);
    $single = splitPayment($this, '150000.0001', ['party_name' => 'Large Single Payee']);

    expect($detector->analyze($second)['count'])->toBe(2)
        ->and($detector->analyze($first->fresh())['aggregate'])->toBe('200000.0000')
        ->and($detector->analyze($far))->toBeNull()
        ->and($detector->analyze($other))->toBeNull()
        ->and($detector->analyze($single))->toBeNull()
        ->and($single->approval_tier)->toBe('director');
});
