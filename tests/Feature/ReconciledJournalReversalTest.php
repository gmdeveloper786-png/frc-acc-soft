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
use App\Models\BankReconciliation;
use App\Models\BankReconciliationMatch;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperationalTransaction;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\BankReconciliation\ReconciliationService;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use App\Services\OperationalTransactionService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'direct_payment_eligible' => true]);
    $this->bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch, 'account_id' => Account::factory()]);
    $this->engine = app(OperationalTransactionService::class);
    $this->reconciliations = app(ReconciliationService::class);
    $this->actingAs($this->maker);
});

/** A bank payment taken through the full maker-checker workflow and posted. */
function reconciledReversalPayment(object $test): OperationalTransaction
{
    $payment = $test->engine->create(['type' => OperationalTransactionType::BankPayment->value, 'branch_id' => $test->branch->id, 'transaction_date' => '2026-01-20', 'amount' => '100',
        'financial_account_id' => $test->bank->id, 'contra_account_id' => $test->expense->id, 'party_name' => 'Supplier', 'narration' => 'Reconciled reversal test'], $test->maker);
    $test->engine->submit($payment, $test->maker);
    $test->engine->approve($payment->fresh(), $test->checker);
    $test->engine->disburse($payment->fresh(), $test->checker);

    return $test->engine->post($payment->fresh(), $test->checker);
}

function reconciledReversalBankLine(object $test, OperationalTransaction $payment): JournalLine
{
    return JournalLine::query()->where('journal_entry_id', $payment->journal_entry_id)->where('account_id', $test->bank->account_id)->firstOrFail();
}

/** A draft reconciliation of the bank account in which the payment's bank line is matched through the real workflow. */
function reconciledReversalMatch(object $test, OperationalTransaction $payment): BankReconciliationMatch
{
    $statement = BankStatement::factory()->create(['financial_account_id' => $test->bank->id, 'branch_id' => $test->branch->id, 'uploaded_by' => $test->maker->id]);
    $line = BankStatementLine::factory()->create(['bank_statement_id' => $statement->id, 'financial_account_id' => $test->bank->id, 'transaction_date' => '2026-01-20', 'amount' => '-100.0000']);
    $reconciliation = $test->reconciliations->create($test->bank, ['statement_date' => '2026-01-31', 'statement_closing_balance' => '-100.00'], $test->maker);

    return $test->reconciliations->match($reconciliation, $line->id, reconciledReversalBankLine($test, $payment)->id, $test->maker);
}

/** @return array<string, mixed> */
function reconciledReversalSnapshot(): array
{
    return [
        'journals' => JournalEntry::query()->count(),
        'matches' => BankReconciliationMatch::query()->orderBy('id')->get(['id', 'bank_reconciliation_id', 'bank_statement_line_id', 'journal_line_id'])->toArray(),
        'reconciliations' => BankReconciliation::query()->orderBy('id')->get(['id', 'status', 'open_key'])->toArray(),
        'lines' => BankStatementLine::query()->orderBy('id')->pluck('status', 'id')->all(),
    ];
}

test('a payment whose bank line is matched in a draft, submitted or approved reconciliation cannot be reversed', function (string $status, string $message) {
    $payment = reconciledReversalPayment($this);
    $match = reconciledReversalMatch($this, $payment);
    if ($status !== BankReconciliation::DRAFT) {
        // Fixture: move the reconciliation to the state under test without changing its matches.
        $match->reconciliation->forceFill(['status' => $status, 'open_key' => $status === BankReconciliation::APPROVED ? null : '1'])->save();
    }
    $before = reconciledReversalSnapshot();

    expect(fn () => $this->engine->reverse($payment->fresh(), $this->period, '2026-01-25', 'Attempted correction', $this->checker))
        ->toThrow(ValidationException::class, $message);
    // The source-document path into the journal engine is guarded directly as well.
    expect(fn () => app(JournalReversalService::class)->reverse($payment->journalEntry, $this->period, '2026-01-25', 'Attempted correction', $this->checker))
        ->toThrow(ValidationException::class, $message);

    expect(reconciledReversalSnapshot())->toBe($before)
        ->and($payment->fresh()->status)->toBe(OperationalTransactionStatus::Posted)
        ->and($payment->journalEntry->fresh()->status)->toBe(JournalStatus::Posted)
        ->and(JournalEntry::query()->where('reversal_of_id', $payment->journal_entry_id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'journal.reversed')->exists())->toBeFalse();
})->with([
    'draft' => [BankReconciliation::DRAFT, 'Unmatch it there before reversing'],
    'submitted' => [BankReconciliation::SUBMITTED, 'awaiting approval'],
    'approved' => [BankReconciliation::APPROVED, 'which is locked'],
]);

