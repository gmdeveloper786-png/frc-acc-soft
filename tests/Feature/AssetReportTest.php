<?php

use App\Enums\DepreciationRunStatus;
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
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Assets\DepreciationRunService;
use App\Services\Assets\FixedAssetService;
use App\Services\Reports\Operational\AssetReportService;
use App\Services\Reports\Operational\OperationalReport;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\VoucherTypeSeeder;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->travelTo('2026-03-15 10:00:00');
    $this->seed([AppSettingSeeder::class, RolePermissionSeeder::class, VoucherTypeSeeder::class]);
    AppSetting::query()->where('key', 'assets.depreciation_policy_approved')->first()->update(['value' => '1']);
    $this->branch = Branch::factory()->create(['status' => 'publish', 'name' => 'Alpha Branch']);
    $this->otherBranch = Branch::factory()->create(['status' => 'publish', 'name' => 'Beta Branch']);
    $this->maker = assetreportUser(SystemRole::Accountant, $this->branch);
    $this->approver = assetreportUser(SystemRole::Approver, $this->branch);
    $this->admin = assetreportUser(SystemRole::Administrator, $this->branch);
    $this->category = assetreportCategory('EQP', 'Equipment');
    $this->year = FiscalYear::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
    $this->actingAs($this->maker);

    // A: 12,000 over 12 months = 1,000 a month. B: 6,000 over 12 months = 500 a month, with 1,500 opening accumulated depreciation.
    $this->assetA = assetreportAsset($this, ['name' => 'Therapy treadmill']);
    $this->assetB = assetreportAsset($this, ['name' => 'Hydrotherapy pump', 'acquisition_cost' => '6000.0000', 'opening_accumulated_depreciation' => '1500.0000']);
});

function assetreportUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

function assetreportCategory(string $code, string $name): AssetCategory
{
    return AssetCategory::factory()->create([
        'code' => $code, 'name' => $name, 'default_useful_life_months' => 12,
        'asset_account_id' => Account::factory()->create()->id,
        'accumulated_depreciation_account_id' => Account::factory()->create(['normal_balance' => 'credit'])->id,
        'depreciation_expense_account_id' => Account::factory()->create(['account_type' => 'expense', 'financial_statement' => 'income_expenditure'])->id,
    ]);
}

function assetreportPeriod(object $test, int $month): AccountingPeriod
{
    $start = Carbon::create(2026, $month, 1);

    return AccountingPeriod::query()->where('fiscal_year_id', $test->year->id)->where('period_number', $month)->first()
        ?? AccountingPeriod::factory()->create([
            'fiscal_year_id' => $test->year->id, 'name' => $start->format('F Y'), 'period_number' => $month,
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString(), 'status' => 'open',
        ]);
}

function assetreportAsset(object $test, array $overrides = [], ?User $actor = null): FixedAsset
{
    $actor ??= $test->maker;
    $service = app(FixedAssetService::class);

    return $service->activate($service->register([
        'name' => 'Asset', 'category_id' => $test->category->id, 'branch_id' => $test->branch->id,
        'acquisition_date' => '2026-01-01', 'in_service_date' => '2026-01-01', 'acquisition_cost' => '12000.0000',
        'residual_value' => '0.0000', 'useful_life_months' => 12, 'opening_accumulated_depreciation' => '0.0000',
        'location' => 'Gym', 'custodian' => 'Physio lead', ...$overrides,
    ], $actor), $actor);
}

function assetreportApprovedRun(object $test, int $month): DepreciationRun
{
    $service = app(DepreciationRunService::class);
    $run = $service->prepare($test->branch->id, assetreportPeriod($test, $month)->id, $test->maker);
    $service->submit($run, $test->maker);

    return $service->approve($run->fresh(), $test->approver);
}

function assetreportPostedRun(object $test, int $month): DepreciationRun
{
    return app(DepreciationRunService::class)->post(assetreportApprovedRun($test, $month), $test->maker);
}

