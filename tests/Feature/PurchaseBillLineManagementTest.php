<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Fund;
use App\Models\PurchaseBill;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseBillService;
use App\Services\PurchaseOrderService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);

    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Accountant->value);
    $this->maker->branches()->attach($this->branch);

    $this->approver = User::factory()->create();
    $this->approver->assignRole(SystemRole::Approver->value);
    $this->approver->branches()->attach($this->branch);

    $this->vendor = Vendor::factory()->create();
    $this->expense = Account::factory()->create([
        'code' => '53101',
        'account_type' => 'expense',
        'financial_statement' => 'income_expenditure',
        'purchase_bill_debit_eligible' => true,
    ]);
    $this->expenseSecondary = Account::factory()->create([
        'code' => '53102',
        'account_type' => 'expense',
        'financial_statement' => 'income_expenditure',
        'purchase_bill_debit_eligible' => true,
    ]);
    $this->payable = Account::factory()->create([
        'code' => '21101',
        'account_type' => 'liability',
        'normal_balance' => 'credit',
    ]);

    $year = FiscalYear::factory()->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => FiscalYearStatus::Open,
        'is_current' => true,
    ]);
    AccountingPeriod::factory()->create([
        'fiscal_year_id' => $year->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => AccountingPeriodStatus::Open,
    ]);

    $this->actingAs($this->maker);
});

test('purchase bill create form renders remove line button and dynamic template matching purchase order UI style', function () {
    $response = $this->get(route('purchase-bills.create'));

    $response->assertOk()
        ->assertSee('Bill lines')
        ->assertSee('data-add-bill-line', false)
        ->assertSee('+ Add line')
        ->assertSee('data-bill-line', false)
        ->assertSee('data-remove-bill-line', false)
        ->assertSee('aria-label="Remove line"', false)
        ->assertSee('btn-outline-danger', false)
        ->assertSee('×')
        ->assertSee('id="billLineTemplate"', false)
        ->assertSee('initBillLines', false);
});

test('purchase bill create pre-fills lines from an approved purchase order with PO line association and remove button', function () {
    $poService = app(PurchaseOrderService::class);
    $order = $poService->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'po_date' => '2026-01-10',
        'lines' => [
            ['description' => 'A4 Paper Ream - Office Stationery', 'quantity' => '5.0000', 'unit' => 'pcs', 'unit_price' => '200.0000', 'account_id' => $this->expense->id],
            ['description' => 'Ballpoint Pens Box', 'quantity' => '10.0000', 'unit' => 'pcs', 'unit_price' => '50.0000', 'account_id' => $this->expenseSecondary->id],
        ],
    ], $this->maker);

    $poService->submit($order, $this->maker);
    $poService->approve($order->fresh(), $this->approver);

    $response = $this->get(route('purchase-bills.create', ['purchase_order' => $order->id]));

    $response->assertOk()
        ->assertSee('Billing against '.$order->po_number)
        ->assertSee('A4 Paper Ream - Office Stationery')
        ->assertSee('Ballpoint Pens Box')
        ->assertSee('PO remaining 5.0000')
        ->assertSee('PO remaining 10.0000')
        ->assertSee('data-remove-bill-line', false)
        ->assertSee('lines[0][purchase_order_line_id]', false)
        ->assertSee('lines[1][purchase_order_line_id]', false);
});

test('submitting a purchase bill requires at least one line and rejects empty lines submission', function () {
    $payload = [
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => 'PB-EMPTY-LINES',
        'invoice_date' => '2026-01-15',
        'lines' => [],
    ];

    $this->post(route('purchase-bills.store'), $payload)
        ->assertSessionHasErrors('lines');

    $this->assertDatabaseMissing('purchase_bills', ['bill_number' => 'PB-EMPTY-LINES']);
});