test('a direct reverse request for a reconciled payment is refused server side', function () {
    $payment = reconciledReversalPayment($this);
    $match = reconciledReversalMatch($this, $payment);
    $match->reconciliation->forceFill(['status' => BankReconciliation::APPROVED, 'open_key' => null])->save();
    $before = reconciledReversalSnapshot();

    $this->actingAs($this->checker)->post(route('transactions.reverse', $payment), ['accounting_period_id' => $this->period->id, 'transaction_date' => '2026-01-25', 'reason' => 'Direct tampered reversal'])
        ->assertSessionHasErrors('status');
    expect(reconciledReversalSnapshot())->toBe($before)->and($payment->fresh()->status)->toBe(OperationalTransactionStatus::Posted);
});

test('a match released by unmatching or by cancelling its draft no longer blocks reversal', function (string $release) {
    $payment = reconciledReversalPayment($this);
    $match = reconciledReversalMatch($this, $payment);
    $reconciliation = $match->reconciliation;
    $release === 'unmatch'
        ? $this->reconciliations->unmatch($reconciliation, $match, $this->maker)
        : $this->reconciliations->cancel($reconciliation, 'Abandoned draft', $this->maker);
    $statusAfterRelease = $reconciliation->fresh()->status;

    $reversed = $this->engine->reverse($payment->fresh(), $this->period, '2026-01-25', 'Legitimate correction', $this->checker);
    expect($reversed->status)->toBe(OperationalTransactionStatus::Reversed)
        ->and($reconciliation->fresh()->status)->toBe($statusAfterRelease)
        ->and(BankReconciliationMatch::query()->count())->toBe(0);
})->with(['unmatch', 'cancel']);

test('an unmatched payment reverses once with a balanced swapped journal and an audit entry', function () {
    $payment = reconciledReversalPayment($this);
    $journals = JournalEntry::query()->count();

    $reversed = $this->engine->reverse($payment->fresh(), $this->period, '2026-01-25', 'Genuine correction', $this->checker);
    $reversal = JournalEntry::query()->with('lines')->where('reversal_of_id', $payment->journal_entry_id)->firstOrFail();
    $original = $payment->journalEntry->fresh('lines');

    expect($reversed->status)->toBe(OperationalTransactionStatus::Reversed)
        ->and(JournalEntry::query()->count())->toBe($journals + 1)
        ->and($original->status)->toBe(JournalStatus::Reversed)
        ->and($reversal->status)->toBe(JournalStatus::Posted)
        ->and($reversal->total_debit)->toBe($reversal->total_credit)
        ->and($reversal->lines->firstWhere('account_id', $this->bank->account_id)->debit)->toBe($original->lines->firstWhere('account_id', $this->bank->account_id)->credit)
        ->and(AuditLog::query()->where('event', 'journal.reversed')->where('auditable_id', $original->id)->exists())->toBeTrue();

    // A second reversal is refused and creates nothing.
    expect(fn () => $this->engine->reverse($payment->fresh(), $this->period, '2026-01-26', 'Duplicate correction', $this->checker))->toThrow(ValidationException::class)
        ->and(JournalEntry::query()->count())->toBe($journals + 1);
});

