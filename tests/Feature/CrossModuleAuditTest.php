<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\PurchaseBillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DocumentSequence;
use App\Models\DonationCategory;
use App\Models\Employee;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\JournalEntry;
use App\Models\OperationalTransaction;
use App\Models\PayrollComponent;
use App\Models\PurchaseBill;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VoucherType;
use App\Services\DocumentNumberService;
use App\Services\Inventory\InventoryService;
use App\Services\JournalPostingService;
use App\Services\JournalReversalService;
use App\Services\JournalWorkflowService;
use App\Services\OpeningBalancePostingService;
use App\Services\OpeningBalanceService;
use App\Services\OperationalTransactionService;
use App\Services\Payroll\PayrollRunService;
use App\Services\PurchaseBillService;
use App\Services\VoucherNumberService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\DonationCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class, DonationCategorySeeder::class]);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Administrator->value);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $this->poster = User::factory()->create();
    $this->poster->assignRole(SystemRole::Administrator->value);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $this->period = AccountingPeriod::factory()->create(['fiscal_year_id' => $this->year, 'name' => 'January 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish']);
    $this->cashLedger = Account::factory()->create();
    $this->expenseLedger = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'purchase_bill_debit_eligible' => true, 'direct_payment_eligible' => true]);
    $this->payableLedger = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch, 'account_id' => $this->cashLedger]);
    $this->vendor = Vendor::factory()->create();
    $this->actingAs($this->maker);
});

function audit8User(SystemRole $role, Branch ...$branches): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    foreach ($branches as $branch) {
        $user->branches()->attach($branch);
    }

    return $user;
}

/** A posted purchase bill of 100.0000 in the test branch. */
function audit8PostedBill(object $test, string $number = 'AUDIT-1', ?Branch $branch = null): PurchaseBill
{
    $bills = app(PurchaseBillService::class);
    $bill = $bills->create([
        'vendor_id' => $test->vendor->id, 'branch_id' => ($branch ?? $test->branch)->id, 'payable_account_id' => $test->payableLedger->id,
        'bill_number' => $number, 'invoice_date' => '2026-01-10',
        'lines' => [['account_id' => $test->expenseLedger->id, 'description' => 'Supplies', 'quantity' => '1.0000', 'unit_amount' => '100.0000']],
    ], $test->maker);
    $bills->submit($bill, $test->maker);
    $bills->approve($bill->fresh(), $test->checker);

    return $bills->post($bill->fresh(), $test->checker);
}

function audit8VendorPayment(object $test, PurchaseBill $bill, string $amount): OperationalTransaction
{
    return app(OperationalTransactionService::class)->create([
        'type' => OperationalTransactionType::VendorPayment->value, 'branch_id' => $bill->branch_id, 'transaction_date' => '2026-01-15',
        'amount' => $amount, 'financial_account_id' => $test->cash->id, 'vendor_id' => $bill->vendor_id, 'purchase_bill_id' => $bill->id,
        'narration' => 'Audit vendor payment',
    ], $test->maker);
}

/** @return array<string, mixed> */
function audit8JournalPayload(object $test, Branch $branch): array
{
    return [
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-15',
        'fiscal_year_id' => $test->year->id, 'accounting_period_id' => $test->period->id, 'branch_id' => $branch->id,
        'narration' => 'Audit journal', 'reference_number' => 'AUDIT-JV',
        'lines' => [
            ['account_id' => $test->expenseLedger->id, 'debit' => '100.0000', 'credit' => '0'],
            ['account_id' => $test->payableLedger->id, 'debit' => '0', 'credit' => '100.0000'],
        ],
    ];
}

/** @return array<string, string> */
function audit8ReversalForm(object $test): array
{
    return ['accounting_period_id' => (string) $test->period->id, 'transaction_date' => '2026-01-20', 'reason' => 'Audit reversal attempt'];
}

// --- Journal engine -----------------------------------------------------------

test('a draft journal of an inaccessible branch cannot be edited by pointing it at an accessible branch', function () {
    $journal = app(JournalWorkflowService::class)->create(audit8JournalPayload($this, $this->otherBranch), $this->maker);
    $outsider = audit8User(SystemRole::Accountant, $this->branch);

    $this->actingAs($outsider)->put(route('journals.update', $journal), audit8JournalPayload($this, $this->branch))->assertForbidden();

    expect($journal->fresh()->branch_id)->toBe($this->otherBranch->id);
});

