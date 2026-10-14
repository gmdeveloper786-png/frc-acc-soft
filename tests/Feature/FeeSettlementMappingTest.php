<?php

use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FeeSettlementMapping;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\FeePosting\FeeSettlementMappingService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class]);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
    $this->branch = Branch::factory()->create();
    $this->otherBranch = Branch::factory()->create();
    $this->cash = feemapAccount($this->branch, 'cash');
    $this->bank = feemapAccount($this->branch, 'bank');
    $this->actingAs($this->admin);
});

function feemapAccount(Branch $branch, string $type, array $overrides = [], array $ledger = []): FinancialAccount
{
    return FinancialAccount::factory()->create(['type' => $type, 'branch_id' => $branch->id, 'account_id' => Account::factory()->create($ledger)->id, ...$overrides]);
}

function feemapSave(object $test, string $method, ?int $accountId, ?Branch $branch = null)
{
    return $test->put(route('fee-settlement-mappings.update'), ['branch_id' => ($branch ?? $test->branch)->id, 'payment_method' => $method, 'financial_account_id' => $accountId]);
}

test('a valid mapping is saved and audited with old and new values', function () {
    feemapSave($this, 'cash', $this->cash->id)->assertSessionHasNoErrors()->assertRedirect();
    $replacement = feemapAccount($this->branch, 'cash');
    feemapSave($this, 'cash', $replacement->id)->assertSessionHasNoErrors();

    expect(FeeSettlementMapping::query()->where('branch_id', $this->branch->id)->where('payment_method', 'cash')->value('financial_account_id'))->toBe($replacement->id);
    $audits = AuditLog::query()->where('event', 'fee_settlement.mapping_updated')->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits[0]->properties['old_financial_account_id'])->toBeNull()
        ->and($audits[0]->properties['new_financial_account_id'])->toBe($this->cash->id)
        ->and($audits[1]->properties['old_financial_account_id'])->toBe($this->cash->id)
        ->and($audits[1]->properties['new_financial_account_id'])->toBe($replacement->id)
        ->and($audits[1]->actor_id)->toBe($this->admin->id);

    feemapSave($this, 'cash', null)->assertSessionHasNoErrors();
    expect(FeeSettlementMapping::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'fee_settlement.mapping_cleared')->exists())->toBeTrue();
});

test('the cash method requires a cash account', function () {
    feemapSave($this, 'cash', $this->bank->id)->assertSessionHasErrors('financial_account_id');
    expect(FeeSettlementMapping::query()->count())->toBe(0);
});

test('every non-cash method requires a bank account', function (string $method) {
    feemapSave($this, $method, $this->cash->id)->assertSessionHasErrors('financial_account_id');
    feemapSave($this, $method, $this->bank->id)->assertSessionHasNoErrors();
    expect(FeeSettlementMapping::query()->where('payment_method', $method)->value('financial_account_id'))->toBe($this->bank->id);
})->with(['bank_transfer', 'card', 'easypaisa', 'jazzcash', 'other']);

test('an account of another branch is refused', function () {
    $foreign = feemapAccount($this->otherBranch, 'cash');
    feemapSave($this, 'cash', $foreign->id)->assertSessionHasErrors('financial_account_id');
    expect(FeeSettlementMapping::query()->count())->toBe(0);
});

test('inactive accounts and unusable ledgers are refused', function () {
    $inactive = feemapAccount($this->branch, 'cash', ['is_active' => false]);
    $inactiveLedger = feemapAccount($this->branch, 'cash', [], ['is_active' => false]);
    $incomeLedger = feemapAccount($this->branch, 'bank', [], ['account_type' => 'income', 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure']);

    feemapSave($this, 'cash', $inactive->id)->assertSessionHasErrors('financial_account_id');
    feemapSave($this, 'cash', $inactiveLedger->id)->assertSessionHasErrors('financial_account_id');
    feemapSave($this, 'card', $incomeLedger->id)->assertSessionHasErrors('financial_account_id');
    feemapSave($this, 'unknown_method', $this->bank->id)->assertSessionHasErrors('payment_method');
    expect(FeeSettlementMapping::query()->count())->toBe(0);
});

test('card easypaisa and jazzcash are mapped independently', function () {
    $card = feemapAccount($this->branch, 'bank', ['name' => 'Card clearing']);
    $easypaisa = feemapAccount($this->branch, 'bank', ['name' => 'Easypaisa wallet']);
    $jazzcash = feemapAccount($this->branch, 'bank', ['name' => 'JazzCash wallet']);
    feemapSave($this, 'card', $card->id)->assertSessionHasNoErrors();
    feemapSave($this, 'easypaisa', $easypaisa->id)->assertSessionHasNoErrors();
    feemapSave($this, 'jazzcash', $jazzcash->id)->assertSessionHasNoErrors();

    $mapped = FeeSettlementMapping::query()->where('branch_id', $this->branch->id)->pluck('financial_account_id', 'payment_method');
    expect($mapped['card'])->toBe($card->id)->and($mapped['easypaisa'])->toBe($easypaisa->id)->and($mapped['jazzcash'])->toBe($jazzcash->id);

    $resolver = app(FeeSettlementMappingService::class);
    expect($resolver->resolve($this->branch->id, 'easypaisa')['account']->id)->toBe($easypaisa->id)
        ->and($resolver->resolve($this->branch->id, 'bank_transfer')['issue'])->toContain('No settlement account is mapped');
});

test('mapping requires the manage permission and access to the branch', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole(SystemRole::Auditor->value);
    $this->actingAs($viewer);
    $this->get(route('fee-settlement-mappings.index'))->assertOk();
    feemapSave($this, 'cash', $this->cash->id)->assertForbidden();

    $accountant = User::factory()->create();
    $accountant->assignRole(SystemRole::Accountant->value);
    $accountant->branches()->attach($this->otherBranch);
    $this->actingAs($accountant);
    feemapSave($this, 'cash', $this->cash->id)->assertForbidden();
    expect(FeeSettlementMapping::query()->count())->toBe(0);

    $this->get(route('fee-settlement-mappings.index'))->assertOk()->assertSee($this->otherBranch->name)->assertDontSee($this->branch->name);
});

test('the mapping screen lists missing mappings', function () {
    feemapSave($this, 'cash', $this->cash->id);
    $this->get(route('fee-settlement-mappings.index'))->assertOk()
        ->assertSee('5 missing or invalid')
        ->assertSee('Not mapped — payments by this method cannot be posted.', false);

    $this->cash->update(['is_active' => false]);
    $this->get(route('fee-settlement-mappings.index'))->assertOk()->assertSee('Invalid:');
});
