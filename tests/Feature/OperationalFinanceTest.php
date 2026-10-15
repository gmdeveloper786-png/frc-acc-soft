<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DonationCategory;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\Vendor;
use App\Services\OperationalTransactionService;
use App\Services\PurchaseBillService;
use App\Support\AppSettings;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\DonationCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class, DonationCategorySeeder::class]);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->cashLedger = Account::factory()->create(['code' => '111101']);
    $this->bankLedger = Account::factory()->create(['code' => '111102']);
    $this->expenseLedger = Account::factory()->create(['code' => '511101', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->incomeLedger = Account::factory()->create(['code' => '411101', 'account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->payableLedger = Account::factory()->create(['code' => '211101', 'account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch, 'account_id' => $this->cashLedger]);
    $this->bank = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => $this->branch, 'account_id' => $this->bankLedger]);
    $this->actingAs($this->maker);
});

function operationalPayload(object $test, OperationalTransactionType $type, string $amount = '100.0000'): array
{
    return ['type' => $type->value, 'branch_id' => $test->branch->id, 'transaction_date' => '2026-01-15', 'party_name' => 'Test party', 'amount' => $amount, 'financial_account_id' => $test->cash->id, 'contra_account_id' => $test->expenseLedger->id, 'narration' => 'Isolated finance fixture'];
}

test('cash receipt posts exact balanced debit and credit once', function () {
    $data = operationalPayload($this, OperationalTransactionType::CashReceipt, '123.4567');
    $data['contra_account_id'] = $this->incomeLedger->id;
    $transaction = app(OperationalTransactionService::class)->create($data, $this->maker);
    $posted = app(OperationalTransactionService::class)->post($transaction, $this->maker);

    expect($posted->status)->toBe(OperationalTransactionStatus::Posted)
        ->and($posted->journalEntry->total_debit)->toBe('123.4567')
        ->and($posted->journalEntry->lines[0]->account_id)->toBe($this->cashLedger->id)
        ->and($posted->journalEntry->lines[1]->account_id)->toBe($this->incomeLedger->id);
    expect(fn () => app(OperationalTransactionService::class)->post($posted, $this->maker))->toThrow(ValidationException::class);
});

test('payment threshold maker checker and disbursement are enforced', function () {
    AppSetting::query()->where('key', 'approval.payment_threshold')->update(['value' => '150000.0000']);
    Cache::forget(AppSettings::cacheKey('approval.payment_threshold'));
    $payment = app(OperationalTransactionService::class)->create(operationalPayload($this, OperationalTransactionType::CashPayment, '150000.0000'), $this->maker);
    app(OperationalTransactionService::class)->submit($payment, $this->maker);
    expect(fn () => app(OperationalTransactionService::class)->approve($payment->fresh(), $this->maker))->toThrow(ValidationException::class);
    $this->actingAs($this->checker);
    app(OperationalTransactionService::class)->approve($payment->fresh(), $this->checker);
    expect(fn () => app(OperationalTransactionService::class)->post($payment->fresh(), $this->checker))->toThrow(ValidationException::class);
    app(OperationalTransactionService::class)->disburse($payment->fresh(), $this->checker);
    $posted = app(OperationalTransactionService::class)->post($payment->fresh(), $this->checker);
    expect($posted->status)->toBe(OperationalTransactionStatus::Posted)->and($posted->approval_tier)->toBe('standard');

    $high = app(OperationalTransactionService::class)->create(operationalPayload($this, OperationalTransactionType::CashPayment, '150000.0001'), $this->checker);
    expect($high->approval_tier)->toBe('director');
});

