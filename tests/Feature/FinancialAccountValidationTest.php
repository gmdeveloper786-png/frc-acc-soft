<?php

use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
});

function financialAccountPayload(Account $ledger, Branch $branch, string $type = 'cash'): array
{
    return [
        'type' => $type,
        'name' => $type === 'cash' ? 'Main cash box' : 'Operating bank account',
        'account_id' => $ledger->id,
        'branch_id' => $branch->id,
        'bank_name' => $type === 'bank' ? 'Test Bank' : null,
        'account_title' => $type === 'bank' ? 'Faizan Rehabilitation Centre' : null,
        'account_number' => $type === 'bank' ? '0012-3456789012' : null,
        'currency' => 'PKR',
    ];
}

test('cash and bank financial accounts accept only a matching explicitly eligible ledger', function () {
    $cash = Account::factory()->create(['financial_account_type' => 'cash']);
    $bank = Account::factory()->create(['financial_account_type' => 'bank']);

    $this->actingAs($this->admin)->post(route('financial-accounts.store'), financialAccountPayload($cash, $this->branch))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('financial-accounts.store'), financialAccountPayload($bank, $this->branch, 'bank'))
        ->assertSessionHasNoErrors();

    expect(FinancialAccount::query()->where('type', 'cash')->where('account_id', $cash->id)->exists())->toBeTrue()
        ->and(FinancialAccount::query()->where('type', 'bank')->where('account_id', $bank->id)->exists())->toBeTrue();
});

test('cash account creation and update do not require banking details', function () {
    $ledger = Account::factory()->create(['financial_account_type' => 'cash']);

    $this->actingAs($this->admin)->post(route('financial-accounts.store'), financialAccountPayload($ledger, $this->branch))
        ->assertSessionHasNoErrors();

    $account = FinancialAccount::query()->sole();
    expect($account->bank_name)->toBeNull()->and($account->account_title)->toBeNull()->and($account->account_number)->toBeNull();

    $this->actingAs($this->admin)->put(route('financial-accounts.update', $account), financialAccountPayload($ledger, $this->branch, 'cash'))
        ->assertSessionHasNoErrors();
});

test('an existing complete cash account can be activated without banking details', function () {
    $ledger = Account::factory()->create(['financial_account_type' => 'cash']);
    $account = FinancialAccount::factory()->create(['type' => 'cash', 'account_id' => $ledger, 'branch_id' => $this->branch, 'bank_name' => null, 'account_title' => null, 'account_number' => null, 'is_active' => false]);

    $this->actingAs($this->admin)->post(route('financial-accounts.toggle', $account))->assertSessionHasNoErrors();

    expect($account->fresh()->is_active)->toBeTrue();
});

test('each mandatory bank field is enforced', function (string $field, mixed $value) {
    $ledger = Account::factory()->create(['financial_account_type' => 'bank']);
    $payload = financialAccountPayload($ledger, $this->branch, 'bank');
    $payload[$field] = $value;

    $this->actingAs($this->admin)->post(route('financial-accounts.store'), $payload)
        ->assertSessionHasErrors($field);

    expect(FinancialAccount::query()->doesntExist())->toBeTrue();
})->with([
    'bank name missing' => ['bank_name', null],
    'account title missing' => ['account_title', null],
    'account number missing' => ['account_number', null],
    'bank name whitespace only' => ['bank_name', '   '],
    'account title whitespace only' => ['account_title', " \t "],
    'account number whitespace only' => ['account_number', '   '],
    'account name is not meaningful' => ['name', 'a'],
]);

test('a complete bank account accepts a valid domestic account number', function () {
    $ledger = Account::factory()->create(['financial_account_type' => 'bank']);

    $this->actingAs($this->admin)->post(route('financial-accounts.store'), financialAccountPayload($ledger, $this->branch, 'bank'))
        ->assertSessionHasNoErrors();

    $account = FinancialAccount::query()->sole();
    expect($account->type)->toBe('bank')->and($account->account_last_four)->toBe('9012');
});

test('banking identifiers are not exposed in financial account audit logs', function () {
    $ledger = Account::factory()->create(['financial_account_type' => 'bank']);
    $accountNumber = '0012-3456789012';

    $this->actingAs($this->admin)->post(route('financial-accounts.store'), [
        ...financialAccountPayload($ledger, $this->branch, 'bank'),
        'account_number' => $accountNumber,
    ])->assertSessionHasNoErrors();

    $serialized = json_encode(AuditLog::query()->where('event', 'financial_accounts.created')->sole()->properties);

    expect($serialized)
        ->not->toContain($accountNumber)
        ->not->toContain('account_number')
        ->not->toContain('iban');
});

