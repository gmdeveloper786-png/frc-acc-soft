<?php

use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->administrator()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);

    // The approved numbering: class 1 digit, group +1, sub group +1, ledger +2.
    $make = fn (string $code, string $name, string $type, string $level, ?Account $parent, array $extra = []) => Account::factory()->create([
        'code' => $code, 'name' => $name, 'account_type' => $type, 'level' => $level, 'parent_id' => $parent?->id,
        'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit',
        'financial_statement' => in_array($type, ['income', 'expense'], true) ? 'income_expenditure' : 'balance_sheet',
        'is_postable' => $level === 'ledger', 'sort_order' => (int) $code, ...$extra,
    ]);
    $this->assets = $make('1', 'Assets', 'asset', 'class', null);
    $this->current = $make('11', 'Current Assets', 'asset', 'group', $this->assets);
    $this->cashHeading = $make('111', 'Cash in Hand', 'asset', 'sub_group', $this->current);
    $make('11102', 'Cash in Hand - Rehab Centre', 'asset', 'ledger', $this->cashHeading);
    $make('11104', 'Petty Cash - Rehab Centre', 'asset', 'ledger', $this->cashHeading);
    $this->expenses = $make('5', 'Expenses', 'expense', 'class', null);
    $this->operating = $make('51', 'Operating Expenses', 'expense', 'group', $this->expenses);
    $this->utilities = $make('511', 'Utilities', 'expense', 'sub_group', $this->operating);
    $this->income = $make('4', 'Income', 'income', 'class', null);
});

function coaChild(object $test, Account $parent, string $level, array $overrides = []): array
{
    return ['account_type' => $parent->account_type->value, 'level' => $level, 'parent_id' => $parent->id, 'name' => 'New '.$level, ...$overrides];
}

test('a ledger gets the next code after the highest one under its sub group and inherits its classification', function () {
    $this->actingAs($this->admin)->post(route('accounts.store'), coaChild($this, $this->cashHeading, 'ledger', ['name' => 'Cash - New Branch']))->assertSessionHasNoErrors()->assertRedirect();

    $account = Account::query()->where('name', 'Cash - New Branch')->firstOrFail();
    expect($account->code)->toBe('11105') // 11103 is a gap and stays reserved
        ->and($account->parent_id)->toBe($this->cashHeading->id)
        ->and($account->account_type->value)->toBe('asset')
        ->and($account->financial_statement->value)->toBe('balance_sheet')
        ->and($account->normal_balance->value)->toBe('debit')
        ->and($account->is_postable)->toBeTrue()
        ->and($account->is_active)->toBeTrue()
        ->and($account->financial_account_type)->toBeNull()
        ->and($account->purchase_bill_debit_eligible)->toBeFalse()
        ->and($account->sort_order)->toBeGreaterThan(11104)
        ->and(AuditLog::query()->where('event', 'account.created')->where('auditable_id', $account->id)->value('properties'))->toMatchArray(['code' => '11105', 'code_generated' => true]);
});

test('groups and sub groups get one more digit, are never postable and start at 1 when they have no children', function () {
    $service = app(AccountService::class);

    $group = $service->createChild(coaChild($this, $this->assets, 'group', ['is_postable' => '1']), $this->admin);
    $subGroup = $service->createChild(coaChild($this, $this->current, 'sub_group'), $this->admin);
    $firstLedger = $service->createChild(coaChild($this, $subGroup, 'ledger'), $this->admin);
    $incomeGroup = $service->createChild(coaChild($this, $this->income, 'group'), $this->admin);

    expect($group->code)->toBe('12')->and($group->is_postable)->toBeFalse()->and($group->level->value)->toBe('group')
        ->and($subGroup->code)->toBe('112')->and($subGroup->is_postable)->toBeFalse()
        ->and($firstLedger->code)->toBe('11201')
        ->and($incomeGroup->code)->toBe('41')->and($incomeGroup->normal_balance->value)->toBe('credit')
        ->and($incomeGroup->financial_statement->value)->toBe('income_expenditure');
});

test('consecutive creations under the same parent never reuse a code, even one held under another parent', function () {
    Account::factory()->create(['code' => '11106', 'level' => 'ledger', 'parent_id' => $this->current->id, 'is_postable' => true]);
    $service = app(AccountService::class);

    $first = $service->createChild(coaChild($this, $this->cashHeading, 'ledger', ['name' => 'A']), $this->admin);
    $second = $service->createChild(coaChild($this, $this->cashHeading, 'ledger', ['name' => 'B']), $this->admin);

    expect($first->code)->toBe('11107')->and($second->code)->toBe('11108');
});

