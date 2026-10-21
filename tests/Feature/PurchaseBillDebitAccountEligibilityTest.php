<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\PurchaseBillStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PurchaseBillService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->maker = User::factory()->create();
    $this->maker->assignRole(SystemRole::Accountant->value);
    $this->maker->branches()->attach($this->branch);
    $this->checker = User::factory()->create();
    $this->checker->assignRole(SystemRole::Administrator->value);
    $this->checker->branches()->attach($this->branch);
    $this->vendor = Vendor::factory()->create();
    $year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    AccountingPeriod::factory()->create(['fiscal_year_id' => $year->id, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $this->expense = Account::factory()->create(['code' => '53101', 'account_type' => 'expense', 'financial_statement' => 'income_expenditure', 'purchase_bill_debit_eligible' => true]);
    $this->inventory = Account::factory()->create(['code' => '11501', 'account_type' => 'asset', 'financial_statement' => 'balance_sheet', 'purchase_bill_debit_eligible' => true]);
    $this->capitalAsset = Account::factory()->create(['code' => '14101', 'account_type' => 'asset', 'financial_statement' => 'balance_sheet', 'purchase_bill_debit_eligible' => true]);
    $this->cash = Account::factory()->create(['code' => '11102', 'financial_account_type' => 'cash', 'purchase_bill_debit_eligible' => false]);
    $this->bank = Account::factory()->create(['code' => '11201', 'financial_account_type' => 'bank', 'purchase_bill_debit_eligible' => false]);
    $this->receivable = Account::factory()->create(['code' => '11302', 'purchase_bill_debit_eligible' => false]);
    $this->accumulatedDepreciation = Account::factory()->create(['code' => '14902', 'account_type' => 'asset', 'normal_balance' => 'credit', 'purchase_bill_debit_eligible' => false]);
    $this->payable = Account::factory()->create(['code' => '21101', 'account_type' => 'liability', 'normal_balance' => 'credit']);
    FinancialAccount::factory()->create(['account_id' => $this->cash->id, 'branch_id' => $this->branch->id, 'type' => 'cash']);
});

function purchaseBillPayload(object $test, int $accountId, array $overrides = []): array
{
    return [
        'vendor_id' => $test->vendor->id,
        'branch_id' => $test->branch->id,
        'payable_account_id' => $test->payable->id,
        'bill_number' => 'PB-ELIGIBLE-'.str()->random(8),
        'invoice_date' => '2026-01-15',
        'due_date' => '2026-01-31',
        'lines' => [[
            'account_id' => $accountId,
            'description' => 'Isolated purchase line',
            'quantity' => '1.0000',
            'unit_amount' => '100.0000',
        ]],
        ...$overrides,
    ];
}

test('only explicitly eligible expense inventory and capital asset ledgers can be used for purchase bill debit lines', function () {
    $service = app(PurchaseBillService::class);

    foreach ([$this->expense, $this->inventory, $this->capitalAsset] as $account) {
        $bill = $service->create(purchaseBillPayload($this, $account->id), $this->maker);

        expect($bill->lines->sole()->account_id)->toBe($account->id);
    }

    foreach ([$this->cash, $this->bank, $this->receivable, $this->accumulatedDepreciation] as $account) {
        expect(fn () => $service->create(purchaseBillPayload($this, $account->id), $this->maker))
            ->toThrow(ValidationException::class);
    }
});

test('purchase bill form only renders explicitly eligible debit ledgers and forged account requests are rejected', function () {
    $this->actingAs($this->maker)
        ->get(route('purchase-bills.create'))
        ->assertOk()
        ->assertSee($this->expense->code)
        ->assertSee($this->inventory->code)
        ->assertSee($this->capitalAsset->code)
        ->assertDontSee($this->cash->code)
        ->assertDontSee($this->bank->code)
        ->assertDontSee($this->receivable->code)
        ->assertDontSee($this->accumulatedDepreciation->code);

    $payload = purchaseBillPayload($this, $this->cash->id, ['bill_number' => 'PB-FORGED-CASH']);
    $this->post(route('purchase-bills.store'), $payload)->assertSessionHasErrors('lines.account_id');
    $this->assertDatabaseMissing('purchase_bills', ['bill_number' => 'PB-FORGED-CASH']);
});