test('a valid Pakistan IBAN is accepted and an invalid checksum is rejected', function () {
    $validLedger = Account::factory()->create(['financial_account_type' => 'bank']);
    $invalidLedger = Account::factory()->create(['financial_account_type' => 'bank']);

    $this->actingAs($this->admin)->post(route('financial-accounts.store'), [...financialAccountPayload($validLedger, $this->branch, 'bank'), 'account_number' => 'PK36 SCBL 0000 0011 2345 6702'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('financial-accounts.store'), [...financialAccountPayload($invalidLedger, $this->branch, 'bank'), 'account_number' => 'PK00 SCBL 0000 0011 2345 6702'])
        ->assertSessionHasErrors('account_number');

    expect(FinancialAccount::query()->count())->toBe(1);
});

test('an incomplete legacy bank account cannot be activated', function () {
    $ledger = Account::factory()->create(['financial_account_type' => 'bank']);
    $account = FinancialAccount::factory()->create(['type' => 'bank', 'name' => 'Legacy bank', 'account_id' => $ledger, 'branch_id' => $this->branch, 'bank_name' => 'Test Bank', 'account_title' => null, 'account_number' => null, 'is_active' => false]);

    $this->actingAs($this->admin)->post(route('financial-accounts.toggle', $account))
        ->assertSessionHasErrors(['account_title', 'account_number']);

    expect($account->fresh()->is_active)->toBeFalse();
});

test('an active bank account cannot be updated to remove required details', function (string $field) {
    $ledger = Account::factory()->create(['financial_account_type' => 'bank']);
    $account = FinancialAccount::factory()->create(['type' => 'bank', 'name' => 'Operating bank', 'account_id' => $ledger, 'branch_id' => $this->branch, 'bank_name' => 'Test Bank', 'account_title' => 'FRC', 'account_number' => '001234567890', 'is_active' => true]);
    $payload = financialAccountPayload($ledger, $this->branch, 'bank');
    $payload[$field] = '   ';

    $this->actingAs($this->admin)->put(route('financial-accounts.update', $account), $payload)
        ->assertSessionHasErrors($field);

    expect(filled($account->fresh()->{$field}))->toBeTrue();
})->with(['bank_name', 'account_title', 'account_number']);

test('editing can retain an existing encrypted bank account number without exposing it in the form', function () {
    $ledger = Account::factory()->create(['financial_account_type' => 'bank']);
    $account = FinancialAccount::factory()->create(['type' => 'bank', 'name' => 'Operating bank', 'account_id' => $ledger, 'branch_id' => $this->branch, 'bank_name' => 'Test Bank', 'account_title' => 'FRC', 'account_number' => '001234567890', 'account_last_four' => '7890']);
    $payload = financialAccountPayload($ledger, $this->branch, 'bank');
    $payload['account_number'] = '';
    $payload['keep_account_number'] = '1';

    $this->actingAs($this->admin)->put(route('financial-accounts.update', $account), $payload)
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->get(route('financial-accounts.edit', $account))->assertOk()
        ->assertDontSee('001234567890');

    expect($account->fresh()->account_number)->toBe('001234567890');
});

test('cash and bank financial accounts reject a ledger classified for the other type', function (string $requestedType, string $ledgerType) {
    $ledger = Account::factory()->create(['financial_account_type' => $ledgerType]);

    $this->actingAs($this->admin)->post(route('financial-accounts.store'), financialAccountPayload($ledger, $this->branch, $requestedType))
        ->assertSessionHasErrors('account_id');

    expect(FinancialAccount::query()->doesntExist())->toBeTrue();
})->with([
    'cash cannot use a bank ledger' => ['cash', 'bank'],
    'bank cannot use a cash ledger' => ['bank', 'cash'],
]);

test('receivable inventory and unrelated asset ledgers cannot back financial accounts', function (string $name) {
    $ledger = Account::factory()->create(['name' => $name, 'financial_account_type' => null]);

    $this->actingAs($this->admin)->post(route('financial-accounts.store'), financialAccountPayload($ledger, $this->branch))
        ->assertSessionHasErrors('account_id');

    expect(FinancialAccount::query()->doesntExist())->toBeTrue();
})->with(['Accounts Receivable', 'Inventory', 'Security Deposit']);

test('only active posting enabled debit asset balance sheet ledgers can back financial accounts', function (array $state) {
    $ledger = Account::factory()->create(['financial_account_type' => 'cash', ...$state]);

    $this->actingAs($this->admin)->post(route('financial-accounts.store'), financialAccountPayload($ledger, $this->branch))
        ->assertSessionHasErrors('account_id');

    expect(FinancialAccount::query()->doesntExist())->toBeTrue();
})->with([
    'inactive' => [['is_active' => false]],
    'not posting enabled' => [['is_postable' => false]],
    'not an asset' => [['account_type' => 'liability']],
    'not debit normal balance' => [['normal_balance' => 'credit']],
    'not on balance sheet' => [['financial_statement' => 'income_expenditure']],
]);

test('financial account form exposes only eligible ledgers grouped by selected type', function () {
    $cash = Account::factory()->create(['financial_account_type' => 'cash']);
    $bank = Account::factory()->create(['financial_account_type' => 'bank']);
    $receivable = Account::factory()->create(['financial_account_type' => null]);
    $inactiveCash = Account::factory()->create(['financial_account_type' => 'cash', 'is_active' => false]);

    $this->actingAs($this->admin)->get(route('financial-accounts.create'))->assertOk()
        ->assertViewHas('ledgersByType', function ($groups) use ($cash, $bank, $receivable, $inactiveCash): bool {
            return $groups->get('cash')->pluck('id')->all() === [$cash->id]
                && $groups->get('bank')->pluck('id')->all() === [$bank->id]
                && ! $groups->flatten()->pluck('id')->contains($receivable->id)
                && ! $groups->flatten()->pluck('id')->contains($inactiveCash->id);
        });
});

test('a branch scoped user cannot create a financial account for another branch', function () {
    $otherBranch = Branch::factory()->create(['status' => 'publish']);
    $ledger = Account::factory()->create(['financial_account_type' => 'cash']);
    $user = User::factory()->create();
    $user->assignRole(SystemRole::Accountant->value);
    $user->branches()->attach($this->branch);

    $response = $this->actingAs($user)->post(route('financial-accounts.store'), financialAccountPayload($ledger, $otherBranch));

    $response->assertNotFound()->assertHeaderMissing('Location');

    expect(FinancialAccount::query()->doesntExist())->toBeTrue();
});

test('a branch scoped accountant can create a financial account for an assigned branch', function () {
    $ledger = Account::factory()->create(['financial_account_type' => 'cash']);
    $user = User::factory()->create();
    $user->assignRole(SystemRole::Accountant->value);
    $user->branches()->attach($this->branch);

    $this->actingAs($user)->post(route('financial-accounts.store'), financialAccountPayload($ledger, $this->branch))
        ->assertRedirect(route('financial-accounts.index'))
        ->assertSessionHasNoErrors();

    expect(FinancialAccount::query()->whereBelongsTo($this->branch)->whereBelongsTo($ledger, 'account')->exists())->toBeTrue();
});

test('cross branch edit update and status changes are forbidden without disclosing account data', function () {
    $otherBranch = Branch::factory()->create(['status' => 'publish']);
    $ledger = Account::factory()->create(['financial_account_type' => 'cash']);
    $foreignAccount = FinancialAccount::factory()->create([
        'name' => 'Foreign branch cash',
        'account_id' => $ledger,
        'branch_id' => $otherBranch,
        'is_active' => true,
    ]);
    $user = User::factory()->create();
    $user->assignRole(SystemRole::Accountant->value);
    $user->branches()->attach($this->branch);

    $this->actingAs($user)->get(route('financial-accounts.edit', $foreignAccount))->assertForbidden();
    $this->actingAs($user)->put(route('financial-accounts.update', $foreignAccount), financialAccountPayload($ledger, $otherBranch))
        ->assertForbidden();
    $this->actingAs($user)->post(route('financial-accounts.toggle', $foreignAccount))->assertForbidden();

    expect($foreignAccount->fresh()->name)->toBe('Foreign branch cash')
        ->and($foreignAccount->fresh()->is_active)->toBeTrue();
});

test('administrator and explicitly assigned multi branch accountants retain authorized access', function () {
    $secondBranch = Branch::factory()->create(['status' => 'publish']);
    $adminLedger = Account::factory()->create(['financial_account_type' => 'cash']);
    $accountantLedger = Account::factory()->create(['financial_account_type' => 'cash']);
    $accountant = User::factory()->create();
    $accountant->assignRole(SystemRole::Accountant->value);
    $accountant->branches()->attach([$this->branch->id, $secondBranch->id]);

    $this->actingAs($this->admin)->post(route('financial-accounts.store'), financialAccountPayload($adminLedger, $secondBranch))
        ->assertRedirect(route('financial-accounts.index'));
    $this->actingAs($accountant)->post(route('financial-accounts.store'), financialAccountPayload($accountantLedger, $secondBranch))
        ->assertRedirect(route('financial-accounts.index'));

    expect(FinancialAccount::query()->where('branch_id', $secondBranch->id)->count())->toBe(2);
});