function assetreportBuild(string $report, User $user, array $filters = []): OperationalReport
{
    return app(AssetReportService::class)->build($report, $user, $filters);
}

/** @return array<string, mixed> */
function assetreportRow(OperationalReport $report, string $code): array
{
    return collect($report->rows)->firstWhere('asset_code', $code);
}

test('register shows exact cost, accumulated depreciation and net book value from opening plus posted runs', function () {
    assetreportPostedRun($this, 1);
    assetreportPostedRun($this, 2);

    $report = assetreportBuild('register', $this->maker, ['as_of' => '2026-02-28']);
    $a = assetreportRow($report, $this->assetA->asset_code);
    $b = assetreportRow($report, $this->assetB->asset_code);

    expect([$a['cost'], $a['accumulated'], $a['net_book_value']])->toBe(['12000.0000', '2000.0000', '10000.0000'])
        ->and([$b['cost'], $b['accumulated'], $b['net_book_value']])->toBe(['6000.0000', '2500.0000', '3500.0000'])
        ->and($report->totals[0])->toMatchArray(['cost' => '18000.0000', 'accumulated' => '4500.0000', 'net_book_value' => '13500.0000'])
        ->and($a['accumulated'])->toBe($this->assetA->fresh()->accumulatedDepreciation())
        ->and($b['net_book_value'])->toBe($this->assetB->fresh()->netBookValue());

    $nbv = assetreportRow(assetreportBuild('net-book-value', $this->maker, ['as_of' => '2026-02-28']), $this->assetB->asset_code);
    expect([$nbv['opening'], $nbv['posted'], $nbv['accumulated'], $nbv['remaining']])->toBe(['1500.0000', '1000.0000', '2500.0000', '3500.0000']);

    $this->get(route('reports.assets.register', ['as_of' => '2026-02-28']))->assertOk()
        ->assertSee('Fixed Asset Register')->assertSee($this->assetA->asset_code)->assertSee('10,000.00')->assertSee('13,500.00')
        ->assertDontSee('Depreciation policy not approved');
    $this->get(route('reports.assets.net-book-value', ['as_of' => '2026-02-28']))->assertOk()->assertSee('3,500.00');
    $this->get(route('reports.assets.index'))->assertOk()->assertSee('Missed-Period / Catch-up Exception Report');
});

test('a run for a period ending after the as-of date is excluded', function () {
    assetreportPostedRun($this, 1);
    assetreportPostedRun($this, 2);

    $endOfJanuary = assetreportBuild('register', $this->maker, ['as_of' => '2026-01-31']);
    $beforeJanuaryEnd = assetreportBuild('register', $this->maker, ['as_of' => '2026-01-30']);

    expect(assetreportRow($endOfJanuary, $this->assetA->asset_code)['accumulated'])->toBe('1000.0000')
        ->and(assetreportRow($endOfJanuary, $this->assetB->asset_code)['accumulated'])->toBe('2000.0000')
        ->and(assetreportRow($beforeJanuaryEnd, $this->assetA->asset_code)['accumulated'])->toBe('0.0000')
        ->and(assetreportRow($beforeJanuaryEnd, $this->assetA->asset_code)['net_book_value'])->toBe('12000.0000')
        ->and(assetreportRow($beforeJanuaryEnd, $this->assetB->asset_code)['accumulated'])->toBe('1500.0000')
        ->and(assetreportBuild('register', $this->maker, ['as_of' => '2025-12-31'])->rows)->toBe([]);
});