test('the user who submitted a journal cannot approve it', function () {
    $workflow = app(JournalWorkflowService::class);
    $journal = $workflow->create(audit8JournalPayload($this, $this->branch), $this->maker);
    $workflow->submit($journal, $this->checker);

    expect(fn () => $workflow->approve($journal->fresh(), $this->checker))->toThrow(ValidationException::class)
        ->and($journal->fresh()->status)->toBe(JournalStatus::Submitted);
});

test('a system journal can only be reversed through its source document', function () {
    $bill = audit8PostedBill($this);

    $this->post(route('journals.reverse', $bill->journal_entry_id), audit8ReversalForm($this))->assertSessionHasErrors('status');

    expect($bill->journalEntry->fresh()->status)->toBe(JournalStatus::Posted)
        ->and($bill->fresh()->status)->toBe(PurchaseBillStatus::Posted)
        ->and(JournalEntry::query()->where('reversal_of_id', $bill->journal_entry_id)->exists())->toBeFalse();
});

test('a reversing journal cannot itself be reversed from the journal screen', function () {
    $bills = app(PurchaseBillService::class);
    $bill = audit8PostedBill($this);
    $bills->reverse($bill, $this->period, '2026-01-20', 'Bill entered in error', $this->checker);
    $reversal = JournalEntry::query()->where('reversal_of_id', $bill->journal_entry_id)->firstOrFail();

    $this->post(route('journals.reverse', $reversal), audit8ReversalForm($this))->assertSessionHasErrors('status');

    expect($reversal->fresh()->status)->toBe(JournalStatus::Posted);
});

test('a manual journal is still reversed from the journal screen through request approval and posting', function () {
    $workflow = app(JournalWorkflowService::class);
    $journal = $workflow->create(audit8JournalPayload($this, $this->branch), $this->maker);
    $workflow->submit($journal, $this->maker);
    $workflow->approve($journal->fresh(), $this->checker);
    app(JournalPostingService::class)->post($journal->fresh(), $this->poster);

    $this->post(route('journals.reverse', $journal), [...audit8ReversalForm($this), 'reason' => 'Audit reversal of a manual journal'])->assertSessionHasNoErrors();
    $request = JournalEntry::query()->where('reversal_of_id', $journal->id)->sole();
    expect($journal->fresh()->status)->toBe(JournalStatus::Posted)->and($request->status)->toBe(JournalStatus::Submitted);

    $approver = User::factory()->create();
    $approver->assignRole(SystemRole::Approver->value);
    $approver->branches()->attach($this->branch);
    $poster = User::factory()->create();
    $poster->assignRole(SystemRole::Accountant->value);
    $poster->branches()->attach($this->branch);
    $reversals = app(JournalReversalService::class);
    $reversals->approve($request, $approver);
    $reversals->post($request->fresh(), $poster);

    expect($journal->fresh()->status)->toBe(JournalStatus::Reversed)->and($request->fresh()->status)->toBe(JournalStatus::Posted);
});

// --- Vendor payments and purchase bills ---------------------------------------

test('a rejected vendor payment cannot be resubmitted once the bill is covered by another payment', function () {
    $engine = app(OperationalTransactionService::class);
    $bill = audit8PostedBill($this);
    $first = audit8VendorPayment($this, $bill, '100.0000');
    $engine->submit($first, $this->maker);
    $engine->reject($first->fresh(), 'Wrong bank account', $this->checker);
    $second = audit8VendorPayment($this, $bill, '100.0000');

    expect(fn () => $engine->submit($first->fresh(), $this->maker))->toThrow(ValidationException::class)
        ->and($first->fresh()->status)->toBe(OperationalTransactionStatus::Rejected);

    $engine->submit($second, $this->maker);
    expect($second->fresh()->status)->toBe(OperationalTransactionStatus::Submitted);
});

test('a rejected vendor payment can be resubmitted while the bill is still outstanding', function () {
    $engine = app(OperationalTransactionService::class);
    $bill = audit8PostedBill($this);
    $payment = audit8VendorPayment($this, $bill, '100.0000');
    $engine->submit($payment, $this->maker);
    $engine->reject($payment->fresh(), 'Wrong bank account', $this->checker);

    $engine->submit($payment->fresh(), $this->maker);

    expect($payment->fresh()->status)->toBe(OperationalTransactionStatus::Submitted);
});