test('an already reversed journal cannot subsequently be matched as an original bank transaction', function () {
    $payment = reconciledReversalPayment($this);
    $bankLine = reconciledReversalBankLine($this, $payment);
    $this->engine->reverse($payment->fresh(), $this->period, '2026-01-25', 'Reversed before reconciliation', $this->checker);
    expect($payment->fresh()->status)->toBe(OperationalTransactionStatus::Reversed)
        ->and($payment->journalEntry->fresh()->status)->toBe(JournalStatus::Reversed);

    $statement = BankStatement::factory()->create(['financial_account_id' => $this->bank->id, 'branch_id' => $this->branch->id, 'uploaded_by' => $this->maker->id]);
    $line = BankStatementLine::factory()->create(['bank_statement_id' => $statement->id, 'financial_account_id' => $this->bank->id, 'transaction_date' => '2026-01-20', 'amount' => '-100.0000']);
    $reconciliation = $this->reconciliations->create($this->bank, ['statement_date' => '2026-01-31', 'statement_closing_balance' => '-100.00'], $this->maker);

    // Attempting to match the reversed line must be rejected.
    expect(fn () => $this->reconciliations->match($reconciliation, $line->id, $bankLine->id, $this->maker))
        ->toThrow(ValidationException::class, 'unreversed book entry can be matched');
    expect(BankReconciliationMatch::query()->count())->toBe(0)
        ->and($line->fresh()->status)->toBe(BankStatementLine::UNMATCHED);
});

test('a manual journal reversal cannot be approved or posted if a bank line has been matched', function () {
    $reversalService = app(JournalReversalService::class);
    $cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch, 'account_id' => Account::factory()]);
    $poster = User::factory()->create();
    $poster->assignRole(SystemRole::Administrator->value);

    // Create a posted manual contra voucher (CV) transferring money between cash and bank.
    $journal = JournalEntry::query()->create([
        'voucher_type_id' => VoucherType::query()->where('code', 'CV')->value('id') ?? VoucherType::factory()->create()->id,
        'transaction_date' => '2026-01-20',
        'fiscal_year_id' => $this->period->fiscal_year_id,
        'accounting_period_id' => $this->period->id,
        'branch_id' => $this->branch->id,
        'narration' => 'Manual contra transfer cash to bank',
        'source_type' => null,
        'status' => JournalStatus::Approved,
        'approved_by' => $this->checker->id,
        'approved_at' => now(),
        'total_debit' => '100.0000',
        'total_credit' => '100.0000',
        'created_by' => $this->maker->id,
    ]);
    $bankLine = $journal->lines()->create(['line_number' => 1, 'account_id' => $this->bank->account_id, 'debit' => '100.0000', 'credit' => '0.0000']);
    $journal->lines()->create(['line_number' => 2, 'account_id' => $cash->account_id, 'debit' => '0.0000', 'credit' => '100.0000']);
    app(JournalPostingService::class)->post($journal, $poster);

    // Step 1: request reversal while unmatched.
    $reversalRequest = $reversalService->request($journal->fresh(), $this->period, '2026-01-25', 'Legitimate manual reversal reason', $this->maker);
    expect($reversalRequest->status)->toBe(JournalStatus::Submitted);

    // Now bank line gets matched in a reconciliation before approval.
    $statement = BankStatement::factory()->create(['financial_account_id' => $this->bank->id, 'branch_id' => $this->branch->id, 'uploaded_by' => $this->maker->id]);
    $line = BankStatementLine::factory()->create(['bank_statement_id' => $statement->id, 'financial_account_id' => $this->bank->id, 'transaction_date' => '2026-01-20', 'amount' => '100.0000']);
    $reconciliation = $this->reconciliations->create($this->bank, ['statement_date' => '2026-01-31', 'statement_closing_balance' => '100.00'], $this->maker);
    $match = $this->reconciliations->match($reconciliation, $line->id, $bankLine->id, $this->maker);
    expect(BankReconciliationMatch::query()->where('journal_line_id', $match->journal_line_id)->exists())->toBeTrue();

    // Step 2: approving the reversal request must be blocked because the line is now matched.
    expect(fn () => $reversalService->approve($reversalRequest->fresh(), $this->checker))
        ->toThrow(ValidationException::class, 'Unmatch it there before reversing');
});