test('reversed, draft and approved runs do not count', function () {
    assetreportPostedRun($this, 1);
    $february = assetreportPostedRun($this, 2);
    app(DepreciationRunService::class)->reverse($february, 'Wrong useful life', $this->maker);
    $march = assetreportApprovedRun($this, 3);

    $report = assetreportBuild('register', $this->maker, ['as_of' => '2026-12-31']);

    expect($february->fresh()->status)->toBe(DepreciationRunStatus::Reversed)
        ->and($march->fresh()->status)->toBe(DepreciationRunStatus::Approved)
        ->and(DepreciationRunLine::query()->where('depreciation_run_id', $march->id)->count())->toBe(2)
        ->and(assetreportRow($report, $this->assetA->asset_code)['accumulated'])->toBe('1000.0000')
        ->and(assetreportRow($report, $this->assetB->asset_code)['accumulated'])->toBe('2000.0000')
        ->and($report->totals[0]['net_book_value'])->toBe('15000.0000');

    // A draft run for the same assets still does not count.
    app(DepreciationRunService::class)->reject($march->fresh(), 'Recalculate', $this->approver);
    expect($march->fresh()->status)->toBe(DepreciationRunStatus::Draft)
        ->and(assetreportBuild('register', $this->maker, ['as_of' => '2026-12-31'])->totals[0]['accumulated'])->toBe('3000.0000');
});

test('category and branch summaries equal the sum of their assets', function () {
    $vehicles = assetreportCategory('VEH', 'Vehicles');
    $this->admin->branches()->attach($this->otherBranch);
    assetreportAsset($this, ['name' => 'Patient van', 'category_id' => $vehicles->id, 'branch_id' => $this->otherBranch->id, 'acquisition_cost' => '24000.0000', 'opening_accumulated_depreciation' => '4000.0000'], $this->admin);
    assetreportPostedRun($this, 1);

    $filters = ['branch_id' => 'all', 'as_of' => '2026-01-31'];
    $register = assetreportBuild('register', $this->admin, $filters);
    $categories = collect(assetreportBuild('category-summary', $this->admin, $filters)->rows)->keyBy('code');
    $branchSummary = assetreportBuild('branch-summary', $this->admin, $filters);
    $branches = collect($branchSummary->rows)->keyBy('group');

    expect($categories['EQP'])->toMatchArray(['assets' => 2, 'cost' => '18000.0000', 'accumulated' => '3000.0000', 'net_book_value' => '15000.0000'])
        ->and($categories['VEH'])->toMatchArray(['assets' => 1, 'cost' => '24000.0000', 'accumulated' => '4000.0000', 'net_book_value' => '20000.0000'])
        ->and($branches['Alpha Branch'])->toMatchArray(['assets' => 2, 'cost' => '18000.0000', 'net_book_value' => '15000.0000'])
        ->and($branches['Beta Branch'])->toMatchArray(['assets' => 1, 'cost' => '24000.0000', 'net_book_value' => '20000.0000'])
        ->and($branchSummary->totals[0])->toMatchArray(['assets' => 3, 'cost' => '42000.0000', 'accumulated' => '7000.0000', 'net_book_value' => '35000.0000'])
        ->and(collect($register->totals[0])->only(['cost', 'accumulated', 'net_book_value'])->all())
        ->toBe(collect($branchSummary->totals[0])->only(['cost', 'accumulated', 'net_book_value'])->all())
        ->and(assetreportBuild('category-summary', $this->admin, [...$filters, 'category_id' => $vehicles->id])->totals[0]['cost'])->toBe('24000.0000');

    $this->actingAs($this->admin)->get(route('reports.assets.category-summary', $filters))->assertOk()->assertSee('Vehicles')->assertSee('35,000.00');
    $this->get(route('reports.assets.branch-summary', $filters))->assertOk()->assertSee('Beta Branch')->assertSee('All branches (consolidated)');
});

