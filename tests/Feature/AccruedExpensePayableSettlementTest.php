<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OperationalTransaction;
use App\Models\PayableObligation;
use App\Models\Payee;
use App\Models\PayeeType;
use App\Models\PaymentPurpose;
use App\Models\User;
use App\Services\OperationalTransactionService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->cashLedger = Account::factory()->create(['code' => '111901']);
    $this->expenseLedger = Account::factory()->create(['code' => '531901', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->payableLedger = Account::factory()->create(['code' => '211901', 'account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch, 'account_id' => $this->cashLedger]);
});

function accruedExpenseForSettlement(object $test, string $amount = '3000.0000'): OperationalTransaction
{
    $service = app(OperationalTransactionService::class);
    $expense = $service->create([
        'type' => OperationalTransactionType::Expense->value,
        'branch_id' => $test->branch->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Unassigned vendor',
        'amount' => $amount,
        'contra_account_id' => $test->expenseLedger->id,
        'payable_account_id' => $test->payableLedger->id,
        'settlement_type' => 'accrued',
        'reference_number' => 'ACCRUED-EXP-'.str_replace('.', '-', $amount),
        'narration' => 'Accrued rent expense',
    ], $test->maker);
    $service->submit($expense, $test->maker);
    $service->approve($expense->fresh(), $test->checker);

    return $service->post($expense->fresh(), $test->checker);
}

function settlementPayload(object $test, PayableObligation $obligation, string $amount, string $token): array
{
    return [
        'type' => OperationalTransactionType::CashPayment->value,
        'branch_id' => $test->branch->id,
        'transaction_date' => '2026-01-15',
        'party_name' => 'Forged party is ignored for accounting mapping',
        'amount' => $amount,
        'financial_account_id' => $test->cash->id,
        'contra_account_id' => $test->expenseLedger->id,
        'payable_obligation_id' => $obligation->id,
        'settlement_request_token' => $token,
        'reference_number' => 'SETTLE-'.substr($token, 0, 8),
        'narration' => 'Settlement of accrued expense',
    ];
}

function postSettlement(object $test, array $payload): OperationalTransaction
{
    $service = app(OperationalTransactionService::class);
    $payment = $service->create($payload, $test->maker);
    $service->submit($payment, $test->maker);
    $service->approve($payment->fresh(), $test->checker);
    $service->disburse($payment->fresh(), $test->checker);

    return $service->post($payment->fresh(), $test->checker);
}

test('posted accrued expense creates one metadata obligation without another journal', function () {
    $expense = accruedExpenseForSettlement($this);
    $obligation = PayableObligation::query()->sole();

    expect($obligation->source_expense_transaction_id)->toBe($expense->id)
        ->and($obligation->source_journal_entry_id)->toBe($expense->journal_entry_id)
        ->and($obligation->original_amount)->toBe('3000.0000')
        ->and($obligation->outstandingAmount())->toBe('3000.0000')
        ->and($expense->fresh()->accruedPayableObligation?->id)->toBe($obligation->id)
        ->and($expense->journalEntry->lines)->toHaveCount(2)
        ->and($expense->journalEntry->lines[0]->account_id)->toBe($this->expenseLedger->id)
        ->and($expense->journalEntry->lines[1]->account_id)->toBe($this->payableLedger->id);
});

test('partial and full settlement debit the linked payable and never expense again', function () {
    accruedExpenseForSettlement($this);
    $obligation = PayableObligation::query()->sole();
    $first = postSettlement($this, settlementPayload($this, $obligation, '1000.0000', '123e4567-e89b-42d3-a456-426614174000'));

    expect($first->journalEntry->lines[0]->account_id)->toBe($this->payableLedger->id)
        ->and($first->journalEntry->lines[1]->account_id)->toBe($this->cashLedger->id)
        ->and($obligation->fresh()->outstandingAmount())->toBe('2000.0000');

    postSettlement($this, settlementPayload($this, $obligation->fresh(), '2000.0000', '123e4567-e89b-42d3-a456-426614174001'));
    expect($obligation->fresh()->outstandingAmount())->toBe('0.0000');
});

test('idempotency, active reservation and overpayment protections are enforced', function () {
    accruedExpenseForSettlement($this);
    $obligation = PayableObligation::query()->sole();
    $payload = settlementPayload($this, $obligation, '2000.0000', '123e4567-e89b-42d3-a456-426614174010');
    $first = app(OperationalTransactionService::class)->create($payload, $this->maker);
    $retry = app(OperationalTransactionService::class)->create($payload, $this->maker);
    expect($retry->id)->toBe($first->id);

    // Drafts are deliberately not reservations: a maker can abandon one without blocking settlement.
    $second = app(OperationalTransactionService::class)->create(settlementPayload($this, $obligation, '2000.0000', '123e4567-e89b-42d3-a456-426614174011'), $this->maker);
    app(OperationalTransactionService::class)->submit($first, $this->maker);
    expect(fn () => app(OperationalTransactionService::class)->submit($second, $this->maker))->toThrow(ValidationException::class);
    expect(fn () => app(OperationalTransactionService::class)->create(settlementPayload($this, $obligation, '3000.0001', '123e4567-e89b-42d3-a456-426614174012'), $this->maker))->toThrow(ValidationException::class);
});

test('reversed settlement reopens outstanding and source reversal is blocked while settlement remains active', function () {
    $expense = accruedExpenseForSettlement($this);
    $obligation = PayableObligation::query()->sole();
    $payment = postSettlement($this, settlementPayload($this, $obligation, '1000.0000', '123e4567-e89b-42d3-a456-426614174020'));
    $period = AccountingPeriod::query()->sole();
    $before = [
        'expense_status' => $expense->status,
        'payment_status' => $payment->status,
        'expense_journal_status' => $expense->journalEntry->status,
        'payment_journal_status' => $payment->journalEntry->status,
        'outstanding' => $obligation->outstandingAmount(),
        'journals' => JournalEntry::query()->count(),
        'lines' => JournalLine::query()->count(),
        'ledger_totals' => collect([$this->expenseLedger->id, $this->payableLedger->id, $this->cashLedger->id])->mapWithKeys(fn (int $accountId): array => [$accountId => [
            'debit' => JournalLine::query()->where('account_id', $accountId)->sum('debit'),
            'credit' => JournalLine::query()->where('account_id', $accountId)->sum('credit'),
        ]])->all(),
    ];

    $this->actingAs($this->maker)->get(route('transactions.show', $expense))
        ->assertOk()
        ->assertSee('Reverse linked payable settlements before reversing this expense.')
        ->assertDontSee('Create and post reversing journal?');
    expect(fn () => app(OperationalTransactionService::class)->reverse($expense->fresh(), $period, '2026-01-16', 'Invalid accrued expense', $this->checker))->toThrow(ValidationException::class);
    expect($expense->fresh()->status)->toBe($before['expense_status'])
        ->and($payment->fresh()->status)->toBe($before['payment_status'])
        ->and($expense->journalEntry->fresh()->status)->toBe($before['expense_journal_status'])
        ->and($payment->journalEntry->fresh()->status)->toBe($before['payment_journal_status'])
        ->and($obligation->fresh()->outstandingAmount())->toBe($before['outstanding'])
        ->and(JournalEntry::query()->count())->toBe($before['journals'])
        ->and(JournalLine::query()->count())->toBe($before['lines'])
        ->and(collect([$this->expenseLedger->id, $this->payableLedger->id, $this->cashLedger->id])->mapWithKeys(fn (int $accountId): array => [$accountId => [
            'debit' => JournalLine::query()->where('account_id', $accountId)->sum('debit'),
            'credit' => JournalLine::query()->where('account_id', $accountId)->sum('credit'),
        ]])->all())->toBe($before['ledger_totals']);

    app(OperationalTransactionService::class)->reverse($payment->fresh(), $period, '2026-01-16', 'Payment correction', $this->checker);
    expect($payment->fresh()->status)->toBe(OperationalTransactionStatus::Reversed)
        ->and($obligation->fresh()->outstandingAmount())->toBe('3000.0000');
    expect(app(OperationalTransactionService::class)->reverse($expense->fresh(), $period, '2026-01-16', 'Invalid accrued expense', $this->checker)->status)
        ->toBe(OperationalTransactionStatus::Reversed);
});

test('settlement screen is branch-protected and payables aging includes vendorless obligations', function () {
    $expense = accruedExpenseForSettlement($this);
    $obligation = PayableObligation::query()->sole();
    $this->actingAs($this->maker)->get(route('transactions.show', $expense))->assertOk()->assertSee('Settle payable');
    $this->get(route('reports.payables'))->assertOk()->assertSee('Unassigned vendor')->assertSee('Accrued expense #'.$obligation->source_expense_transaction_id);

    $other = User::factory()->create();
    $other->assignRole(SystemRole::Accountant->value);
    $other->branches()->attach(Branch::factory()->create(['status' => 'publish']));
    $this->actingAs($other)->get(route('payable-obligations.settle.create', $obligation))->assertForbidden();
});

test('payables screen and every export retain the accrued expense reference without changing totals', function () {
    $this->maker->givePermissionTo('finance_reports.export');
    $expense = accruedExpenseForSettlement($this);
    $expense->update(['reference_number' => 'P5-UAT-EXP-001 <script>alert(1)</script>']);
    $obligation = PayableObligation::query()->sole();
    $source = 'Accrued expense #'.$obligation->source_expense_transaction_id.' - P5-UAT-EXP-001 <script>alert(1)</script>';

    $this->actingAs($this->maker)->get(route('reports.payables', ['as_of' => '2026-01-31']))
        ->assertOk()
        ->assertSee($source)
        ->assertDontSee('<script>alert(1)</script>', false);

    $csvResponse = $this->get(route('reports.payables.export', ['format' => 'csv', 'as_of' => '2026-01-31']));
    $csvResponse->assertOk();
    $csv = $csvResponse->streamedContent();
    expect($csv)->toContain($source)->toContain('"Total outstanding",,,,,3000.0000');

    $xlsxResponse = $this->get(route('reports.payables.export', ['format' => 'xlsx', 'as_of' => '2026-01-31']));
    $xlsxResponse->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'payables').'.xlsx';
    file_put_contents($path, $xlsxResponse->streamedContent());
    $rows = collect(IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false))->flatten();
    unlink($path);
    expect($rows)->toContain($source);

    $pdfResponse = $this->get(route('reports.payables.export', ['format' => 'pdf', 'as_of' => '2026-01-31']));
    $pdfResponse->assertOk();
    $pdf = $pdfResponse->streamedContent();
    expect($pdf)->toStartWith('%PDF-1.4')
        ->and($pdf)->toContain('(Accrued expense #'.$obligation->source_expense_transaction_id.' -')
        ->toContain('(P5-UAT-EXP-001')
        ->toContain('(3,000.00)');
});