test('paid and accrued expenses use distinct credit mappings', function () {
    foreach (['paid' => $this->cashLedger->id, 'accrued' => $this->payableLedger->id] as $settlement => $expectedCredit) {
        $this->actingAs($this->maker);
        $data = operationalPayload($this, OperationalTransactionType::Expense);
        $data['settlement_type'] = $settlement;
        $data['payable_account_id'] = $this->payableLedger->id;
        if ($settlement === 'accrued') {
            $data['financial_account_id'] = null;
        }
        $expense = app(OperationalTransactionService::class)->create($data, $this->maker);
        app(OperationalTransactionService::class)->submit($expense, $this->maker);
        $this->actingAs($this->checker);
        app(OperationalTransactionService::class)->approve($expense->fresh(), $this->checker);
        if ($settlement === 'paid') {
            app(OperationalTransactionService::class)->disburse($expense->fresh(), $this->checker);
        }
        $posted = app(OperationalTransactionService::class)->post($expense->fresh(), $this->checker);
        expect($posted->journalEntry->lines[1]->account_id)->toBe($expectedCredit);
    }
});

test('purchase bill posts payable supports partial payment and blocks overpayment', function () {
    $vendor = Vendor::factory()->create();
    $bill = app(PurchaseBillService::class)->create([
        'vendor_id' => $vendor->id, 'branch_id' => $this->branch->id, 'payable_account_id' => $this->payableLedger->id,
        'bill_number' => 'INV-TEST-1', 'invoice_date' => '2026-01-10', 'due_date' => '2026-01-31',
        'description' => 'Test payable', 'lines' => [['account_id' => $this->expenseLedger->id, 'description' => 'Supplies', 'quantity' => '2.0000', 'unit_amount' => '50.0000']],
    ], $this->maker);
    app(PurchaseBillService::class)->submit($bill, $this->maker);
    $this->actingAs($this->checker);
    app(PurchaseBillService::class)->approve($bill->fresh(), $this->checker);
    $bill = app(PurchaseBillService::class)->post($bill->fresh(), $this->checker);
    expect($bill->total_amount)->toBe('100.0000')->and($bill->outstandingAmount())->toBe('100.0000');

    $paymentData = operationalPayload($this, OperationalTransactionType::VendorPayment, '40.0000');
    $paymentData['financial_account_id'] = $this->bank->id;
    $paymentData['purchase_bill_id'] = $bill->id;
    unset($paymentData['contra_account_id']);
    $payment = app(OperationalTransactionService::class)->create($paymentData, $this->checker);
    expect($payment->amount)->toBe('40.0000');
    expect(fn () => app(OperationalTransactionService::class)->create([...$paymentData, 'amount' => '61.0000'], $this->checker))->toThrow(ValidationException::class);
});

test('petty cash allows no vendor and cross branch access is rejected', function () {
    $petty = app(OperationalTransactionService::class)->create(operationalPayload($this, OperationalTransactionType::PettyCash), $this->maker);
    expect($petty->vendor_id)->toBeNull();
    $restricted = User::factory()->create();
    $restricted->assignRole(SystemRole::Cashier->value);
    $restricted->branches()->attach(Branch::factory()->create());
    $this->actingAs($restricted)->get(route('transactions.show', $petty))->assertForbidden();
});

test('closed period blocks operational posting and audit contains no secret data', function () {
    $data = operationalPayload($this, OperationalTransactionType::CashReceipt);
    $data['contra_account_id'] = $this->incomeLedger->id;
    $receipt = app(OperationalTransactionService::class)->create($data, $this->maker);
    $this->period->update(['status' => AccountingPeriodStatus::Closed]);
    expect(fn () => app(OperationalTransactionService::class)->post($receipt, $this->maker))->toThrow(ValidationException::class);
    expect(AuditLog::query()->where('event', 'receipts.created')->exists())->toBeTrue()
        ->and(json_encode(AuditLog::query()->latest('id')->first()->properties))->not->toContain('token');
});

test('cash and bank account type validation prevents unsafe posting mapping', function () {
    $data = operationalPayload($this, OperationalTransactionType::BankReceipt);
    $data['contra_account_id'] = $this->incomeLedger->id;
    expect(fn () => app(OperationalTransactionService::class)->create($data, $this->maker))->toThrow(ValidationException::class);

    $protected = FinancialAccount::factory()->create(['branch_id' => $this->branch, 'account_id' => Account::factory(), 'account_number' => 'PK00SECRET1234', 'account_last_four' => '1234']);
    expect($protected->fresh()->getRawOriginal('account_number'))->not->toBe('PK00SECRET1234')
        ->and($protected->fresh()->maskedNumber())->toEndWith('1234');
});