test('depreciation history lists posted and reversed runs and the schedule labels its projection', function () {
    $january = assetreportPostedRun($this, 1);
    $february = assetreportPostedRun($this, 2);
    app(DepreciationRunService::class)->reverse($february, 'Wrong useful life', $this->maker);
    assetreportApprovedRun($this, 2);

    $history = assetreportBuild('history', $this->maker);
    $rows = collect($history->rows)->keyBy('run');

    expect($history->rows)->toHaveCount(2)
        ->and($rows['#'.$january->id])->toMatchArray(['period' => 'January 2026', 'branch' => 'Alpha Branch', 'status' => 'Posted', 'total' => '1500.0000', 'voucher' => $january->journalEntry->voucher_number, 'posted_by' => $this->maker->name])
        ->and($rows['#'.$february->id])->toMatchArray(['status' => 'Reversed', 'reversed_by' => $this->maker->name, 'reversal_reason' => 'Wrong useful life'])
        ->and($history->totals[0]['total'])->toBe('1500.0000')
        ->and(assetreportBuild('history', $this->maker, ['status' => 'reversed'])->rows)->toHaveCount(1)
        ->and(assetreportBuild('history', $this->maker, ['date_from' => '2026-02-01'])->rows)->toHaveCount(1);

    $schedule = assetreportBuild('schedule', $this->maker, ['asset_id' => $this->assetB->id]);
    $basis = array_column($schedule->rows, 'basis');

    expect($basis[0])->toBe('Opening accumulated depreciation')
        ->and($basis[1])->toBe('Posted (run #'.$january->id.')')
        ->and($basis[2])->toBe('PROJECTION — not posted')
        ->and($schedule->rows[1]['accumulated_after'])->toBe('2000.0000')
        ->and($schedule->totals[1]['net_book_value_after'])->toBe('4000.0000')
        ->and(assetreportBuild('schedule', $this->maker)->prompt)->not->toBeNull();

    $this->get(route('reports.assets.history'))->assertOk()->assertSee('Reversed')->assertSee($january->journalEntry->voucher_number);
    $this->get(route('reports.assets.schedule', ['asset_id' => $this->assetB->id]))->assertOk()->assertSee('PROJECTION — not posted');
    $this->get(route('reports.assets.schedule'))->assertOk()->assertSee('Select an asset');
});

test('missed-period report detects a skipped period for review and posts nothing', function () {
    // January is skipped: depreciation is posted for February only.
    assetreportPeriod($this, 1);
    assetreportPostedRun($this, 2);
    $counts = [DepreciationRun::query()->count(), DepreciationRunLine::query()->count(), JournalEntry::query()->count()];

    $report = assetreportBuild('missed-periods', $this->maker);

    // Asset B carries opening accumulated depreciation and was activated in March, so earlier periods are covered by the opening figure.
    expect($report->rows)->toHaveCount(1)
        ->and($report->rows[0])->toMatchArray(['asset_code' => $this->assetA->asset_code, 'period' => 'January 2026', 'amount' => '1000.0000', 'latest_posted' => '28 Feb 2026'])
        ->and($report->totals[0]['amount'])->toBe('1000.0000')
        ->and($report->note)->toContain('For review — no catch-up policy is approved')
        ->and([DepreciationRun::query()->count(), DepreciationRunLine::query()->count(), JournalEntry::query()->count()])->toBe($counts);

    $this->get(route('reports.assets.missed-periods'))->assertOk()->assertSee('For review — no catch-up policy is approved')->assertSee('January 2026')->assertSee('1,000.00');
    expect([DepreciationRun::query()->count(), JournalEntry::query()->count()])->toBe([$counts[0], $counts[2]]);

    // A draft or approved January run does not cover the period.
    foreach ([DepreciationRunStatus::Draft, DepreciationRunStatus::Approved] as $index => $status) {
        $run = DepreciationRun::factory()->create(['branch_id' => $this->branch->id, 'accounting_period_id' => assetreportPeriod($this, 1)->id, 'status' => $status, 'total_amount' => '1000.0000', 'created_by' => $this->maker->id]);
        $run->lines()->create(['fixed_asset_id' => $this->assetA->id, 'accounting_period_id' => $run->accounting_period_id, 'depreciation_amount' => '1000.0000', 'accumulated_before' => '0.0000', 'accumulated_after' => '1000.0000', 'net_book_value_after' => '11000.0000', 'active_key' => $index === 0 ? null : DepreciationRunLine::ACTIVE]);
    }
    expect(assetreportBuild('missed-periods', $this->maker)->rows)->toHaveCount(1)
        ->and(assetreportRow(assetreportBuild('register', $this->maker), $this->assetA->asset_code)['accumulated'])->toBe('1000.0000');
});