test('a vendor payment cannot proceed after its purchase bill was reversed', function () {
    $engine = app(OperationalTransactionService::class);
    $bill = audit8PostedBill($this);
    $payment = audit8VendorPayment($this, $bill, '100.0000');
    app(PurchaseBillService::class)->reverse($bill, $this->period, '2026-01-20', 'Bill entered in error', $this->checker);

    expect(fn () => $engine->submit($payment->fresh(), $this->maker))->toThrow(ValidationException::class)
        ->and($payment->fresh()->status)->toBe(OperationalTransactionStatus::Draft);
});

test('a purchase bill with an approved or disbursed payment cannot be reversed', function () {
    $engine = app(OperationalTransactionService::class);
    $bill = audit8PostedBill($this);
    $payment = audit8VendorPayment($this, $bill, '100.0000');
    $engine->submit($payment, $this->maker);
    $engine->approve($payment->fresh(), $this->checker);
    $engine->disburse($payment->fresh(), $this->checker);

    expect(fn () => app(PurchaseBillService::class)->reverse($bill->fresh(), $this->period, '2026-01-20', 'Bill entered in error', $this->checker))->toThrow(ValidationException::class)
        ->and($bill->fresh()->status)->toBe(PurchaseBillStatus::Posted)
        ->and($bill->journalEntry->fresh()->status)->toBe(JournalStatus::Posted);

    expect($engine->post($payment->fresh(), $this->checker)->status)->toBe(OperationalTransactionStatus::Posted);
});

test('a payment that is not a vendor payment cannot attach itself to a purchase bill', function () {
    $bill = audit8PostedBill($this, 'AUDIT-OTHER', $this->otherBranch);

    $payment = app(OperationalTransactionService::class)->create([
        'type' => OperationalTransactionType::CashPayment->value, 'branch_id' => $this->branch->id, 'transaction_date' => '2026-01-15',
        'amount' => '100.0000', 'financial_account_id' => $this->cash->id, 'contra_account_id' => $this->expenseLedger->id,
        'purchase_bill_id' => $bill->id, 'narration' => 'Unrelated cash payment',
    ], $this->maker);

    expect($payment->fresh()->purchase_bill_id)->toBeNull()
        ->and($bill->payments()->count())->toBe(0);
});

test('a split payment warning is audited once when the payment is created', function () {
    AppSetting::query()->where('key', 'approval.payment_threshold')->first()->update(['value' => '150.0000']);
    $bill = audit8PostedBill($this);
    app(PurchaseBillService::class);
    $base = ['type' => OperationalTransactionType::CashPayment->value, 'branch_id' => $this->branch->id, 'transaction_date' => '2026-01-15', 'party_name' => 'Same Payee', 'amount' => '100.0000', 'financial_account_id' => $this->cash->id, 'contra_account_id' => $this->expenseLedger->id, 'narration' => 'Split fixture'];
    app(OperationalTransactionService::class)->create($base, $this->maker);
    $second = app(OperationalTransactionService::class)->create($base, $this->maker);

    expect(AuditLog::query()->where('event', 'payments.split_warning')->where('auditable_id', $second->id)->count())->toBe(1)
        ->and($bill->exists)->toBeTrue();
});

test('updating a donation category without an income ledger is a validation outcome, not a server error', function () {
    $category = DonationCategory::query()->firstOrFail();

    $this->put(route('donation-categories.update', $category), ['is_active' => '1'])->assertRedirect()->assertSessionHasNoErrors();
});

// --- Payroll payments through the payment engine -------------------------------