test('removing a line and submitting only the remaining line reindexes lines and creates bill with correct PO association', function () {
    $poService = app(PurchaseOrderService::class);
    $order = $poService->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'po_date' => '2026-01-10',
        'lines' => [
            ['description' => 'A4 Paper Ream - Office Stationery', 'quantity' => '5.0000', 'unit' => 'pcs', 'unit_price' => '200.0000', 'account_id' => $this->expense->id],
            ['description' => 'Ballpoint Pens Box', 'quantity' => '10.0000', 'unit' => 'pcs', 'unit_price' => '50.0000', 'account_id' => $this->expenseSecondary->id],
        ],
    ], $this->maker);

    $poService->submit($order, $this->maker);
    $poService->approve($order->fresh(), $this->approver);

    $line1 = $order->lines()->where('description', 'A4 Paper Ream - Office Stationery')->firstOrFail();
    $line2 = $order->lines()->where('description', 'Ballpoint Pens Box')->firstOrFail();

    // User removed Line 1 in the UI; only Line 2 is submitted as lines[0]
    $payload = [
        'purchase_order_id' => $order->id,
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => 'PB-REMOVED-LINE-01',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'purchase_order_line_id' => $line2->id,
                'account_id' => $this->expenseSecondary->id,
                'description' => 'Ballpoint Pens Box',
                'quantity' => '10.0000',
                'unit_amount' => '50.0000',
            ],
        ],
    ];

    $response = $this->post(route('purchase-bills.store'), $payload);
    $response->assertSessionHasNoErrors();

    $bill = PurchaseBill::query()->where('bill_number', 'PB-REMOVED-LINE-01')->firstOrFail();
    $response->assertRedirect(route('purchase-bills.show', $bill));

    expect($bill->total_amount)->toBe('500.0000')
        ->and($bill->lines)->toHaveCount(1)
        ->and($bill->lines->first()->purchase_order_line_id)->toBe($line2->id)
        ->and($bill->lines->first()->line_number)->toBe(1)
        ->and($bill->lines->first()->description)->toBe('Ballpoint Pens Box')
        ->and($bill->lines->first()->line_total)->toBe('500.0000');

    // Line 1 remains completely unbilled on the purchase order
    expect($line1->fresh()->remainingQuantity())->toBe('5.0000')
        ->and($line2->fresh()->remainingQuantity())->toBe('0.0000');
});

test('validation errors preserve remaining lines in old input without restoring removed lines', function () {
    $payload = [
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => '', // Intentionally missing to trigger validation error
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => $this->expenseSecondary->id,
                'description' => 'Remaining Line After Delete',
                'quantity' => '3.0000',
                'unit_amount' => '150.0000',
            ],
        ],
    ];

    $response = $this->from(route('purchase-bills.create'))->post(route('purchase-bills.store'), $payload);
    $response->assertRedirect(route('purchase-bills.create'));
    $response->assertSessionHasErrors('bill_number');

    // Follow redirect to create form with flashed old input
    $followUp = $this->get(route('purchase-bills.create'));
    $followUp->assertOk()
        ->assertSee('Remaining Line After Delete')
        ->assertSee('150.0000')
        ->assertDontSee('Original Deleted Line');
});

test('purchase bill create form displays disabled placeholder option and does not auto-select first ledger', function () {
    $capitalAsset = Account::factory()->create([
        'code' => '12201',
        'name' => 'Furniture & Fixtures',
        'account_type' => 'asset',
        'financial_statement' => 'balance_sheet',
        'purchase_bill_debit_eligible' => true,
    ]);

    $response = $this->get(route('purchase-bills.create'));

    $response->assertOk()
        ->assertSee('Select expense / asset ledger')
        ->assertSee('disabled', false)
        ->assertSee('None / Unassigned (Auto GEN for expenses)');

    // Ensure the placeholder is marked disabled and selected by default when line account_id is empty
    expect($response->getContent())
        ->toContain('<option value="" disabled selected>Select expense / asset ledger</option>');
});

