<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\AccountMappingKey;
use App\Enums\FiscalYearStatus;
use App\Enums\OperationalTransactionType;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\FrcService;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AccountingReadinessService;
use App\Services\AccountMappingService;
use App\Services\OperationalTransactionService;
use App\Services\PurchaseBillService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\DonationCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class, DonationCategorySeeder::class]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->payable = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $this->income = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $this->expense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->cashLedger = Account::factory()->create();
    $this->cash = FinancialAccount::factory()->create(['type' => 'cash', 'branch_id' => $this->branch, 'account_id' => $this->cashLedger]);
    $this->actingAs($this->admin);
});

function openJanuaryPeriod(): AccountingPeriod
{
    $year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);

    return AccountingPeriod::factory()->create(['fiscal_year_id' => $year, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
}

test('a compatible active postable ledger can be mapped and the change is audited', function () {
    $mapping = app(AccountMappingService::class)->set(AccountMappingKey::AccountsPayable, $this->payable->id, $this->admin);

    expect($mapping->account_id)->toBe($this->payable->id)
        ->and(app(AccountMappingService::class)->resolve(AccountMappingKey::AccountsPayable)->id)->toBe($this->payable->id)
        ->and(AuditLog::query()->where('event', 'account_mappings.updated')->where('auditable_id', $mapping->id)->exists())->toBeTrue();
});

test('wrong account classification is rejected', function (AccountMappingKey $key, string $ledger) {
    expect(fn () => app(AccountMappingService::class)->set($key, $this->{$ledger}->id, $this->admin))->toThrow(ValidationException::class);
    expect(AccountMapping::query()->count())->toBe(0);
})->with([
    'payable to an asset' => [AccountMappingKey::AccountsPayable, 'cashLedger'],
    'fee income to a liability' => [AccountMappingKey::FeeIncomeGeneral, 'payable'],
    'receivable to an income ledger' => [AccountMappingKey::AccountsReceivable, 'income'],
    'expense to an income ledger' => [AccountMappingKey::DefaultExpense, 'income'],
]);

test('inactive and non postable accounts are rejected', function () {
    $inactive = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit', 'is_active' => false]);
    $group = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 'sub_group', 'is_postable' => false]);

    expect(fn () => app(AccountMappingService::class)->set(AccountMappingKey::AccountsPayable, $inactive->id, $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => app(AccountMappingService::class)->set(AccountMappingKey::AccountsPayable, $group->id, $this->admin))->toThrow(ValidationException::class);
});

test('one ledger cannot serve two control mappings or duplicate a cash ledger', function () {
    $service = app(AccountMappingService::class);
    $service->set(AccountMappingKey::AccountsPayable, $this->payable->id, $this->admin);

    expect(fn () => $service->set(AccountMappingKey::PurchaseBillControl, $this->payable->id, $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $service->set(AccountMappingKey::AccountsReceivable, $this->cashLedger->id, $this->admin))->toThrow(ValidationException::class);
});

test('mappings stay dynamic and a ledger deactivated later blocks resolution', function () {
    $service = app(AccountMappingService::class);
    $second = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);
    $service->set(AccountMappingKey::AccountsPayable, $this->payable->id, $this->admin);
    $service->set(AccountMappingKey::AccountsPayable, $second->id, $this->admin);
    expect($service->resolve(AccountMappingKey::AccountsPayable)->id)->toBe($second->id)
        ->and(AccountMapping::query()->count())->toBe(1);

    $second->update(['is_active' => false]);
    expect(fn () => $service->resolve(AccountMappingKey::AccountsPayable))->toThrow(ValidationException::class);
});