test('a rejected salary payment cannot be resubmitted once the net salary is covered by another payment', function () {
    $salaryExpense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $salaryPayable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::SalaryExpense->value, 'account_id' => $salaryExpense->id, 'updated_by' => $this->maker->id]);
    AccountMapping::query()->create(['mapping_key' => AccountMappingKey::SalaryPayable->value, 'account_id' => $salaryPayable->id, 'updated_by' => $this->maker->id]);
    AppSetting::query()->where('key', 'payroll.calculation_policy_approved')->first()->update(['value' => '1']);
    $employee = Employee::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->maker->id]);
    $employee->salaryItems()->create(['payroll_component_id' => PayrollComponent::query()->where('code', 'BASIC')->value('id'), 'amount' => '50000.0000']);

    $payroll = app(PayrollRunService::class);
    $engine = app(OperationalTransactionService::class);
    $run = $payroll->validate($payroll->createFromStructures(['branch_id' => $this->branch->id, 'accounting_period_id' => $this->period->id], $this->maker), $this->maker);
    $payroll->submit($run, $this->maker);
    $payroll->approve($run->fresh(), $this->checker);
    $run = $payroll->post($run->fresh(), $this->maker);
    $data = ['type' => 'cash_payment', 'financial_account_id' => $this->cash->id, 'amount' => '50000.0000', 'transaction_date' => '2026-01-31'];

    $first = $payroll->pay($run, $data, $this->maker);
    $engine->submit($first, $this->maker);
    $engine->reject($first->fresh(), 'Wrong account chosen', $this->checker);
    $payroll->pay($run->fresh(), $data, $this->maker);

    expect(fn () => $engine->submit($first->fresh(), $this->maker))->toThrow(ValidationException::class)
        ->and($first->fresh()->status)->toBe(OperationalTransactionStatus::Rejected)
        ->and($payroll->outstandingAmount($run->fresh()))->toBe('0.0000');
});

// --- Opening balances -----------------------------------------------------------

test('the user who last changed an opening balance draft cannot approve it', function () {
    $opening = app(OpeningBalanceService::class);
    $lines = fn (string $amount): array => [
        ['account_id' => $this->cashLedger->id, 'debit' => $amount, 'credit' => '0'],
        ['account_id' => $this->payableLedger->id, 'debit' => '0', 'credit' => $amount],
    ];
    $header = ['fiscal_year_id' => $this->year->id, 'branch_id' => $this->branch->id, 'opening_date' => '2026-01-01'];
    $opening->saveDraft([...$header, 'lines' => $lines('100.00')], $this->maker);
    $batch = $opening->saveDraft([...$header, 'lines' => $lines('999.00')], $this->checker);

    expect(fn () => app(OpeningBalancePostingService::class)->approve($batch, $this->checker))->toThrow(ValidationException::class)
        ->and($batch->fresh()->status)->toBe('draft');
});

// --- List pages -----------------------------------------------------------------