test('a contra ledger takes the opposite normal balance and cannot be a cash, bank or purchase bill ledger', function () {
    $service = app(AccountService::class);
    $contra = $service->createChild(coaChild($this, $this->cashHeading, 'ledger', ['is_contra' => '1']), $this->admin);
    expect($contra->normal_balance->value)->toBe('credit');

    expect(fn () => $service->createChild(coaChild($this, $this->cashHeading, 'ledger', ['is_contra' => '1', 'financial_account_type' => 'cash']), $this->admin))
        ->toThrow(ValidationException::class)
        ->and(fn () => $service->createChild(coaChild($this, $this->cashHeading, 'ledger', ['is_contra' => '1', 'purchase_bill_debit_eligible' => '1']), $this->admin))
        ->toThrow(ValidationException::class);
});

test('cash or bank eligibility is only accepted on asset ledgers and purchase bill eligibility stays opt-in', function () {
    $service = app(AccountService::class);
    $bank = $service->createChild(coaChild($this, $this->cashHeading, 'ledger', ['financial_account_type' => 'bank']), $this->admin);
    $expense = $service->createChild(coaChild($this, $this->utilities, 'ledger', ['purchase_bill_debit_eligible' => '1']), $this->admin);
    $plain = $service->createChild(coaChild($this, $this->utilities, 'ledger'), $this->admin);

    expect($bank->financial_account_type->value)->toBe('bank')
        ->and($expense->purchase_bill_debit_eligible)->toBeTrue()->and($expense->code)->toBe('51101')
        ->and($plain->purchase_bill_debit_eligible)->toBeFalse()
        ->and(fn () => $service->createChild(coaChild($this, $this->utilities, 'ledger', ['financial_account_type' => 'cash']), $this->admin))->toThrow(ValidationException::class)
        ->and($service->createChild(coaChild($this, $this->current, 'sub_group', ['financial_account_type' => 'cash', 'purchase_bill_debit_eligible' => '1']), $this->admin)->financial_account_type)->toBeNull();
});

test('forged codes, classifications, classes and invalid parents are rejected', function () {
    $this->actingAs($this->admin);
    $store = fn (array $data) => $this->post(route('accounts.store'), $data);

    $store([...coaChild($this, $this->cashHeading, 'ledger'), 'code' => '11199'])->assertSessionHasErrors('code');
    $store([...coaChild($this, $this->cashHeading, 'ledger'), 'normal_balance' => 'credit', 'financial_statement' => 'income_expenditure'])->assertSessionHasErrors(['normal_balance', 'financial_statement']);
    $store(['account_type' => 'asset', 'level' => 'class', 'name' => 'Second Assets'])->assertSessionHasErrors('level');
    $store(coaChild($this, $this->current, 'ledger'))->assertSessionHasErrors('parent_id'); // a ledger needs a sub group
    $store([...coaChild($this, $this->cashHeading, 'ledger'), 'account_type' => 'expense'])->assertSessionHasErrors('parent_id');
    $ledger = Account::query()->where('code', '11102')->firstOrFail();
    $store(coaChild($this, $ledger, 'ledger'))->assertSessionHasErrors('parent_id'); // ledgers have no children
    $this->cashHeading->update(['is_active' => false]);
    $store(coaChild($this, $this->cashHeading, 'ledger'))->assertSessionHasErrors('parent_id');

    expect(Account::query()->count())->toBe(9)->and(Account::query()->where('level', 'class')->count())->toBe(3);
});

