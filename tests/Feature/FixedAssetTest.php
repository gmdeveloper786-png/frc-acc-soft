<?php

use App\Enums\DepreciationRunStatus;
use App\Enums\FixedAssetStatus;
use App\Enums\JournalStatus;
use App\Enums\SystemRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AppSetting;
use App\Models\AssetCategory;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DepreciationRun;
use App\Models\DepreciationRunLine;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\FixedAssetEvent;
use App\Models\JournalEntry;
use App\Models\PurchaseBill;
use App\Models\User;
use App\Services\Assets\AssetCategoryService;
use App\Services\Assets\DepreciationRunService;
use App\Services\Assets\FixedAssetService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    $this->branch = Branch::factory()->create(['status' => 'publish']);
    $this->maker = assetUser(SystemRole::Accountant, $this->branch);
    $this->approver = assetUser(SystemRole::Approver, $this->branch);
    $this->assetLedger = Account::factory()->create();
    $this->accumulatedLedger = Account::factory()->create(['normal_balance' => 'credit']);
    $this->expenseLedger = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $this->category = AssetCategory::factory()->create([
        'asset_account_id' => $this->assetLedger->id, 'accumulated_depreciation_account_id' => $this->accumulatedLedger->id,
        'depreciation_expense_account_id' => $this->expenseLedger->id, 'default_useful_life_months' => 12,
    ]);
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
    $this->actingAs($this->maker);
});

function assetUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

function assetSetting(string $key, string $value): void
{
    AppSetting::query()->where('key', $key)->first()->update(['value' => $value]);
}

function assetApprovePolicy(): void
{
    assetSetting('assets.depreciation_policy_approved', '1');
}

/** Open accounting period for a month of 2026. */
function assetPeriod(object $test, int $month): AccountingPeriod
{
    $start = Carbon::create(2026, $month, 1);

    return AccountingPeriod::query()->where('fiscal_year_id', $test->year->id)->where('period_number', $month)->first()
        ?? AccountingPeriod::factory()->create([
            'fiscal_year_id' => $test->year->id, 'name' => $start->format('F Y'), 'period_number' => $month,
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString(), 'status' => 'open',
        ]);
}

function assetPayload(object $test, array $overrides = []): array
{
    return [
        'name' => 'Therapy treadmill', 'category_id' => $test->category->id, 'branch_id' => $test->branch->id,
        'acquisition_date' => '2026-01-01', 'in_service_date' => '2026-01-01', 'acquisition_cost' => '12000.0000',
        'residual_value' => '0.0000', 'useful_life_months' => 12, 'opening_accumulated_depreciation' => '0.0000',
        'location' => 'Gym', 'custodian' => 'Physio lead', ...$overrides,
    ];
}

function assetActive(object $test, array $overrides = []): FixedAsset
{
    $service = app(FixedAssetService::class);

    return $service->activate($service->register(assetPayload($test, $overrides), $test->maker), $test->maker);
}

function assetApprovedRun(object $test, AccountingPeriod $period): DepreciationRun
{
    $service = app(DepreciationRunService::class);
    $run = $service->prepare($test->branch->id, $period->id, $test->maker);
    $service->submit($run, $test->maker);

    return $service->approve($run->fresh(), $test->approver);
}

function assetPostedRun(object $test, AccountingPeriod $period): DepreciationRun
{
    return app(DepreciationRunService::class)->post(assetApprovedRun($test, $period), $test->maker);
}