test('only director permission approves payment above configured threshold', function () {
    $payment = app(OperationalTransactionService::class)->create(operationalPayload($this, OperationalTransactionType::CashPayment, '150000.0001'), $this->maker);
    app(OperationalTransactionService::class)->submit($payment, $this->maker);
    $approver = User::factory()->create();
    $approver->assignRole(SystemRole::Approver->value);
    $this->actingAs($approver);
    expect(fn () => app(OperationalTransactionService::class)->approve($payment->fresh(), $approver))->toThrow(HttpException::class);
    $director = User::factory()->create();
    $director->assignRole(SystemRole::Director->value);
    $director->branches()->attach($this->branch);
    $this->actingAs($director);
    expect(app(OperationalTransactionService::class)->approve($payment->fresh(), $director)->status)->toBe(OperationalTransactionStatus::Approved);
});

test('donation requires explicit category account mapping and posts donation receipt', function () {
    $category = DonationCategory::query()->firstOrFail();
    $data = operationalPayload($this, OperationalTransactionType::Donation);
    $data['donation_category_id'] = $category->id;
    unset($data['contra_account_id']);
    $donation = app(OperationalTransactionService::class)->create($data, $this->maker);
    expect(fn () => app(OperationalTransactionService::class)->post($donation, $this->maker))->toThrow(ValidationException::class);
    $category->update(['income_account_id' => $this->incomeLedger->id]);
    $posted = app(OperationalTransactionService::class)->post($donation->fresh(), $this->maker);
    expect($posted->journalEntry->voucherType->code)->toBe('DR');
});

test('cash book includes posted activity and excludes drafts', function () {
    $data = operationalPayload($this, OperationalTransactionType::CashReceipt);
    $data['contra_account_id'] = $this->incomeLedger->id;
    $posted = app(OperationalTransactionService::class)->create($data, $this->maker);
    app(OperationalTransactionService::class)->post($posted, $this->maker);
    app(OperationalTransactionService::class)->create([...$data, 'reference_number' => 'DRAFT'], $this->maker);

    $this->get(route('reports.books', 'cash'))->assertOk()->assertSee($posted->fresh()->journalEntry->voucher_number)->assertDontSee('DRAFT');
});

test('a posted cash or bank receipt has a printable receipt limited to its branch', function () {
    $data = [...operationalPayload($this, OperationalTransactionType::CashReceipt, '2500.0000'), 'contra_account_id' => $this->incomeLedger->id, 'party_name' => 'Synthetic Client'];
    $draft = app(OperationalTransactionService::class)->create($data, $this->maker);
    $this->get(route('transactions.acknowledgment', $draft))->assertNotFound();

    $posted = app(OperationalTransactionService::class)->post($draft, $this->maker);
    $this->get(route('transactions.show', $posted))->assertOk()->assertSee('Print receipt');
    $this->get(route('transactions.acknowledgment', $posted))->assertOk()
        ->assertSee('Cash Receipt')->assertSee($posted->journalEntry->voucher_number)->assertSee('Received from')->assertSee('Synthetic Client')->assertSee('2,500.00');

    $expense = app(OperationalTransactionService::class)->create(operationalPayload($this, OperationalTransactionType::Expense), $this->maker);
    $this->get(route('transactions.acknowledgment', $expense))->assertNotFound();

    $otherBranchUser = User::factory()->create();
    $otherBranchUser->assignRole(SystemRole::Accountant->value);
    $otherBranchUser->branches()->attach(Branch::factory()->create(['status' => 'publish']));
    $this->actingAs($otherBranchUser)->get(route('transactions.acknowledgment', $posted))->assertForbidden();
});