test('posting revalidates a purchase bill debit ledger and refuses an invalidated draft without creating a journal', function () {
    $service = app(PurchaseBillService::class);
    $bill = $service->create(purchaseBillPayload($this, $this->expense->id, ['bill_number' => 'PB-REVALIDATE']), $this->maker);
    $service->submit($bill, $this->maker);
    $service->approve($bill->fresh(), $this->checker);
    $this->expense->update(['purchase_bill_debit_eligible' => false]);

    expect(fn () => $service->post($bill->fresh(), $this->checker))
        ->toThrow(ValidationException::class)
        ->and($bill->fresh()->status)->toBe(PurchaseBillStatus::Approved)
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('account eligibility configuration is restricted to users with account update permission', function () {
    $unauthorized = User::factory()->create();

    $this->actingAs($unauthorized)
        ->put(route('accounts.update', $this->expense), [])
        ->assertForbidden();
});

test('authorized users can change referenced ledger eligibility without changing accounting classification', function () {
    $admin = User::factory()->create();
    $admin->assignRole(SystemRole::Administrator->value);
    $parent = Account::factory()->create([
        'code' => '50000',
        'account_type' => 'expense',
        'level' => 'class',
        'financial_statement' => 'income_expenditure',
        'is_postable' => false,
    ]);
    $group = Account::factory()->create([
        'code' => '53000',
        'account_type' => 'expense',
        'level' => 'group',
        'parent_id' => $parent->id,
        'financial_statement' => 'income_expenditure',
        'is_postable' => false,
    ]);
    $subGroup = Account::factory()->create([
        'code' => '53100',
        'account_type' => 'expense',
        'level' => 'sub_group',
        'parent_id' => $group->id,
        'financial_statement' => 'income_expenditure',
        'is_postable' => false,
    ]);
    $ledger = Account::factory()->create([
        'code' => '53102',
        'account_type' => 'expense',
        'parent_id' => $subGroup->id,
        'financial_statement' => 'income_expenditure',
        'purchase_bill_debit_eligible' => true,
    ]);

    $bill = app(PurchaseBillService::class)->create(purchaseBillPayload($this, $ledger->id, ['bill_number' => 'PB-REFERENCED-LEDGER']), $this->maker);
    app(PurchaseBillService::class)->submit($bill, $this->maker);
    app(PurchaseBillService::class)->approve($bill->fresh(), $this->checker);
    app(PurchaseBillService::class)->post($bill->fresh(), $this->checker);

    $payload = [
        'code' => $ledger->code,
        'name' => $ledger->name,
        'parent_id' => $subGroup->id,
        'account_type' => 'expense',
        'level' => 'ledger',
        'normal_balance' => 'debit',
        'financial_statement' => 'income_expenditure',
        'financial_account_type' => null,
        'purchase_bill_debit_eligible' => false,
        'is_postable' => true,
        'is_active' => true,
        'sort_order' => 0,
    ];
    $this->actingAs($admin)->put(route('accounts.update', $ledger), $payload)->assertRedirect();

    $audit = AuditLog::query()->where('event', 'account.updated')->where('auditable_id', $ledger->id)->latest('id')->firstOrFail();
    expect($ledger->fresh()->purchase_bill_debit_eligible)->toBeFalse()
        ->and($audit->properties['before']['purchase_bill_debit_eligible'])->toBeTrue()
        ->and($audit->properties['after']['purchase_bill_debit_eligible'])->toBeFalse();

    $this->actingAs($admin)->put(route('accounts.update', $ledger), [...$payload, 'normal_balance' => 'credit'])
        ->assertSessionHasErrors('account');
    expect($ledger->fresh()->normal_balance->value)->toBe('debit');
});