test('asset is registered as a coded draft and neither registration nor activation posts a journal', function () {
    $this->post(route('fixed-assets.store'), assetPayload($this))->assertRedirect();
    $asset = FixedAsset::query()->firstOrFail();

    expect($asset->status)->toBe(FixedAssetStatus::Draft)
        ->and($asset->asset_code)->toBe('FA-000001')
        ->and($asset->acquisition_cost)->toBe('12000.0000')
        ->and($asset->asset_account_id)->toBe($this->assetLedger->id)
        ->and($asset->accumulated_depreciation_account_id)->toBe($this->accumulatedLedger->id)
        ->and($asset->depreciation_expense_account_id)->toBe($this->expenseLedger->id)
        ->and($asset->events()->where('event', 'registered')->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'fixed_assets.registered')->exists())->toBeTrue()
        ->and(JournalEntry::query()->count())->toBe(0);

    $this->post(route('fixed-assets.activate', $asset))->assertRedirect();

    expect($asset->fresh()->status)->toBe(FixedAssetStatus::Active)
        ->and($asset->fresh()->netBookValue())->toBe('12000.0000')
        ->and(app(FixedAssetService::class)->register(assetPayload($this), $this->maker)->asset_code)->toBe('FA-000002')
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('category accounts must be an asset ledger, a contra-asset ledger and an expense ledger', function () {
    $service = app(AssetCategoryService::class);
    $payload = fn (array $overrides = []): array => [
        'code' => 'equip', 'name' => 'Equipment', 'default_useful_life_months' => 60, 'asset_account_id' => $this->assetLedger->id,
        'accumulated_depreciation_account_id' => $this->accumulatedLedger->id, 'depreciation_expense_account_id' => $this->expenseLedger->id, ...$overrides,
    ];
    $debitAsset = Account::factory()->create();
    $inactiveContra = Account::factory()->create(['normal_balance' => 'credit', 'is_active' => false]);
    $liability = Account::factory()->create(['account_type' => 'liability', 'normal_balance' => 'credit']);

    expect(fn () => $service->create($payload(['accumulated_depreciation_account_id' => $debitAsset->id]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->create($payload(['accumulated_depreciation_account_id' => $inactiveContra->id]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->create($payload(['asset_account_id' => $this->accumulatedLedger->id]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->create($payload(['depreciation_expense_account_id' => $liability->id]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->create($payload(['depreciation_expense_account_id' => $this->assetLedger->id]), $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->create($payload(), $this->approver))->toThrow(HttpException::class)
        ->and(AssetCategory::query()->where('code', 'EQUIP')->exists())->toBeFalse();

    $this->post(route('asset-categories.store'), $payload())->assertRedirect(route('asset-categories.index'));
    $this->post(route('asset-categories.store'), $payload())->assertSessionHasErrors('code');

    expect(AssetCategory::query()->where('code', 'EQUIP')->count())->toBe(1);
});

test('straight-line depreciation absorbs the rounding remainder and ends exactly at residual value', function () {
    assetApprovePolicy();
    $asset = assetActive($this, ['acquisition_cost' => '10000', 'residual_value' => '1000', 'useful_life_months' => 7]);

    $amounts = [];
    foreach (range(1, 7) as $month) {
        $amounts[] = assetPostedRun($this, assetPeriod($this, $month))->lines()->first()->depreciation_amount;
    }
    $asset->refresh();

    expect($amounts)->toBe(['1285.7143', '1285.7143', '1285.7143', '1285.7143', '1285.7143', '1285.7143', '1285.7142'])
        ->and($asset->accumulatedDepreciation())->toBe('9000.0000')
        ->and($asset->accumulatedDepreciation())->toBe($asset->depreciableAmount())
        ->and($asset->netBookValue())->toBe('1000.0000')
        ->and($asset->status)->toBe(FixedAssetStatus::FullyDepreciated)
        ->and(fn () => app(DepreciationRunService::class)->prepare($this->branch->id, assetPeriod($this, 8)->id, $this->maker))->toThrow(ValidationException::class);
});

test('a monthly amount that rounds down still sums exactly to the depreciable amount', function () {
    $asset = assetActive($this, ['acquisition_cost' => '1000', 'useful_life_months' => 3]);
    $projection = collect(app(FixedAssetService::class)->schedule($asset)['projection']);

    expect($projection->pluck('amount')->all())->toBe(['333.3333', '333.3333', '333.3334'])
        ->and($projection->last()['net_book_value_after'])->toBe('0.0000');
});

test('each convention places the first and last period correctly', function (string $convention, bool $januaryLine, array $expected) {
    assetSetting('assets.depreciation_convention', $convention);
    $asset = assetActive($this, ['acquisition_cost' => '3720', 'in_service_date' => '2026-01-16']);

    $projection = collect(app(FixedAssetService::class)->schedule($asset)['projection']);
    $preview = app(DepreciationRunService::class)->preview($this->branch, assetPeriod($this, 1), $this->maker);

    expect($projection->count())->toBe($expected['periods'])
        ->and($projection->first()['period_start']->format('Y-m'))->toBe($expected['first_month'])
        ->and($projection->first()['amount'])->toBe($expected['first_amount'])
        ->and($projection->last()['period_start']->format('Y-m'))->toBe($expected['last_month'])
        ->and($projection->last()['amount'])->toBe($expected['last_amount'])
        ->and($projection->last()['accumulated_after'])->toBe('3720.0000')
        ->and($projection->last()['net_book_value_after'])->toBe('0.0000')
        ->and(count($preview['lines']))->toBe($januaryLine ? 1 : 0)
        ->and($preview['total'])->toBe($januaryLine ? $expected['first_amount'] : '0.0000')
        ->and(DepreciationRun::query()->count())->toBe(0);
})->with([
    'full month' => ['full_month', true, ['periods' => 12, 'first_month' => '2026-01', 'first_amount' => '310.0000', 'last_month' => '2026-12', 'last_amount' => '310.0000']],
    'following month' => ['following_month', false, ['periods' => 12, 'first_month' => '2026-02', 'first_amount' => '310.0000', 'last_month' => '2027-01', 'last_amount' => '310.0000']],
    'daily pro rata' => ['daily_pro_rata', true, ['periods' => 13, 'first_month' => '2026-01', 'first_amount' => '160.0000', 'last_month' => '2027-01', 'last_amount' => '150.0000']],
]);

test('residual value, opening depreciation, life and dates are restricted', function (array $overrides, string $field) {
    expect(fn () => app(FixedAssetService::class)->register(assetPayload($this, $overrides), $this->maker))->toThrow(ValidationException::class)
        ->and(FixedAsset::query()->count())->toBe(0);
    $this->post(route('fixed-assets.store'), assetPayload($this, $overrides))->assertSessionHasErrors($field);
})->with([
    'residual above cost' => [['residual_value' => '12000.0001'], 'residual_value'],
    'negative residual' => [['residual_value' => '-1'], 'residual_value'],
    'zero cost' => [['acquisition_cost' => '0'], 'acquisition_cost'],
    'zero life' => [['useful_life_months' => 0], 'useful_life_months'],
    'opening above depreciable amount' => [['residual_value' => '2000', 'opening_accumulated_depreciation' => '10000.0001'], 'opening_accumulated_depreciation'],
    'in service before acquisition' => [['acquisition_date' => '2026-02-01', 'in_service_date' => '2026-01-31'], 'in_service_date'],
]);

test('a purchase bill of another branch cannot be linked', function () {
    $foreignBill = PurchaseBill::factory()->create();
    $ownBill = PurchaseBill::factory()->create(['branch_id' => $this->branch->id]);

    expect(fn () => app(FixedAssetService::class)->register(assetPayload($this, ['purchase_bill_id' => $foreignBill->id]), $this->maker))->toThrow(ValidationException::class)
        ->and(app(FixedAssetService::class)->register(assetPayload($this, ['purchase_bill_id' => $ownBill->id]), $this->maker)->purchase_bill_id)->toBe($ownBill->id);
});

test('opening accumulated depreciation counts and only the remainder is depreciated', function () {
    assetApprovePolicy();
    $asset = assetActive($this, ['acquisition_date' => '2025-01-01', 'in_service_date' => '2025-01-01', 'opening_accumulated_depreciation' => '11500']);

    expect($asset->accumulatedDepreciation())->toBe('11500.0000')->and($asset->netBookValue())->toBe('500.0000');

    $line = assetPostedRun($this, assetPeriod($this, 1))->lines()->first();

    expect($line->accumulated_before)->toBe('11500.0000')
        ->and($line->depreciation_amount)->toBe('500.0000')
        ->and($line->net_book_value_after)->toBe('0.0000')
        ->and($asset->fresh()->accumulatedDepreciation())->toBe('12000.0000')
        ->and($asset->fresh()->status)->toBe(FixedAssetStatus::FullyDepreciated);
});

test('the same asset cannot be depreciated twice for one period', function () {
    $asset = assetActive($this);
    $period = assetPeriod($this, 1);
    $run = app(DepreciationRunService::class)->prepare($this->branch->id, $period->id, $this->maker);

    expect(fn () => app(DepreciationRunService::class)->prepare($this->branch->id, $period->id, $this->maker))->toThrow(ValidationException::class)
        ->and(DepreciationRun::query()->count())->toBe(1)
        ->and(DepreciationRunLine::query()->count())->toBe(1);

    $duplicate = fn (?string $activeKey) => DepreciationRunLine::query()->create([
        'depreciation_run_id' => $run->id, 'fixed_asset_id' => $asset->id, 'accounting_period_id' => $period->id, 'depreciation_amount' => '1000.0000',
        'accumulated_before' => '0.0000', 'accumulated_after' => '1000.0000', 'net_book_value_after' => '11000.0000', 'active_key' => $activeKey,
    ]);

    expect(fn () => $duplicate(DepreciationRunLine::ACTIVE))->toThrow(UniqueConstraintViolationException::class)
        ->and($duplicate(null)->exists)->toBeTrue();
});

test('posting is blocked while the depreciation policy is not approved', function () {
    $asset = assetActive($this);
    $run = assetApprovedRun($this, assetPeriod($this, 1));

    expect(fn () => app(DepreciationRunService::class)->post($run, $this->maker))->toThrow(ValidationException::class, 'assets.depreciation_policy_approved');
    $this->post(route('depreciation-runs.post', $run))->assertSessionHasErrors('policy');
    $this->get(route('depreciation-runs.show', $run))->assertOk()->assertSee('Depreciation policy not approved');

    expect($run->fresh()->status)->toBe(DepreciationRunStatus::Approved)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and($asset->fresh()->accumulatedDepreciation())->toBe('0.0000');
});

test('a closed period rejects preparing and posting depreciation', function () {
    assetApprovePolicy();
    assetActive($this);
    $closed = assetPeriod($this, 2);
    $closed->update(['status' => 'closed']);

    expect(fn () => app(DepreciationRunService::class)->prepare($this->branch->id, $closed->id, $this->maker))->toThrow(ValidationException::class);

    $period = assetPeriod($this, 1);
    $run = assetApprovedRun($this, $period);
    $period->update(['status' => 'closed']);

    expect(fn () => app(DepreciationRunService::class)->post($run, $this->maker))->toThrow(ValidationException::class)
        ->and($run->fresh()->status)->toBe(DepreciationRunStatus::Approved)
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('a posted run creates one balanced journal on the asset ledgers', function () {
    assetApprovePolicy();
    $otherExpense = Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure']);
    $otherCategory = AssetCategory::factory()->create(['asset_account_id' => $this->assetLedger->id, 'accumulated_depreciation_account_id' => $this->accumulatedLedger->id, 'depreciation_expense_account_id' => $otherExpense->id]);
    $first = assetActive($this);
    $second = assetActive($this, ['acquisition_cost' => '6000']);
    $third = assetActive($this, ['acquisition_cost' => '2400', 'category_id' => $otherCategory->id]);
    app(FixedAssetService::class)->register(assetPayload($this), $this->maker);
    $period = assetPeriod($this, 1);

    $run = assetPostedRun($this, $period);
    $journal = JournalEntry::query()->with(['lines', 'voucherType'])->sole();

    expect($run->status)->toBe(DepreciationRunStatus::Posted)
        ->and($run->journal_entry_id)->toBe($journal->id)
        ->and($run->total_amount)->toBe('1700.0000')
        ->and($run->lines()->count())->toBe(3)
        ->and($journal->status)->toBe(JournalStatus::Posted)
        ->and($journal->voucherType->code)->toBe('JV')
        ->and($journal->source_system)->toBe('accounting')
        ->and($journal->source_type)->toBe('depreciation_run')
        ->and($journal->source_key)->toBe('depreciation_run:'.$run->id)
        ->and($journal->transaction_date->toDateString())->toBe('2026-01-31')
        ->and($journal->branch_id)->toBe($this->branch->id)
        ->and($journal->total_debit)->toBe('1700.0000')
        ->and($journal->total_credit)->toBe('1700.0000')
        ->and($journal->lines->map(fn ($line) => [$line->account_id, (string) $line->debit, (string) $line->credit])->all())->toBe([
            [$this->expenseLedger->id, '1500.0000', '0.0000'],
            [$otherExpense->id, '200.0000', '0.0000'],
            [$this->accumulatedLedger->id, '0.0000', '1700.0000'],
        ])
        ->and($first->fresh()->accumulatedDepreciation())->toBe('1000.0000')
        ->and($second->fresh()->netBookValue())->toBe('5500.0000')
        ->and($third->fresh()->events()->where('event', 'depreciated')->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'depreciation.posted')->exists())->toBeTrue()
        ->and(fn () => $run->fresh()->update(['total_amount' => '1.0000']))->toThrow(LogicException::class);
});

test('posting is refused when an asset changed after approval', function () {
    assetApprovePolicy();
    $asset = assetActive($this);
    assetActive($this, ['acquisition_cost' => '6000']);
    $run = assetApprovedRun($this, assetPeriod($this, 1));
    app(FixedAssetService::class)->retire($asset, 'Damaged beyond repair', $this->maker);

    expect(fn () => app(DepreciationRunService::class)->post($run, $this->maker))->toThrow(ValidationException::class)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and($asset->fresh()->status)->toBe(FixedAssetStatus::Retired);
});

test('the user who prepared or submitted a run cannot approve it', function () {
    assetActive($this);
    $this->maker->givePermissionTo('depreciation.approve');
    $service = app(DepreciationRunService::class);
    $run = $service->prepare($this->branch->id, assetPeriod($this, 1)->id, $this->maker);
    $service->submit($run, $this->maker);

    expect(fn () => $service->approve($run->fresh(), $this->maker))->toThrow(ValidationException::class)
        ->and($run->fresh()->status)->toBe(DepreciationRunStatus::Submitted)
        ->and($service->approve($run->fresh(), $this->approver)->approved_by)->toBe($this->approver->id);
});

test('users without the posting permissions cannot post depreciation', function () {
    assetApprovePolicy();
    assetActive($this);
    $run = assetApprovedRun($this, assetPeriod($this, 1));
    $viewer = assetUser(SystemRole::Auditor, $this->branch);

    $this->actingAs($this->approver)->post(route('depreciation-runs.post', $run))->assertForbidden();
    $this->actingAs($viewer)->post(route('depreciation-runs.post', $run))->assertForbidden();
    $this->actingAs($viewer)->post(route('depreciation-runs.store'), ['branch_id' => $this->branch->id, 'accounting_period_id' => $run->accounting_period_id])->assertForbidden();
    $this->actingAs($viewer)->post(route('fixed-assets.store'), assetPayload($this))->assertForbidden();
    $this->actingAs($viewer)->get(route('depreciation-runs.show', $run))->assertOk();

    expect(fn () => app(DepreciationRunService::class)->post($run, $this->approver))->toThrow(HttpException::class)
        ->and($run->fresh()->status)->toBe(DepreciationRunStatus::Approved)
        ->and(JournalEntry::query()->count())->toBe(0);
});

test('cross-branch access to assets, documents and runs is rejected', function () {
    Storage::fake('local');
    $asset = assetActive($this);
    $document = app(FixedAssetService::class)->addDocument($asset, UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'), $this->maker);
    $run = app(DepreciationRunService::class)->prepare($this->branch->id, assetPeriod($this, 1)->id, $this->maker);
    $otherBranch = Branch::factory()->create(['status' => 'publish']);
    $outsider = assetUser(SystemRole::Accountant, $otherBranch);

    $this->actingAs($outsider)->get(route('fixed-assets.show', $asset))->assertForbidden();
    $this->actingAs($outsider)->get(route('fixed-assets.index'))->assertOk()->assertDontSee($asset->asset_code);
    $this->actingAs($outsider)->get(route('fixed-assets.register'))->assertOk()->assertDontSee($asset->asset_code);
    $this->actingAs($outsider)->get(route('fixed-assets.register', ['branch_id' => $this->branch->id]))->assertForbidden();
    $this->actingAs($outsider)->get(route('fixed-assets.documents.download', [$asset, $document]))->assertForbidden();
    $this->actingAs($outsider)->put(route('fixed-assets.update', $asset), ['name' => 'Hijacked'])->assertForbidden();
    $this->actingAs($outsider)->post(route('fixed-assets.retire', $asset), ['reason' => 'Not my asset'])->assertForbidden();
    $this->actingAs($outsider)->get(route('depreciation-runs.show', $run))->assertForbidden();
    $this->actingAs($outsider)->get(route('depreciation-runs.index'))->assertOk()->assertDontSee('#'.$run->id.'</code>', false);
    $this->actingAs($outsider)->post(route('depreciation-runs.submit', $run))->assertForbidden();
    $this->actingAs($outsider)->post(route('depreciation-runs.store'), ['branch_id' => $this->branch->id, 'accounting_period_id' => $run->accounting_period_id])->assertForbidden();

    expect(fn () => app(FixedAssetService::class)->register(assetPayload($this), $outsider))->toThrow(HttpException::class)
        ->and(fn () => app(DepreciationRunService::class)->preview($this->branch, assetPeriod($this, 1), $outsider))->toThrow(HttpException::class)
        ->and($asset->fresh()->name)->toBe('Therapy treadmill')
        ->and($asset->fresh()->status)->toBe(FixedAssetStatus::Active)
        ->and($run->fresh()->status)->toBe(DepreciationRunStatus::Draft);
});

test('financial terms of an active asset are frozen while descriptive details stay editable', function () {
    $asset = assetActive($this);
    $service = app(FixedAssetService::class);

    expect(fn () => $service->update($asset, ['name' => 'Treadmill', 'acquisition_cost' => '15000'], $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->update($asset, ['name' => 'Treadmill', 'useful_life_months' => 24], $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->update($asset, ['name' => 'Treadmill', 'in_service_date' => '2026-02-01'], $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $asset->fresh()->update(['residual_value' => '500.0000']))->toThrow(LogicException::class)
        ->and(fn () => $asset->fresh()->update(['depreciation_expense_account_id' => $this->assetLedger->id]))->toThrow(LogicException::class)
        ->and(fn () => $asset->fresh()->update(['asset_code' => 'FA-999999']))->toThrow(LogicException::class)
        ->and(fn () => $asset->fresh()->delete())->toThrow(LogicException::class);

    $this->put(route('fixed-assets.update', $asset), ['name' => 'Treadmill (gym 2)', 'location' => 'Gym 2', 'acquisition_cost' => '1.0000'])->assertRedirect(route('fixed-assets.show', $asset));

    expect($asset->fresh()->name)->toBe('Treadmill (gym 2)')
        ->and($asset->fresh()->location)->toBe('Gym 2')
        ->and($asset->fresh()->acquisition_cost)->toBe('12000.0000');
});

test('a draft asset can be edited and retirement posts nothing', function () {
    $service = app(FixedAssetService::class);
    $asset = $service->register(assetPayload($this), $this->maker);

    $this->put(route('fixed-assets.update', $asset), assetPayload($this, ['acquisition_cost' => '9000', 'useful_life_months' => 9]))->assertRedirect();
    expect($asset->fresh()->acquisition_cost)->toBe('9000.0000')
        ->and(fn () => $service->retire($asset->fresh(), 'Never used', $this->maker))->toThrow(ValidationException::class);

    $service->activate($asset->fresh(), $this->maker);
    $this->post(route('fixed-assets.retire', $asset), ['reason' => 'Sold to a third party'])->assertRedirect();

    expect($asset->fresh()->status)->toBe(FixedAssetStatus::Retired)
        ->and(JournalEntry::query()->count())->toBe(0)
        ->and(app(DepreciationRunService::class)->preview($this->branch, assetPeriod($this, 1), $this->maker)['lines'])->toBe([])
        ->and(fn () => $service->update($asset->fresh(), ['name' => 'Changed'], $this->maker))->toThrow(ValidationException::class);
});

test('reversal restores accumulated depreciation and lets the period be run again', function () {
    assetApprovePolicy();
    $asset = assetActive($this, ['acquisition_cost' => '2000', 'useful_life_months' => 2]);
    $service = app(DepreciationRunService::class);
    $january = assetPostedRun($this, assetPeriod($this, 1));
    $february = assetPostedRun($this, assetPeriod($this, 2));

    expect($asset->fresh()->status)->toBe(FixedAssetStatus::FullyDepreciated)
        ->and(fn () => $service->reverse($january, 'Wrong useful life', $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => $service->reverse($february, 'Wrong useful life', $this->approver))->toThrow(HttpException::class);

    $this->post(route('depreciation-runs.reverse', $february), ['reason' => 'Wrong useful life', 'reversal_date' => '2026-02-28'])->assertRedirect();
    $february->refresh();

    expect($february->status)->toBe(DepreciationRunStatus::Reversed)
        ->and($february->lines()->whereNotNull('active_key')->count())->toBe(0)
        ->and($february->journalEntry->status)->toBe(JournalStatus::Reversed)
        ->and(JournalEntry::query()->where('reversal_of_id', $february->journal_entry_id)->where('status', 'posted')->count())->toBe(1)
        ->and($asset->fresh()->accumulatedDepreciation())->toBe('1000.0000')
        ->and($asset->fresh()->status)->toBe(FixedAssetStatus::Active)
        ->and($asset->events()->where('event', 'depreciation_reversed')->count())->toBe(1)
        ->and(fn () => $february->update(['total_amount' => '5.0000']))->toThrow(LogicException::class);

    $rerun = assetPostedRun($this, assetPeriod($this, 2));

    expect($rerun->id)->not->toBe($february->id)
        ->and($rerun->status)->toBe(DepreciationRunStatus::Posted)
        ->and($asset->fresh()->accumulatedDepreciation())->toBe('2000.0000')
        ->and($asset->fresh()->status)->toBe(FixedAssetStatus::FullyDepreciated);
});

test('a rejected run returns to draft, releases its assets and is recalculated on resubmission', function () {
    $asset = assetActive($this);
    $service = app(DepreciationRunService::class);
    $run = $service->prepare($this->branch->id, assetPeriod($this, 1)->id, $this->maker);
    $service->submit($run, $this->maker);

    $this->actingAs($this->approver)->post(route('depreciation-runs.reject', $run), ['reason' => 'Check the asset list'])->assertRedirect();

    expect($run->fresh()->status)->toBe(DepreciationRunStatus::Draft)
        ->and($run->fresh()->rejection_reason)->toBe('Check the asset list')
        ->and($run->lines()->whereNotNull('active_key')->count())->toBe(0);

    assetActive($this, ['acquisition_cost' => '6000']);
    $service->submit($run->fresh(), $this->maker);

    expect($run->fresh()->status)->toBe(DepreciationRunStatus::Submitted)
        ->and($run->fresh()->total_amount)->toBe('1500.0000')
        ->and($run->lines()->where('active_key', DepreciationRunLine::ACTIVE)->count())->toBe(2)
        ->and($asset->fresh()->accumulatedDepreciation())->toBe('0.0000');
});

test('asset history is append-only', function () {
    $asset = assetActive($this);
    $event = $asset->events()->firstOrFail();

    expect($asset->events()->pluck('event')->all())->toBe(['registered', 'activated'])
        ->and(fn () => $event->update(['details' => 'Rewritten']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class)
        ->and(FixedAssetEvent::query()->count())->toBe(2);
});

test('the capitalization threshold refuses activation once the policy is approved', function () {
    assetSetting('assets.capitalization_threshold', '5000.00');
    $service = app(FixedAssetService::class);
    $small = $service->register(assetPayload($this, ['acquisition_cost' => '4999.9999']), $this->maker);

    assetApprovePolicy();

    expect(fn () => $service->activate($small, $this->maker))->toThrow(ValidationException::class)
        ->and($small->fresh()->status)->toBe(FixedAssetStatus::Draft)
        ->and($service->activate($service->register(assetPayload($this, ['acquisition_cost' => '5000']), $this->maker), $this->maker)->status)->toBe(FixedAssetStatus::Active);

    assetSetting('assets.depreciation_policy_approved', '0');

    expect($service->activate($small->fresh(), $this->maker)->status)->toBe(FixedAssetStatus::Active);
});

test('supporting documents are validated, stored privately and downloadable', function () {
    Storage::fake('local');
    $asset = assetActive($this);

    $this->post(route('fixed-assets.documents.store', $asset), ['document' => UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream')])->assertSessionHasErrors('document');
    $this->post(route('fixed-assets.documents.store', $asset), ['document' => UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')])->assertSessionHasErrors('document');
    $this->post(route('fixed-assets.documents.store', $asset), ['document' => UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf')])->assertRedirect();
    $document = $asset->documents()->sole();

    Storage::disk('local')->assertExists($document->path);
    expect($document->original_name)->toBe('invoice.pdf')
        ->and($document->uploaded_by)->toBe($this->maker->id)
        ->and($asset->events()->where('event', 'document_added')->count())->toBe(1);
    $this->get(route('fixed-assets.documents.download', [$asset, $document]))->assertOk()->assertDownload('invoice.pdf');
});

test('asset screens render real figures and the policy warning', function () {
    assetApprovePolicy();
    $asset = assetActive($this);
    $run = assetPostedRun($this, assetPeriod($this, 1));
    assetSetting('assets.depreciation_policy_approved', '0');

    $this->get(route('asset-categories.index'))->assertOk()->assertSee($this->category->code);
    $this->get(route('asset-categories.create'))->assertOk();
    $this->get(route('asset-categories.edit', $this->category))->assertOk();
    $this->get(route('fixed-assets.index', ['search' => $asset->asset_code, 'status' => 'active']))->assertOk()->assertSee($asset->asset_code)->assertSee('11,000.00')->assertSee('Depreciation policy not approved');
    $this->get(route('fixed-assets.create'))->assertOk();
    $this->get(route('fixed-assets.edit', $asset))->assertOk();
    $this->get(route('fixed-assets.show', $asset))->assertOk()->assertSee('Projection only')->assertSee('11,000.00')->assertSee('January 2026');
    $this->get(route('fixed-assets.register', ['branch_id' => $this->branch->id]))->assertOk()->assertSee($asset->asset_code)->assertSee('1,000.00')->assertSee('11,000.00');
    $this->get(route('depreciation-runs.index', ['status' => 'posted']))->assertOk()->assertSee('January 2026');
    $this->get(route('depreciation-runs.create', ['branch_id' => $this->branch->id, 'accounting_period_id' => assetPeriod($this, 2)->id]))->assertOk()->assertSee('Preview only')->assertSee($asset->asset_code);
    $this->get(route('depreciation-runs.show', $run))->assertOk()->assertSee($run->journalEntry->voucher_number);
});