test('the fixed asset list derives accumulated depreciation without a query per asset', function () {
    $count = function (int $assets): int {
        FixedAsset::factory()->count($assets)->active()->create(['branch_id' => $this->branch->id, 'created_by' => $this->maker->id]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('fixed-assets.index'))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $this->get(route('fixed-assets.index'))->assertOk();
    $few = $count(2);
    $many = $count(6);

    expect($many)->toBe($few);
});

test('the purchase bill list derives outstanding amounts without a query per bill', function () {
    $count = function (array $numbers): int {
        foreach ($numbers as $number) {
            audit8PostedBill($this, $number);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('purchase-bills.index'))->assertOk()->assertSee('100.00');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $this->get(route('purchase-bills.index'))->assertOk();
    $few = $count(['AUDIT-L1', 'AUDIT-L2']);
    $many = $count(['AUDIT-L3', 'AUDIT-L4', 'AUDIT-L5', 'AUDIT-L6']);

    expect($many)->toBe($few);
});

// --- Concurrency hardening (sequential regressions; the races are exercised by the MySQL harness) ---

test('an inventory movement submitted twice with one idempotency key is recorded once', function () {
    $item = InventoryItem::factory()->create();
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $inventory = app(InventoryService::class);
    $data = ['item_id' => $item->id, 'branch_id' => $this->branch->id, 'location_id' => $location->id, 'quantity' => '5.0000', 'transaction_date' => '2026-01-15', 'idempotency_key' => (string) Str::uuid()];

    $first = $inventory->receive($data, $this->maker);
    $second = $inventory->receive($data, $this->maker);
    $issue = [...$data, 'quantity' => '2.0000', 'issued_to' => 'Therapy room', 'idempotency_key' => (string) Str::uuid()];
    $inventory->issue($issue, $this->maker);
    $inventory->issue($issue, $this->maker);

    expect($second->id)->toBe($first->id)
        ->and(InventoryMovement::query()->count())->toBe(2)
        ->and($inventory->quantityOnHand($item, $this->branch))->toBe('3.0000');

    $inventory->receive([...$data, 'idempotency_key' => null], $this->maker);
    $inventory->receive([...$data, 'idempotency_key' => null], $this->maker);
    expect($inventory->quantityOnHand($item, $this->branch))->toBe('13.0000');
});

test('an inventory transfer submitted twice with one idempotency key moves stock once', function () {
    $item = InventoryItem::factory()->create();
    $from = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $to = InventoryLocation::factory()->create(['branch_id' => $this->otherBranch->id]);
    $inventory = app(InventoryService::class);
    $inventory->receive(['item_id' => $item->id, 'branch_id' => $this->branch->id, 'location_id' => $from->id, 'quantity' => '10.0000', 'transaction_date' => '2026-01-15'], $this->maker);
    $transfer = ['item_id' => $item->id, 'from_branch_id' => $this->branch->id, 'from_location_id' => $from->id, 'to_branch_id' => $this->otherBranch->id, 'to_location_id' => $to->id, 'quantity' => '4.0000', 'transaction_date' => '2026-01-15', 'idempotency_key' => (string) Str::uuid()];

    $first = $inventory->transfer($transfer, $this->maker);
    $second = $inventory->transfer($transfer, $this->maker);

    expect($second['out']->id)->toBe($first['out']->id)
        ->and($second['in']->id)->toBe($first['in']->id)
        ->and(InventoryMovement::query()->count())->toBe(3)
        ->and($inventory->quantityOnHand($item, $this->branch))->toBe('6.0000')
        ->and($inventory->quantityOnHand($item, $this->otherBranch))->toBe('4.0000');
});

test('the inventory forms carry an idempotency key and the request rejects a malformed one', function () {
    $this->get(route('inventory.receive.create'))->assertOk()->assertSee('name="idempotency_key"', false);
    $this->get(route('inventory.transfer.create'))->assertOk()->assertSee('name="idempotency_key"', false);

    $this->post(route('inventory.receive.store'), ['idempotency_key' => 'not-a-uuid'])->assertSessionHasErrors('idempotency_key');
});

test('a brand new number sequence issues consecutive numbers from one', function () {
    $documents = app(DocumentNumberService::class);
    $numbers = DB::transaction(fn (): array => [$documents->nextPurchaseOrderNumber(Carbon::parse('2031-03-01')), $documents->nextPurchaseOrderNumber(Carbon::parse('2031-03-02'))]);
    $voucherType = VoucherType::query()->where('code', 'JV')->firstOrFail();
    $vouchers = DB::transaction(fn (): array => [app(VoucherNumberService::class)->next($voucherType, $this->year), app(VoucherNumberService::class)->next($voucherType, $this->year)]);

    expect($numbers[0])->toEndWith('00001')->and($numbers[1])->toEndWith('00002')
        ->and($vouchers[0])->toEndWith('00001')->and($vouchers[1])->toEndWith('00002')
        ->and(DocumentSequence::query()->where('document_type', 'purchase_order')->where('scope_key', 'year:2031')->value('next_number'))->toBe(3);
});

test('bills against a purchase order are still limited by the order after the locking reads', function () {
    $order = PurchaseOrder::factory()->create(['branch_id' => $this->branch->id, 'vendor_id' => $this->vendor->id, 'status' => PurchaseOrderStatus::Approved, 'total_amount' => '150.0000', 'subtotal' => '150.0000', 'created_by' => $this->maker->id]);
    $bill = fn (string $number): array => [
        'vendor_id' => $this->vendor->id, 'branch_id' => $this->branch->id, 'payable_account_id' => $this->payableLedger->id, 'purchase_order_id' => $order->id,
        'bill_number' => $number, 'invoice_date' => '2026-01-10',
        'lines' => [['account_id' => $this->expenseLedger->id, 'description' => 'Supplies', 'quantity' => '1.0000', 'unit_amount' => '100.0000']],
    ];
    $bills = app(PurchaseBillService::class);

    expect($bills->create($bill('AUDIT-PO-1'), $this->maker)->po_exception_status)->toBeNull()
        ->and(fn () => $bills->create($bill('AUDIT-PO-2'), $this->maker))->toThrow(ValidationException::class)
        ->and($bills->create([...$bill('AUDIT-PO-3'), 'po_exception_reason' => 'Price increase agreed with vendor'], $this->maker)->po_exception_status)->toBe('pending');
});

test('several vendor payments cannot reserve more than the bill in total', function () {
    $bill = audit8PostedBill($this);
    audit8VendorPayment($this, $bill, '40.0000');
    audit8VendorPayment($this, $bill, '40.0000');

    expect(fn () => audit8VendorPayment($this, $bill, '40.0000'))->toThrow(ValidationException::class)
        ->and($bill->payments()->count())->toBe(2);
});