test('service fee income falls back to the general mapping', function () {
    $service = app(AccountMappingService::class);
    $own = Account::factory()->create(['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);
    $frcService = FrcService::factory()->create(['status' => 'publish']);
    $service->set(AccountMappingKey::FeeIncomeGeneral, $this->income->id, $this->admin);
    expect($service->feeIncomeFor($frcService->id)->id)->toBe($this->income->id);

    $service->setServiceRevenue($frcService, $own->id, $this->admin);
    expect($service->feeIncomeFor($frcService->id)->id)->toBe($own->id)
        ->and(fn () => $service->setServiceRevenue($frcService, $this->payable->id, $this->admin))->toThrow(ValidationException::class);
});

test('missing required mapping prevents posting with a configuration error', function () {
    openJanuaryPeriod();
    $receipt = ['type' => OperationalTransactionType::CashReceipt->value, 'branch_id' => $this->branch->id, 'transaction_date' => '2026-01-15', 'amount' => '500.0000', 'financial_account_id' => $this->cash->id, 'narration' => 'Fee receipt fixture'];

    try {
        app(OperationalTransactionService::class)->create($receipt, $this->admin);
        $this->fail('A receipt without a fee income mapping must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['mapping'][0])->toContain('Configuration error')->toContain('General Fee Income');
    }

    $bill = ['vendor_id' => Vendor::factory()->create()->id, 'branch_id' => $this->branch->id, 'bill_number' => 'INV-MAP-1', 'invoice_date' => '2026-01-10', 'lines' => [['account_id' => $this->expense->id, 'description' => 'Supplies', 'quantity' => '1.0000', 'unit_amount' => '50.0000']]];
    expect(fn () => app(PurchaseBillService::class)->create($bill, $this->admin))->toThrow(ValidationException::class)
        ->and(JournalEntry::query()->count())->toBe(0);

    app(AccountMappingService::class)->set(AccountMappingKey::FeeIncomeGeneral, $this->income->id, $this->admin);
    app(AccountMappingService::class)->set(AccountMappingKey::AccountsPayable, $this->payable->id, $this->admin);
    $posted = app(OperationalTransactionService::class)->post(app(OperationalTransactionService::class)->create($receipt, $this->admin), $this->admin);

    expect($posted->journalEntry->lines[1]->account_id)->toBe($this->income->id)
        ->and(app(PurchaseBillService::class)->create($bill, $this->admin)->payable_account_id)->toBe($this->payable->id);
});

test('unauthorized mapping update is rejected in the route and in the service', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole(SystemRole::Auditor->value);

    $this->actingAs($viewer)->get(route('account-mappings.index'))->assertOk()->assertDontSee('Save</button>', false);
    $this->actingAs($viewer)->put(route('account-mappings.update', AccountMappingKey::AccountsPayable->value), ['account_id' => $this->payable->id])->assertForbidden();
    expect(fn () => app(AccountMappingService::class)->set(AccountMappingKey::AccountsPayable, $this->payable->id, $viewer))->toThrow(HttpException::class)
        ->and(AccountMapping::query()->count())->toBe(0);

    $this->actingAs(User::factory()->create())->get(route('account-mappings.index'))->assertForbidden();
});

test('mapping screen saves through the form and rejects an incompatible ledger', function () {
    $this->put(route('account-mappings.update', AccountMappingKey::AccountsPayable->value), ['account_id' => $this->payable->id])->assertRedirect()->assertSessionHasNoErrors();
    $this->put(route('account-mappings.update', AccountMappingKey::FeeIncomeGeneral->value), ['account_id' => $this->payable->id])->assertSessionHasErrors('account_id');
    $this->put(route('account-mappings.update', 'not_a_mapping'), ['account_id' => $this->payable->id])->assertNotFound();
    $this->get(route('account-mappings.index'))->assertOk()->assertSee('Accounts Payable')->assertSee($this->payable->code)->assertSee('Required — not mapped');
});

test('readiness reports real failures and never a false success', function () {
    $readiness = app(AccountingReadinessService::class);
    $checks = collect($readiness->checks())->keyBy('key');

    expect($readiness->isReady())->toBeFalse()
        ->and($checks['chart']['ready'])->toBeTrue()
        ->and($checks['fiscal_year']['ready'])->toBeFalse()
        ->and($checks['fee_revenue']['ready'])->toBeFalse()
        ->and($checks['payable']['ready'])->toBeFalse()
        ->and($checks['donations']['ready'])->toBeFalse()
        ->and($checks['cash_bank']['issues'])->toContain('No active bank account is configured.')
        ->and($checks['settings']['ready'])->toBeTrue();

    app(AccountMappingService::class)->set(AccountMappingKey::AccountsPayable, $this->payable->id, $this->admin);
    expect(collect($readiness->checks())->keyBy('key')['payable']['ready'])->toBeTrue();
    $this->payable->update(['is_active' => false]);
    expect(collect($readiness->checks())->keyBy('key')['payable']['ready'])->toBeFalse()
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('an empty chart is reported as not imported', function () {
    FinancialAccount::query()->delete();
    Account::query()->delete();

    expect(collect(app(AccountingReadinessService::class)->checks())->keyBy('key')['chart']['ready'])->toBeFalse();
});

test('readiness screen is restricted to the administrator permission', function () {
    $accountant = User::factory()->create();
    $accountant->assignRole(SystemRole::Accountant->value);

    $this->actingAs($accountant)->get(route('accounting-setup.readiness'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('accounting-setup.readiness'))->assertOk()->assertSee('Not ready for financial posting')->assertSee('Action required');
});