test('submitting a purchase bill with unselected or empty account_id fails validation', function () {
    $payload = [
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => 'PB-EMPTY-ACCOUNT',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => '',
                'description' => 'Office Chairs without account',
                'quantity' => '2.0000',
                'unit_amount' => '5000.0000',
            ],
        ],
    ];

    $response = $this->post(route('purchase-bills.store'), $payload);

    $response->assertSessionHasErrors(['lines.0.account_id']);
    $this->assertDatabaseMissing('purchase_bills', ['bill_number' => 'PB-EMPTY-ACCOUNT']);
});

test('purchase bill with explicit expense ledger succeeds and auto-defaults to GEN fund when unassigned', function () {
    Fund::query()->firstOrCreate(
        ['code' => 'GEN'],
        ['name' => 'General Fund', 'type' => Fund::TYPE_UNRESTRICTED, 'category' => 'general', 'is_active' => true]
    );

    $payload = [
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => 'PB-AUTO-GEN-EXP',
        'invoice_date' => '2026-01-15',
        'fund_id' => '',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'Stationery Supplies',
                'quantity' => '5.0000',
                'unit_amount' => '100.0000',
            ],
        ],
    ];

    $response = $this->post(route('purchase-bills.store'), $payload);
    $response->assertSessionHasNoErrors();

    $bill = PurchaseBill::query()->where('bill_number', 'PB-AUTO-GEN-EXP')->firstOrFail();
    expect($bill->fund)->not->toBeNull()
        ->and($bill->fund->code)->toBe('GEN')
        ->and($bill->lines->first()->account_id)->toBe($this->expense->id);
});

test('purchase bill with capital asset ledger without fund preserves unassigned null fund', function () {
    Fund::query()->firstOrCreate(
        ['code' => 'GEN'],
        ['name' => 'General Fund', 'type' => Fund::TYPE_UNRESTRICTED, 'category' => 'general', 'is_active' => true]
    );

    $capitalAsset = Account::factory()->create([
        'code' => '12201',
        'name' => 'Office Equipment',
        'account_type' => 'asset',
        'financial_statement' => 'balance_sheet',
        'purchase_bill_debit_eligible' => true,
    ]);

    $payload = [
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => 'PB-ASSET-UNASSIGNED',
        'invoice_date' => '2026-01-15',
        'fund_id' => null,
        'lines' => [
            [
                'account_id' => $capitalAsset->id,
                'description' => 'Office Furniture Asset',
                'quantity' => '1.0000',
                'unit_amount' => '25000.0000',
            ],
        ],
    ];

    $response = $this->post(route('purchase-bills.store'), $payload);
    $response->assertSessionHasNoErrors();

    $bill = PurchaseBill::query()->where('bill_number', 'PB-ASSET-UNASSIGNED')->firstOrFail();
    expect($bill->fund_id)->toBeNull()
        ->and($bill->lines->first()->fund_id)->toBeNull()
        ->and($bill->lines->first()->account_id)->toBe($capitalAsset->id);
});

test('editing a draft purchase bill pre-selects the existing line ledger and allows updating', function () {
    $service = app(PurchaseBillService::class);
    $bill = $service->create([
        'vendor_id' => $this->vendor->id,
        'branch_id' => $this->branch->id,
        'payable_account_id' => $this->payable->id,
        'bill_number' => 'PB-EDIT-PRESERVE',
        'invoice_date' => '2026-01-15',
        'lines' => [
            [
                'account_id' => $this->expense->id,
                'description' => 'Existing Line Item',
                'quantity' => '2.0000',
                'unit_amount' => '300.0000',
            ],
        ],
    ], $this->maker);

    $response = $this->get(route('purchase-bills.edit', $bill));

    $response->assertOk()
        ->assertSee('value="'.$this->expense->id.'" selected', false)
        ->assertSee('Existing Line Item');
});