test('settle payable opens the unified payment screen on the remaining outstanding, and a vendorless payable is paid to a directory payee', function () {
    $expense = accruedExpenseForSettlement($this);
    $obligation = PayableObligation::query()->sole();
    postSettlement($this, settlementPayload($this, $obligation, '2000.0000', (string) str()->uuid()));
    $this->actingAs($this->maker)->get(route('transactions.show', $expense))->assertOk()
        ->assertSee('href="'.route('payments.create', ['obligation' => $obligation->id]).'"', false)->assertDontSee(route('payable-obligations.settle.create', $obligation));

    // Opening the screen saves nothing; it starts on the payable's branch, purpose, payable and remaining PKR 1,000.
    $transactions = OperationalTransaction::query()->count();
    $purposeId = PaymentPurpose::query()->where('code', 'accrued_payable')->value('id');
    $form = $this->get(route('payments.create', ['obligation' => $obligation->id]))->assertOk()->getContent();
    expect(OperationalTransaction::query()->count())->toBe($transactions)
        ->and($form)->toContain('data-prefill-obligation="'.$obligation->id.'"')->toContain('data-prefill-purpose="'.$purposeId.'"')
        ->toContain('name="amount" value="1000.0000"')->toContain('<option value="'.$this->branch->id.'" selected')
        ->toContain('payable_obligation_id')->not->toContain('name="payee_vendor_id" value="');

    // A payable outside the user's branches is ignored, not prefilled.
    $other = User::factory()->create();
    $other->assignRole(SystemRole::Accountant->value);
    $other->branches()->attach(Branch::factory()->create(['status' => 'publish']));
    $this->actingAs($other)->get(route('payments.create', ['obligation' => $obligation->id]))->assertOk()->assertSee('data-prefill-obligation=""', false)->assertDontSee('value="1000.0000"', false);
    $this->actingAs($other)->get(route('payable-obligations.settle.create', $obligation))->assertForbidden();

    // Submitted through the unified screen: a directory payee, at most the outstanding, against the payable ledger.
    $type = PayeeType::query()->create(['name' => 'Landlord', 'code' => 'landlord', 'source_kind' => 'directory', 'is_active' => true]);
    $payee = Payee::query()->create(['payee_type_id' => $type->id, 'name' => 'Gulshan Properties', 'entity_kind' => 'organization', 'is_active' => true]);
    $payload = ['payment_source' => 'cash', 'payment_purpose_id' => $purposeId, 'branch_id' => $this->branch->id, 'transaction_date' => '2026-01-20',
        'financial_account_id' => $this->cash->id, 'payable_obligation_id' => $obligation->id, 'payee_type_id' => $type->id, 'payee_id' => $payee->id,
        'settlement_request_token' => (string) str()->uuid(), 'narration' => 'Rent settlement'];
    $this->actingAs($this->maker)->post(route('payments.store'), [...$payload, 'amount' => '1000.01'])->assertSessionHasErrors();
    $this->post(route('payments.store'), [...$payload, 'amount' => '1000', 'payee_type_id' => null, 'payee_id' => null])->assertSessionHasErrors();
    $this->post(route('payments.store'), [...$payload, 'amount' => '1000'])->assertSessionHasNoErrors()->assertRedirect();
    $payment = OperationalTransaction::query()->latest('id')->firstOrFail();
    expect($payment->type)->toBe(OperationalTransactionType::CashPayment)->and($payment->payable_obligation_id)->toBe($obligation->id)
        ->and($payment->vendor_id)->toBeNull()->and($payment->contra_account_id)->toBe($this->payableLedger->id);
    expect(substr_count($this->get(route('payments.index'))->getContent(), 'data-payment-row="'.$payment->id.'"'))->toBe(1);

    // The payment detail names its payable and links the original accrued expense; an unlinked payment has no such row.
    $this->get(route('transactions.show', $payment))->assertOk()->assertSee('Linked payable')->assertSee('Payable #'.$obligation->id)
        ->assertSee('href="'.route('transactions.show', $expense).'"', false)->assertSee('#'.$expense->id.' · '.$expense->reference_number);
    $this->get(route('transactions.show', $expense))->assertOk()->assertDontSee('data-linked-payable', false);
});