test('missed-period report is empty when every period was posted and shows the policy banner when unapproved', function () {
    expect(assetreportBuild('missed-periods', $this->maker)->rows)->toBe([]);

    assetreportPostedRun($this, 1);
    assetreportPostedRun($this, 2);
    expect(assetreportBuild('missed-periods', $this->maker)->rows)->toBe([]);

    AppSetting::query()->where('key', 'assets.depreciation_policy_approved')->first()->update(['value' => '0']);
    $this->get(route('reports.assets.missed-periods'))->assertOk()->assertSee('No missed period')->assertSee('Depreciation policy not approved');
    expect(assetreportBuild('register', $this->maker)->note)->toContain('assets.depreciation_policy_approved');
});

test('asset reports enforce permissions, branch scope and the all-branch permission', function () {
    $stranger = User::factory()->create();
    $registerOnly = User::factory()->create();
    $registerOnly->givePermissionTo('fixed_assets.view');
    $registerOnly->branches()->attach($this->branch);
    $inactive = assetreportUser(SystemRole::Accountant, $this->branch);
    $inactive->update(['is_active' => false]);
    $foreign = assetreportAsset($this, ['name' => 'Beta bed', 'branch_id' => $this->otherBranch->id], $this->admin);

    $this->actingAs($stranger)->get(route('reports.assets.register'))->assertForbidden();
    $this->get(route('reports.assets.index'))->assertForbidden();
    $this->actingAs($registerOnly)->get(route('reports.assets.register'))->assertOk();
    $this->get(route('reports.assets.history'))->assertForbidden();
    $this->get(route('reports.assets.missed-periods'))->assertForbidden();
    $this->get(route('reports.assets.schedule', ['asset_id' => $this->assetA->id]))->assertForbidden();

    expect(fn () => assetreportBuild('history', $registerOnly))->toThrow(HttpException::class)
        ->and(fn () => assetreportBuild('register', $stranger))->toThrow(HttpException::class)
        ->and(fn () => assetreportBuild('register', $inactive))->toThrow(HttpException::class);

    // Another branch is refused, never silently widened; the consolidated view needs branches.view_all.
    $this->actingAs($this->maker)->get(route('reports.assets.register', ['branch_id' => $this->otherBranch->id]))->assertForbidden();
    $this->get(route('reports.assets.register', ['branch_id' => 'all']))->assertForbidden();
    $this->get(route('reports.assets.history', ['branch_id' => $this->otherBranch->id]))->assertForbidden();
    $this->get(route('reports.assets.schedule', ['asset_id' => $foreign->id]))->assertForbidden();
    $this->get(route('reports.assets.register.export', ['format' => 'csv', 'branch_id' => $this->otherBranch->id]))->assertForbidden();

    $own = assetreportBuild('register', $this->maker);
    expect(array_column($own->rows, 'asset_code'))->not->toContain($foreign->asset_code)
        ->and($own->meta['Branch'])->toBe('Alpha Branch');

    $all = assetreportBuild('register', $this->admin, ['branch_id' => 'all']);
    expect(array_column($all->rows, 'asset_code'))->toContain($foreign->asset_code, $this->assetA->asset_code)
        ->and($all->meta['Branch'])->toBe('All branches (consolidated)');
    $this->actingAs($this->admin)->get(route('reports.assets.register', ['branch_id' => 'all']))->assertOk()->assertSee($foreign->asset_code);
});