test('code generation stops when the range is exhausted or the existing children do not follow the numbering', function () {
    $service = app(AccountService::class);
    Account::factory()->create(['code' => '11199', 'level' => 'ledger', 'parent_id' => $this->cashHeading->id, 'is_postable' => true]);
    expect(fn () => $service->createChild(coaChild($this, $this->cashHeading, 'ledger'), $this->admin))->toThrow(ValidationException::class, 'No account codes are left');

    Account::factory()->create(['code' => 'UTIL-X', 'level' => 'ledger', 'parent_id' => $this->utilities->id, 'is_postable' => true, 'account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    expect(fn () => $service->createChild(coaChild($this, $this->utilities, 'ledger'), $this->admin))->toThrow(ValidationException::class, 'do not follow the numbering')
        ->and($service->previewNextCode($this->utilities))->toBeNull()
        ->and($service->previewNextCode($this->current))->toBe('112');
});

/** The full update payload the old edit form used to send, with $overrides applied. */
function coaUpdatePayload(Account $account, array $overrides = []): array
{
    return [
        'code' => $account->code, 'name' => $account->name, 'parent_id' => $account->parent_id, 'account_type' => $account->account_type->value,
        'level' => $account->level->value, 'normal_balance' => $account->normal_balance->value, 'financial_statement' => $account->financial_statement->value,
        'is_postable' => $account->is_postable ? '1' : '0', 'is_active' => '1', 'sort_order' => $account->sort_order, ...$overrides,
    ];
}

test('the edit form shows the structure as locked information and only the options that apply', function () {
    $group = app(AccountService::class)->createChild(coaChild($this, $this->assets, 'group', ['name' => 'Test Asset Group']), $this->admin);
    $this->actingAs($this->admin);

    $html = $this->get(route('accounts.edit', $group))->assertOk()->getContent();
    expect($html)->toContain('<code>12</code>')->toContain('1 — Assets')->toContain('Balance Sheet')
        ->not->toContain('name="code"')->not->toContain('name="parent_id"')->not->toContain('name="level"')->not->toContain('name="account_type"')
        ->not->toContain('name="normal_balance"')->not->toContain('name="financial_statement"')
        ->not->toContain('name="is_postable"')->not->toContain('name="financial_account_type"')->not->toContain('name="purchase_bill_debit_eligible"');

    $cash = Account::query()->where('code', '11102')->firstOrFail();
    expect($this->get(route('accounts.edit', $cash))->getContent())->toContain('name="is_postable"')->toContain('name="financial_account_type"')->toContain('name="purchase_bill_debit_eligible"');
    $contra = app(AccountService::class)->createChild(coaChild($this, $this->cashHeading, 'ledger', ['is_contra' => '1']), $this->admin);
    expect($this->get(route('accounts.edit', $contra))->getContent())->toContain('Contra')->not->toContain('name="financial_account_type"')->not->toContain('name="purchase_bill_debit_eligible"');
    $expense = app(AccountService::class)->createChild(coaChild($this, $this->utilities, 'ledger'), $this->admin);
    expect($this->get(route('accounts.edit', $expense))->getContent())->not->toContain('name="financial_account_type"')->toContain('name="purchase_bill_debit_eligible"');
});

test('name, description, sort order and active status can be edited and are audited', function () {
    $ledger = Account::query()->where('code', '11102')->firstOrFail();
    $this->actingAs($this->admin)->put(route('accounts.update', $ledger), ['name' => 'Cash - Head Office', 'description' => 'Main cash box', 'sort_order' => 7, 'is_active' => '1'])
        ->assertSessionHasNoErrors()->assertRedirect(route('accounts.show', $ledger));

    $fresh = $ledger->fresh();
    expect($fresh->name)->toBe('Cash - Head Office')->and($fresh->description)->toBe('Main cash box')->and($fresh->sort_order)->toBe(7)
        // Everything the request left out keeps its stored value.
        ->and($fresh->code)->toBe('11102')->and($fresh->parent_id)->toBe($this->cashHeading->id)->and($fresh->level->value)->toBe('ledger')
        ->and($fresh->account_type->value)->toBe('asset')->and($fresh->normal_balance->value)->toBe('debit')->and($fresh->financial_statement->value)->toBe('balance_sheet')
        ->and($fresh->is_postable)->toBeTrue();
    $audit = AuditLog::query()->where('event', 'account.updated')->where('auditable_id', $ledger->id)->latest('id')->firstOrFail();
    expect($audit->properties['before']['name'])->toBe('Cash in Hand - Rehab Centre')->and($audit->properties['after']['name'])->toBe('Cash - Head Office');
});

test('code, parent, level, type, normal balance and statement cannot be changed by a forged request', function () {
    $ledger = Account::query()->where('code', '11102')->firstOrFail();
    $otherGroup = app(AccountService::class)->createChild(coaChild($this, $this->assets, 'group'), $this->admin);
    $this->actingAs($this->admin);
    $put = fn (Account $account, array $overrides) => $this->put(route('accounts.update', $account), coaUpdatePayload($account, $overrides));

    $put($ledger, ['code' => '11109'])->assertSessionHasErrors('account');
    $put($ledger, ['account_type' => 'expense', 'financial_statement' => 'income_expenditure'])->assertSessionHasErrors('account');
    $put($ledger, ['normal_balance' => 'credit'])->assertSessionHasErrors('account');
    // A group with descendants cannot be moved: its children's codes depend on its code.
    $put($this->current, ['parent_id' => $otherGroup->id])->assertSessionHasErrors('account');
    $put($this->current, ['parent_id' => $this->current->id])->assertSessionHasErrors('account');
    $put($this->current, ['level' => 'class', 'parent_id' => null])->assertSessionHasErrors('account');
    $put($this->assets, ['normal_balance' => 'credit'])->assertSessionHasErrors('account');
    $put($this->assets, ['name' => 'ASSETS'])->assertSessionHasNoErrors();

    expect($ledger->fresh()->only(['code', 'parent_id']))->toBe(['code' => '11102', 'parent_id' => $this->cashHeading->id])
        ->and($ledger->fresh()->normal_balance->value)->toBe('debit')->and($ledger->fresh()->account_type->value)->toBe('asset')
        ->and($this->current->fresh()->parent_id)->toBe($this->assets->id)->and($this->current->fresh()->level->value)->toBe('group')
        ->and($this->cashHeading->fresh()->parent_id)->toBe($this->current->id)
        ->and($this->assets->fresh()->normal_balance->value)->toBe('debit')->and($this->assets->fresh()->name)->toBe('ASSETS');
});

test('groups and sub groups cannot be made postable, cash/bank or purchase bill eligible', function () {
    $this->actingAs($this->admin);
    foreach ([['is_postable' => '1'], ['financial_account_type' => 'cash'], ['purchase_bill_debit_eligible' => '1']] as $forged) {
        $this->put(route('accounts.update', $this->cashHeading), ['name' => 'Cash in Hand', 'is_active' => '1', ...$forged])->assertSessionHasErrors('account');
        $this->put(route('accounts.update', $this->operating), ['name' => 'Operating Expenses', 'is_active' => '1', ...$forged])->assertSessionHasErrors('account');
    }

    expect($this->cashHeading->fresh()->is_postable)->toBeFalse()->and($this->cashHeading->fresh()->financial_account_type)->toBeNull()
        ->and($this->operating->fresh()->purchase_bill_debit_eligible)->toBeFalse();
});

test('a ledger keeps its eligibility rules when edited', function () {
    $this->actingAs($this->admin);
    $expense = app(AccountService::class)->createChild(coaChild($this, $this->utilities, 'ledger'), $this->admin);

    $this->put(route('accounts.update', $expense), ['name' => $expense->name, 'is_active' => '1', 'purchase_bill_debit_eligible' => '1'])->assertSessionHasNoErrors();
    expect($expense->fresh()->purchase_bill_debit_eligible)->toBeTrue();
    $this->put(route('accounts.update', $expense), ['name' => $expense->name, 'is_active' => '1', 'financial_account_type' => 'cash'])->assertSessionHasErrors('financial_account_type');
    expect($expense->fresh()->financial_account_type)->toBeNull()->and($expense->fresh()->purchase_bill_debit_eligible)->toBeTrue();
});

test('only users who may create accounts can add one, and the form offers child creation', function () {
    $approver = User::factory()->create();
    $approver->assignRole(SystemRole::Approver->value);
    $this->actingAs($approver)->post(route('accounts.store'), coaChild($this, $this->cashHeading, 'ledger'))->assertForbidden();
    $this->actingAs(User::factory()->create())->post(route('accounts.store'), coaChild($this, $this->cashHeading, 'ledger'))->assertForbidden();

    $form = $this->actingAs($this->admin)->get(route('accounts.create', ['parent' => $this->cashHeading->id]))->assertOk()->getContent();
    expect($form)->toMatch('/<option value="'.$this->cashHeading->id.'"[^>]*data-next-code="11105"[^>]*selected/')
        ->and($form)->toMatch('/<option value="ledger"\s+selected/')
        ->and($form)->not->toContain('name="code"');

    $this->get(route('accounts.show', $this->cashHeading))->assertSee(route('accounts.create', ['parent' => $this->cashHeading->id]), false);
    $this->get(route('accounts.show', Account::query()->where('code', '11102')->firstOrFail()))->assertDontSee('Add child account');
});
