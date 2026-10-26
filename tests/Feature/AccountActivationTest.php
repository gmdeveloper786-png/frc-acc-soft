<?php

use App\Enums\AccountingPeriodStatus;
use App\Enums\FiscalYearStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\FiscalYear;
use App\Models\User;
use App\Models\VoucherType;
use App\Services\AccountService;
use App\Services\JournalPostingService;
use App\Services\JournalWorkflowService;
use App\Services\OpeningBalanceValidationService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->admin = User::factory()->administrator()->create();
    $this->admin->assignRole(SystemRole::Administrator->value);
    $this->service = app(AccountService::class);

    $make = fn (string $code, string $type, string $level, ?Account $parent) => Account::factory()->create([
        'code' => $code, 'name' => 'Activation '.$code, 'account_type' => $type, 'level' => $level, 'parent_id' => $parent?->id,
        'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit',
        'financial_statement' => in_array($type, ['income', 'expense'], true) ? 'income_expenditure' : 'balance_sheet',
        'is_postable' => $level === 'ledger', 'is_active' => true,
    ]);
    $this->assets = $make('1', 'asset', 'class', null);
    $this->group = $make('13', 'asset', 'group', $this->assets);
    $this->subGroup = $make('131', 'asset', 'sub_group', $this->group);
    $this->ledger = $make('13101', 'asset', 'ledger', $this->subGroup);
    $this->incomeClass = $make('4', 'income', 'class', null);
    $this->incomeGroup = $make('41', 'income', 'group', $this->incomeClass);
    $this->incomeSubGroup = $make('411', 'income', 'sub_group', $this->incomeGroup);
    $this->incomeLedger = $make('41101', 'income', 'ledger', $this->incomeSubGroup);
    $this->actingAs($this->admin);
});

function activationStates(object $test): array
{
    return collect([$test->group, $test->subGroup, $test->ledger])->mapWithKeys(fn (Account $account) => [$account->code => (bool) $account->fresh()->is_active])->all();
}

test('a parent with active descendants cannot be deactivated through the action or the edit form', function () {
    $this->post(route('accounts.deactivate', $this->group))->assertSessionHasErrors(['is_active' => 'Deactivate the active child accounts first: 131, 13101.']);
    $this->post(route('accounts.deactivate', $this->subGroup))->assertSessionHasErrors(['is_active' => 'Deactivate the active child accounts first: 13101.']);
    $this->put(route('accounts.update', $this->group), ['name' => $this->group->name, 'is_active' => '0'])->assertSessionHasErrors('is_active');

    expect(activationStates($this))->toBe(['13' => true, '131' => true, '13101' => true])
        ->and(AuditLog::query()->where('event', 'account.deactivated')->exists())->toBeFalse();
});

test('deactivation is child first and reactivation parent first, each audited, with no cascade', function () {
    $this->post(route('accounts.deactivate', $this->ledger))->assertSessionHasNoErrors();
    $this->post(route('accounts.deactivate', $this->subGroup))->assertSessionHasNoErrors();
    $this->put(route('accounts.update', $this->group), ['name' => $this->group->name, 'is_active' => '0'])->assertSessionHasNoErrors();
    expect(activationStates($this))->toBe(['13' => false, '131' => false, '13101' => false]);

    // A child cannot come back before its parents, through either route.
    $this->post(route('accounts.activate', $this->ledger))->assertSessionHasErrors(['is_active' => 'Activate the parent account(s) first: 131, 13.']);
    $this->put(route('accounts.update', $this->subGroup), ['name' => $this->subGroup->name, 'is_active' => '1'])->assertSessionHasErrors('is_active');
    expect(activationStates($this))->toBe(['13' => false, '131' => false, '13101' => false]);

    $this->post(route('accounts.activate', $this->group))->assertSessionHasNoErrors();
    expect(activationStates($this))->toBe(['13' => true, '131' => false, '13101' => false]); // no cascade
    $this->post(route('accounts.activate', $this->subGroup))->assertSessionHasNoErrors();
    $this->post(route('accounts.activate', $this->ledger))->assertSessionHasNoErrors();

    expect(activationStates($this))->toBe(['13' => true, '131' => true, '13101' => true])
        ->and(AuditLog::query()->where('event', 'account.deactivated')->pluck('auditable_id')->sort()->values()->all())->toBe(collect([$this->ledger->id, $this->subGroup->id])->sort()->values()->all())
        ->and(AuditLog::query()->where('event', 'account.activated')->count())->toBe(3)
        ->and(AuditLog::query()->where('event', 'account.updated')->where('auditable_id', $this->group->id)->exists())->toBeTrue();
});

test('the fixed classes cannot be deactivated', function () {
    $this->ledger->update(['is_active' => false]);
    $this->subGroup->update(['is_active' => false]);
    $this->group->update(['is_active' => false]);
    $this->incomeLedger->update(['is_active' => false]);
    $this->incomeSubGroup->update(['is_active' => false]);
    $this->incomeGroup->update(['is_active' => false]);

    $this->post(route('accounts.deactivate', $this->assets))->assertSessionHasErrors(['is_active' => 'The five account classes are fixed and cannot be deactivated.']);
    $this->put(route('accounts.update', $this->incomeClass), ['name' => $this->incomeClass->name, 'is_active' => '0'])->assertSessionHasErrors('is_active');

    expect($this->assets->fresh()->is_active)->toBeTrue()->and($this->incomeClass->fresh()->is_active)->toBeTrue();
});