test('export needs the export permission and exports correct figures and totals', function () {
    assetreportPostedRun($this, 1);
    assetreportPostedRun($this, 2);
    $query = ['as_of' => '2026-02-28'];

    // The Approver may view the register but holds no asset_reports.export.
    $this->actingAs($this->approver)->get(route('reports.assets.register', $query))->assertOk()->assertDontSee('/export/csv');
    $this->get(route('reports.assets.register.export', ['format' => 'csv'] + $query))->assertForbidden();
    expect(fn () => app(AssetReportService::class)->export('register', $this->approver, $query))->toThrow(HttpException::class);
    $this->actingAs($this->maker)->get(route('reports.assets.register.export', ['format' => 'docx'] + $query))->assertNotFound();
    $this->get(route('reports.assets.schedule.export', ['format' => 'csv']))->assertNotFound();
    $this->get(route('reports.assets.register', $query))->assertOk()->assertSee('/export/csv');

    $csv = $this->get(route('reports.assets.register.export', ['format' => 'csv'] + $query))->assertOk()->streamedContent();
    expect($csv)->toContain('Fixed Asset Register')->toContain('Branch: Alpha Branch')->toContain('As of: 28 Feb 2026')
        ->toContain($this->assetA->asset_code)->toContain('12000.0000,2000.0000,10000.0000')->toContain('6000.0000,2500.0000,3500.0000')
        ->toContain('Total,,,,,,,,18000.0000,4500.0000,13500.0000');

    $path = tempnam(sys_get_temp_dir(), 'ast').'.xlsx';
    file_put_contents($path, $this->get(route('reports.assets.net-book-value.export', ['format' => 'xlsx'] + $query))->assertOk()->streamedContent());
    $rows = collect(IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false));
    unlink($path);
    $assetB = $rows->first(fn (array $row): bool => $row[0] === $this->assetB->asset_code);
    $total = $rows->first(fn (array $row): bool => $row[0] === 'Total');
    expect(array_slice($assetB, 4, 7))->toEqual([6000, 0, 1500, 1000, 2500, 3500, 3500])
        ->and(array_slice($total, 4, 7))->toEqual([18000, 0, 1500, 3000, 4500, 13500, 13500]);

    $pdf = $this->get(route('reports.assets.category-summary.export', ['format' => 'pdf'] + $query))->assertOk()->streamedContent();
    expect(str_starts_with($pdf, '%PDF-1.4'))->toBeTrue()
        ->and($pdf)->toContain('(Asset Category Summary)')->toContain('(EQP / Equipment)')->toContain('(18,000.00)')->toContain('(4,500.00)')->toContain('(13,500.00)');

    $registerPdf = $this->get(route('reports.assets.register.export', ['format' => 'pdf'] + $query))->assertOk()->streamedContent();
    expect($registerPdf)->toContain('(12,000.00)')->toContain('(2,000.00)')->toContain('(10,000.00)')->toContain('(13,500.00)')->toContain('(Layout: Condensed for PDF');

    $history = $this->get(route('reports.assets.history.export', ['format' => 'csv']))->assertOk()->streamedContent();
    $missed = $this->get(route('reports.assets.missed-periods.export', ['format' => 'csv']))->assertOk()->streamedContent();
    expect($history)->toContain('January 2026')->toContain('1500.0000')->toContain('3000.0000')
        ->and($missed)->toContain('For review — no catch-up policy is approved')
        ->and(AuditLog::query()->where('event', 'report.exported')->count())->toBe(6);
});

test('no report or export creates a journal, a run or changes an asset', function () {
    assetreportPostedRun($this, 2);
    $before = [JournalEntry::query()->count(), DepreciationRun::query()->count(), DepreciationRunLine::query()->count(), FixedAsset::query()->max('updated_at')];

    foreach (array_keys(AssetReportService::REPORTS) as $report) {
        $query = $report === 'schedule' ? ['asset_id' => $this->assetA->id] : [];
        $this->get(route('reports.assets.'.$report, $query))->assertOk();
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $content = $this->get(route('reports.assets.'.$report.'.export', ['format' => $format] + $query))->assertOk()->streamedContent();
            if ($format === 'pdf') {
                // No figure may be cut short on the PDF page.
                expect($content)->not->toMatch('/\(-?[\d,.]+\.\.\.\)/');
            }
        }
    }

    expect([JournalEntry::query()->count(), DepreciationRun::query()->count(), DepreciationRunLine::query()->count(), FixedAsset::query()->max('updated_at')])->toBe($before)
        ->and(JournalEntry::query()->count())->toBe(1);
});