test('a ledger behind an active cash or bank account cannot be deactivated', function () {
    $financial = FinancialAccount::factory()->create(['type' => 'bank', 'branch_id' => Branch::factory()->create(), 'account_id' => $this->ledger, 'is_active' => true]);

    $this->post(route('accounts.deactivate', $this->ledger))->assertSessionHasErrors(['is_active' => 'This ledger backs an active cash or bank account. Deactivate that account first.']);
    expect($this->ledger->fresh()->is_active)->toBeTrue();

    $financial->update(['is_active' => false]);
    $this->post(route('accounts.deactivate', $this->ledger))->assertSessionHasNoErrors();
    expect($this->ledger->fresh()->is_active)->toBeFalse();
});

test('no account can be added under an inactive ancestor', function () {
    // Legacy data: an active sub group under an inactive group.
    DB::table('accounts')->where('id', $this->group->id)->update(['is_active' => false]);

    $this->post(route('accounts.store'), ['account_type' => 'asset', 'level' => 'ledger', 'parent_id' => $this->subGroup->id, 'name' => 'Under inactive'])
        ->assertSessionHasErrors(['parent_id' => 'Accounts cannot be added under an inactive account (13). Activate it first.']);
    expect(Account::query()->where('name', 'Under inactive')->exists())->toBeFalse();
});

test('posting refuses an active ledger under an inactive ancestor, including legacy data', function () {
    $branch = Branch::factory()->create();
    $year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => FiscalYearStatus::Open, 'is_current' => true]);
    $period = AccountingPeriod::factory()->create(['fiscal_year_id' => $year, 'period_number' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => AccountingPeriodStatus::Open]);
    $maker = User::factory()->create();
    $maker->assignRole(SystemRole::Accountant->value);
    $approver = User::factory()->create();
    $approver->assignRole(SystemRole::Approver->value);
    $poster = User::factory()->create();
    $poster->assignRole(SystemRole::Accountant->value);
    foreach ([$maker, $approver, $poster] as $user) {
        $user->branches()->attach($branch);
    }

    $workflow = app(JournalWorkflowService::class);
    $this->actingAs($maker);
    $journal = $workflow->create([
        'voucher_type_id' => VoucherType::query()->where('code', 'JV')->value('id'), 'transaction_date' => '2026-01-20',
        'fiscal_year_id' => $year->id, 'accounting_period_id' => $period->id, 'branch_id' => $branch->id, 'narration' => 'Inactive ancestry fixture',
        'lines' => [['account_id' => $this->ledger->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $this->incomeLedger->id, 'debit' => '0', 'credit' => '100']],
    ], $maker);
    $workflow->submit($journal, $maker);
    $this->actingAs($approver);
    $workflow->approve($journal->fresh(), $approver);

    // Legacy inconsistency: the ledger is still active but its group is not.
    DB::table('accounts')->where('id', $this->group->id)->update(['is_active' => false]);
    $this->actingAs($poster);

    expect(fn () => app(JournalPostingService::class)->post($journal->fresh(), $poster))->toThrow(ValidationException::class, 'Only active, postable ledger accounts')
        ->and($journal->fresh()->status)->toBe(JournalStatus::Approved)->and($journal->fresh()->voucher_number)->toBeNull()
        ->and(app(OpeningBalanceValidationService::class)->isEligibleAccount($this->ledger->fresh()))->toBeFalse();

    DB::table('accounts')->where('id', $this->group->id)->update(['is_active' => true]);
    expect(app(OpeningBalanceValidationService::class)->isEligibleAccount($this->ledger->fresh()))->toBeTrue()
        ->and(app(JournalPostingService::class)->post($journal->fresh(), $poster)->status)->toBe(JournalStatus::Posted);
});

test('deactivating through edit needs the deactivate permission', function () {
    $editor = User::factory()->create();
    $editor->givePermissionTo(['accounts.view', 'accounts.update']);
    $this->ledger->update(['is_active' => true]);

    $this->actingAs($editor)->put(route('accounts.update', $this->ledger), ['name' => 'Renamed', 'is_active' => '0'])->assertForbidden();
    $this->post(route('accounts.deactivate', $this->ledger))->assertForbidden();
    expect($this->ledger->fresh()->is_active)->toBeTrue()->and($this->ledger->fresh()->name)->toBe('Activation 13101');

    // Without the permission the form keeps the account active instead of offering the switch.
    $this->get(route('accounts.edit', $this->ledger))->assertOk()->assertSee('You do not have permission to deactivate accounts.');
    $this->put(route('accounts.update', $this->ledger), ['name' => 'Renamed', 'is_active' => '1'])->assertSessionHasNoErrors();
    expect($this->ledger->fresh()->name)->toBe('Renamed')->and($this->ledger->fresh()->is_active)->toBeTrue();
});
