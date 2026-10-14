<?php

namespace App\Services\Reports\Operational;

use App\Enums\DepreciationRunStatus;
use App\Enums\FixedAssetStatus;
use App\Models\AccountingPeriod;
use App\Models\AssetCategory;
use App\Models\DepreciationRun;
use App\Models\DepreciationRunLine;
use App\Models\FixedAsset;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\Assets\AssetPolicy;
use App\Services\Assets\DepreciationCalculator;
use App\Services\Assets\FixedAssetService;
use App\Services\BranchAccess;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only fixed asset reports. Accumulated depreciation is always derived:
 * opening accumulated depreciation plus the active lines of POSTED runs whose
 * accounting period ended on or before the as-of date. Nothing here posts,
 * recalculates posted history or changes an asset or a run.
 */
class AssetReportService
{
    /** @var array<string, array{title: string, permission: string, description: string}> */
    public const REPORTS = [
        'register' => ['title' => 'Fixed Asset Register', 'permission' => 'fixed_assets.view', 'description' => 'Cost, accumulated depreciation and net book value of every activated asset as of a date.'],
        'category-summary' => ['title' => 'Asset Category Summary', 'permission' => 'fixed_assets.view', 'description' => 'Number of assets, cost, accumulated depreciation and net book value per category.'],
        'branch-summary' => ['title' => 'Branch Asset Summary', 'permission' => 'fixed_assets.view', 'description' => 'Number of assets, cost, accumulated depreciation and net book value per branch.'],
        'net-book-value' => ['title' => 'Accumulated Depreciation and Net Book Value', 'permission' => 'fixed_assets.view', 'description' => 'Opening and posted depreciation, net book value and the amount still to depreciate as of a date.'],
        'schedule' => ['title' => 'Depreciation Schedule', 'permission' => 'depreciation.view', 'description' => 'Posted depreciation of one asset followed by a clearly labelled projection of its remaining life.'],
        'history' => ['title' => 'Depreciation Posting History', 'permission' => 'depreciation.view', 'description' => 'Depreciation runs that were posted, with their voucher, and the runs that were reversed.'],
        'missed-periods' => ['title' => 'Missed-Period / Catch-up Exception Report', 'permission' => 'depreciation.view', 'description' => 'Periods in which an active asset was not depreciated although depreciation was posted for its branch. For review only.'],
    ];

    public const EXPORT_PERMISSION = 'asset_reports.export';

    /** Condensed PDF columns per report, so that no figure is truncated on the page. */
    private const PDF_LAYOUTS = [
        'register' => [
            ['label' => 'Asset', 'keys' => ['asset_code', 'name']], ['label' => 'Category / branch / location', 'keys' => ['category', 'branch', 'location']],
            ['label' => 'In service / status', 'keys' => ['in_service', 'status']], ['label' => 'Cost', 'keys' => ['cost']],
            ['label' => 'Accum. depn.', 'keys' => ['accumulated']], ['label' => 'Net book value', 'keys' => ['net_book_value']],
        ],
        'category-summary' => [
            ['label' => 'Category', 'keys' => ['code', 'group']], ['label' => 'Assets', 'keys' => ['assets']], ['label' => 'Cost', 'keys' => ['cost']],
            ['label' => 'Accum. depn.', 'keys' => ['accumulated']], ['label' => 'Net book value', 'keys' => ['net_book_value']],
        ],
        'branch-summary' => [
            ['label' => 'Branch', 'keys' => ['group']], ['label' => 'Assets', 'keys' => ['assets']], ['label' => 'Cost', 'keys' => ['cost']],
            ['label' => 'Accum. depn.', 'keys' => ['accumulated']], ['label' => 'Net book value', 'keys' => ['net_book_value']],
        ],
        'net-book-value' => [
            ['label' => 'Asset', 'keys' => ['asset_code', 'name', 'branch']], ['label' => 'Cost', 'keys' => ['cost']], ['label' => 'Residual', 'keys' => ['residual']],
            ['label' => 'Opening acc.', 'keys' => ['opening']], ['label' => 'Posted depn.', 'keys' => ['posted']], ['label' => 'Accum. depn.', 'keys' => ['accumulated']],
            ['label' => 'Net book value', 'keys' => ['net_book_value']], ['label' => 'To depreciate', 'keys' => ['remaining']],
        ],
        'schedule' => [
            ['label' => 'Period', 'keys' => ['period']], ['label' => 'Basis', 'keys' => ['basis']], ['label' => 'Depreciation', 'keys' => ['amount']],
            ['label' => 'Accum. after', 'keys' => ['accumulated_after']], ['label' => 'NBV after', 'keys' => ['net_book_value_after']],
        ],
        'history' => [
            ['label' => 'Run / period', 'keys' => ['run', 'period']], ['label' => 'Branch / status', 'keys' => ['branch', 'status']], ['label' => 'Total', 'keys' => ['total']],
            ['label' => 'Voucher / posted', 'keys' => ['voucher', 'posted_by', 'posted_at']], ['label' => 'Reversal', 'keys' => ['reversed_by', 'reversed_at', 'reversal_reason']],
        ],
        'missed-periods' => [
            ['label' => 'Asset', 'keys' => ['asset_code', 'name']], ['label' => 'Category / branch', 'keys' => ['category', 'branch']],
            ['label' => 'Missed period', 'keys' => ['period', 'period_dates']], ['label' => 'Not charged', 'keys' => ['amount']],
            ['label' => 'Branch posted up to / remark', 'keys' => ['latest_posted', 'remark']],
        ],
    ];

    public const MISSED_PERIOD_LABEL = 'For review — no catch-up policy is approved';

    private const POLICY_NOTE = 'Depreciation policy not approved: posting depreciation is blocked until a Director approves the setting "'.AssetPolicy::APPROVAL_SETTING.'".';

    public function __construct(
        private ReportBranchScope $scope,
        private BranchAccess $branchAccess,
        private AssetPolicy $policy,
        private DepreciationCalculator $calculator,
        private FixedAssetService $assets,
    ) {}

    public function policyApproved(): bool
    {
        return $this->policy->isApproved();
    }

    /** @param array<string, mixed> $filters */
    public function build(string $report, User $user, array $filters): OperationalReport
    {
        abort_unless(isset(self::REPORTS[$report]), 404);
        abort_unless($user->is_active && $user->can(self::REPORTS[$report]['permission']), 403);
        $scope = $this->scope->resolve($user, $filters['branch_id'] ?? null);

        return match ($report) {
            'register' => $this->register($scope, $filters),
            'category-summary' => $this->summary('category-summary', $scope, $filters),
            'branch-summary' => $this->summary('branch-summary', $scope, $filters),
            'net-book-value' => $this->netBookValue($scope, $filters),
            'schedule' => $this->schedule($user, $filters),
            'history' => $this->history($scope, $filters),
            'missed-periods' => $this->missedPeriods($scope, $filters),
        };
    }

    /**
     * The same report for a download; exporting needs its own permission.
     *
     * @param  array<string, mixed>  $filters
     */
    public function export(string $report, User $user, array $filters): OperationalReport
    {
        abort_unless($user->is_active && $user->can(self::EXPORT_PERMISSION), 403);

        return $this->build($report, $user, $filters);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $filters
     */
    private function register(array $scope, array $filters): OperationalReport
    {
        $asOf = $this->asOf($filters);
        $rows = $this->assetRows($scope['ids'], $filters, $asOf);

        return $this->report('register', [
            ['key' => 'asset_code', 'label' => 'Asset code'], ['key' => 'name', 'label' => 'Name'], ['key' => 'category', 'label' => 'Category'],
            ['key' => 'branch', 'label' => 'Branch'], ['key' => 'location', 'label' => 'Location'], ['key' => 'in_service', 'label' => 'In service'],
            ['key' => 'life', 'label' => 'Life (months)', 'type' => 'number'], ['key' => 'status', 'label' => 'Status'],
            ['key' => 'cost', 'label' => 'Cost', 'type' => 'money'], ['key' => 'accumulated', 'label' => 'Accumulated depreciation', 'type' => 'money'],
            ['key' => 'net_book_value', 'label' => 'Net book value', 'type' => 'money'],
        ], $rows, $this->totalRow('asset_code', $rows, ['cost', 'accumulated', 'net_book_value']), $this->assetMeta($scope, $filters, $asOf), $this->derivationNote());
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $filters
     */
    private function netBookValue(array $scope, array $filters): OperationalReport
    {
        $asOf = $this->asOf($filters);
        $rows = $this->assetRows($scope['ids'], $filters, $asOf);

        return $this->report('net-book-value', [
            ['key' => 'asset_code', 'label' => 'Asset code'], ['key' => 'name', 'label' => 'Name'], ['key' => 'category', 'label' => 'Category'], ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'cost', 'label' => 'Cost', 'type' => 'money'], ['key' => 'residual', 'label' => 'Residual value', 'type' => 'money'],
            ['key' => 'opening', 'label' => 'Opening accumulated', 'type' => 'money'], ['key' => 'posted', 'label' => 'Posted depreciation', 'type' => 'money'],
            ['key' => 'accumulated', 'label' => 'Accumulated depreciation', 'type' => 'money'], ['key' => 'net_book_value', 'label' => 'Net book value', 'type' => 'money'],
            ['key' => 'remaining', 'label' => 'Still to depreciate', 'type' => 'money'],
        ], $rows, $this->totalRow('asset_code', $rows, ['cost', 'residual', 'opening', 'posted', 'accumulated', 'net_book_value', 'remaining']), $this->assetMeta($scope, $filters, $asOf), $this->derivationNote());
    }

    /**
     * Category or branch totals, summed from exactly the rows of the register.
     *
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $filters
     */
    private function summary(string $report, array $scope, array $filters): OperationalReport
    {
        $asOf = $this->asOf($filters);
        $byCategory = $report === 'category-summary';
        $groups = collect($this->assetRows($scope['ids'], $filters, $asOf))->groupBy($byCategory ? 'category_id' : 'branch_id');

        $rows = $groups->map(function (Collection $assets) use ($byCategory): array {
            $first = $assets->first();

            return [
                'code' => $byCategory ? $first['category_code'] : '', 'group' => $byCategory ? $first['category'] : $first['branch'], 'assets' => $assets->count(),
                'cost' => $this->sum($assets, 'cost'), 'accumulated' => $this->sum($assets, 'accumulated'), 'net_book_value' => $this->sum($assets, 'net_book_value'),
            ];
        })->sortBy(fn (array $row): string => $row['code'].' '.$row['group'])->values()->all();

        $columns = [
            ['key' => 'group', 'label' => $byCategory ? 'Category' : 'Branch'], ['key' => 'assets', 'label' => 'Assets', 'type' => 'number'],
            ['key' => 'cost', 'label' => 'Cost', 'type' => 'money'], ['key' => 'accumulated', 'label' => 'Accumulated depreciation', 'type' => 'money'],
            ['key' => 'net_book_value', 'label' => 'Net book value', 'type' => 'money'],
        ];
        if ($byCategory) {
            array_unshift($columns, ['key' => 'code', 'label' => 'Category code']);
        }
        $totals = $rows === [] ? [] : [[
            $columns[0]['key'] => 'Total', 'assets' => array_sum(array_column($rows, 'assets')), 'cost' => $this->sum($rows, 'cost'),
            'accumulated' => $this->sum($rows, 'accumulated'), 'net_book_value' => $this->sum($rows, 'net_book_value'), '_style' => 'total',
        ]];

        return $this->report($report, $columns, $rows, $totals, $this->assetMeta($scope, $filters, $asOf), $this->derivationNote());
    }

    /** @param array<string, mixed> $filters */
    private function schedule(User $user, array $filters): OperationalReport
    {
        $columns = [
            ['key' => 'period', 'label' => 'Period'], ['key' => 'basis', 'label' => 'Basis'], ['key' => 'amount', 'label' => 'Depreciation', 'type' => 'money'],
            ['key' => 'accumulated_after', 'label' => 'Accumulated after', 'type' => 'money'], ['key' => 'net_book_value_after', 'label' => 'Net book value after', 'type' => 'money'],
        ];
        if (blank($filters['asset_id'] ?? null)) {
            return $this->report('schedule', $columns, [], [], [], null, 'Select an asset to see its depreciation schedule.');
        }

        $asset = FixedAsset::query()->with(['branch', 'category'])->findOrFail((int) $filters['asset_id']);
        abort_unless($this->branchAccess->canAccess($user, $asset->branch), 403);
        $schedule = $this->assets->schedule($asset);
        $cost = AccountingDecimal::of($asset->acquisition_cost);
        $opening = AccountingDecimal::of($asset->opening_accumulated_depreciation);

        $rows = [];
        if ($opening->isPositive()) {
            $rows[] = ['period' => 'Before the first posted run', 'basis' => 'Opening accumulated depreciation', 'amount' => AccountingDecimal::string($opening), 'accumulated_after' => AccountingDecimal::string($opening), 'net_book_value_after' => AccountingDecimal::string($cost->minus($opening))];
        }
        foreach ($schedule['history'] as $line) {
            $rows[] = ['period' => $line['period_name'], 'basis' => 'Posted (run #'.$line['run_id'].')', 'amount' => $line['amount'], 'accumulated_after' => $line['accumulated_after'], 'net_book_value_after' => $line['net_book_value_after']];
        }
        foreach ($schedule['projection'] as $line) {
            $rows[] = ['period' => $line['period_start']->format('F Y'), 'basis' => 'PROJECTION — not posted', 'amount' => $line['amount'], 'accumulated_after' => $line['accumulated_after'], 'net_book_value_after' => $line['net_book_value_after']];
        }

        $accumulated = AccountingDecimal::of($asset->accumulatedDepreciation());
        $totals = [
            ['period' => 'Posted depreciation to date', 'amount' => $this->sum($schedule['history'], 'amount'), '_style' => 'subtotal'],
            ['period' => 'Accumulated depreciation (opening + posted)', 'amount' => AccountingDecimal::string($accumulated), 'accumulated_after' => AccountingDecimal::string($accumulated), 'net_book_value_after' => AccountingDecimal::string($cost->minus($accumulated)), '_style' => 'total'],
            ['period' => 'Projected remaining depreciation (not posted)', 'amount' => $this->sum($schedule['projection'], 'amount'), '_style' => 'subtotal'],
        ];
        $meta = [
            'Asset' => $asset->asset_code.' — '.$asset->name, 'Branch' => $asset->branch->name, 'Category' => $asset->category->name,
            'Cost' => number_format((float) $asset->acquisition_cost, 2), 'Residual value' => number_format((float) $asset->residual_value, 2),
            'Useful life' => $asset->useful_life_months.' months', 'Status' => $asset->status->label(), 'Convention' => $schedule['convention'],
        ];

        return $this->report('schedule', $columns, $rows, $totals, $meta, $this->withPolicy('Posted rows come from posted depreciation runs and are never recalculated. Projection rows are estimates under the current convention, assume a run is posted every month, and are not posted.'));
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $filters
     */
    private function history(array $scope, array $filters): OperationalReport
    {
        $ever = [DepreciationRunStatus::Posted->value, DepreciationRunStatus::Reversed->value];
        $status = in_array($filters['status'] ?? null, $ever, true) ? [$filters['status']] : $ever;
        $from = filled($filters['date_from'] ?? null) ? CarbonImmutable::parse($filters['date_from'])->toDateString() : null;
        $to = filled($filters['date_to'] ?? null) ? CarbonImmutable::parse($filters['date_to'])->toDateString() : null;

        $runs = DepreciationRun::query()->with(['branch:id,name', 'accountingPeriod', 'journalEntry', 'poster:id,name', 'reverser:id,name'])
            ->whereIn('branch_id', $scope['ids'])->whereIn('status', $status)
            ->when(filled($filters['period_id'] ?? null), fn ($query) => $query->where('accounting_period_id', (int) $filters['period_id']))
            ->when($from, fn ($query) => $query->whereHas('accountingPeriod', fn ($period) => $period->whereDate('end_date', '>=', $from)))
            ->when($to, fn ($query) => $query->whereHas('accountingPeriod', fn ($period) => $period->whereDate('end_date', '<=', $to)))
            ->get()->sortBy(fn (DepreciationRun $run): string => $run->accountingPeriod->start_date->toDateString().'-'.str_pad((string) $run->id, 10, '0', STR_PAD_LEFT))->values();

        $rows = $runs->map(fn (DepreciationRun $run): array => [
            'run' => '#'.$run->id, 'period' => $run->accountingPeriod->name, 'branch' => $run->branch->name, 'status' => $run->status->label(),
            'total' => $run->total_amount, 'voucher' => (string) ($run->journalEntry?->voucher_number ?? ''),
            'posted_by' => (string) ($run->poster?->name ?? ''), 'posted_at' => (string) $run->posted_at?->format('d M Y H:i'),
            'reversed_by' => (string) ($run->reverser?->name ?? ''), 'reversed_at' => (string) $run->reversed_at?->format('d M Y H:i'), 'reversal_reason' => (string) $run->reversal_reason,
            '_counts' => $run->status === DepreciationRunStatus::Posted,
        ])->all();

        $totals = $rows === [] ? [] : [['run' => 'Total of posted runs (reversed runs excluded)', 'total' => $this->sum(array_filter($rows, fn (array $row): bool => $row['_counts']), 'total'), '_style' => 'total']];
        $meta = ['Branch' => $scope['label'], 'Status' => count($status) === 1 ? ucfirst($status[0]) : 'Posted and reversed'];
        if ($from || $to) {
            $meta['Period ending'] = ($from ? CarbonImmutable::parse($from)->format('d M Y') : 'start').' to '.($to ? CarbonImmutable::parse($to)->format('d M Y') : 'latest');
        }
        if (filled($filters['period_id'] ?? null)) {
            $meta['Accounting period'] = (string) AccountingPeriod::query()->whereKey((int) $filters['period_id'])->value('name');
        }

        return $this->report('history', [
            ['key' => 'run', 'label' => 'Run'], ['key' => 'period', 'label' => 'Period'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'status', 'label' => 'Status'],
            ['key' => 'total', 'label' => 'Total depreciation', 'type' => 'money'], ['key' => 'voucher', 'label' => 'Voucher'],
            ['key' => 'posted_by', 'label' => 'Posted by'], ['key' => 'posted_at', 'label' => 'Posted at'],
            ['key' => 'reversed_by', 'label' => 'Reversed by'], ['key' => 'reversed_at', 'label' => 'Reversed at'], ['key' => 'reversal_reason', 'label' => 'Reversal reason'],
        ], $rows, $totals, $meta, $this->withPolicy('Only runs that reached the General Ledger are listed. A reversed run no longer counts towards accumulated depreciation.'));
    }

    /**
     * Rule: for each ACTIVE asset, every accounting period from its first
     * depreciable month (per the configured convention) up to the latest
     * period for which a posted depreciation run exists for the asset's
     * branch, in which the asset has no active posted depreciation line.
     * For an asset carrying opening accumulated depreciation, periods before
     * the month it was activated are treated as covered by the opening figure.
     *
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $filters
     */
    private function missedPeriods(array $scope, array $filters): OperationalReport
    {
        $convention = $this->policy->convention();
        $assets = FixedAsset::query()->with(['category:id,code,name', 'branch:id,name'])->whereIn('branch_id', $scope['ids'])
            ->where('status', FixedAssetStatus::Active->value)
            ->when(filled($filters['category_id'] ?? null), fn ($query) => $query->where('category_id', (int) $filters['category_id']))
            ->orderBy('asset_code')->get();

        $lastPosted = DB::table('depreciation_runs as run')->join('accounting_periods as period', 'period.id', '=', 'run.accounting_period_id')
            ->where('run.status', DepreciationRunStatus::Posted->value)->whereIn('run.branch_id', $scope['ids'])
            ->groupBy('run.branch_id')->selectRaw('run.branch_id, MAX(period.end_date) as last_end')->pluck('last_end', 'branch_id')
            ->map(fn (mixed $date): CarbonImmutable => CarbonImmutable::parse((string) $date)->startOfDay());
        $covered = [];
        foreach ($this->postedLines($scope['ids'])->get(['line.fixed_asset_id', 'line.accounting_period_id']) as $line) {
            $covered[$line->fixed_asset_id.':'.$line->accounting_period_id] = true;
        }
        $accumulatedPosted = $this->postedTotals($scope['ids'], null);
        $periods = $lastPosted->isEmpty() ? collect() : AccountingPeriod::query()->orderBy('start_date')->get(['id', 'name', 'start_date', 'end_date']);

        $rows = [];
        foreach ($assets as $asset) {
            $upper = $lastPosted->get($asset->branch_id);
            if (! $upper) {
                continue;
            }
            $opening = AccountingDecimal::of($asset->opening_accumulated_depreciation);
            $lower = $this->calculator->firstDepreciationMonth($asset, $convention);
            if ($opening->isPositive()) {
                $lower = $lower->max(CarbonImmutable::parse($asset->activated_at ?? $asset->created_at)->startOfMonth());
            }
            // Amounts follow on from what is already accumulated, so the listed total never exceeds what is still depreciable.
            $running = $opening->plus($accumulatedPosted[$asset->id] ?? BigDecimal::zero());

            foreach ($periods as $period) {
                $end = CarbonImmutable::parse($period->end_date)->startOfDay();
                if ($end->lt($lower) || $end->gt($upper) || isset($covered[$asset->id.':'.$period->id])) {
                    continue;
                }
                $amount = $this->calculator->amountForPeriod($asset, $period->start_date, $period->end_date, $running, $convention);
                if ($amount === null) {
                    continue;
                }
                $running = $running->plus($amount);
                $rows[] = [
                    'asset_code' => $asset->asset_code, 'name' => $asset->name, 'category' => $asset->category->name, 'branch' => $asset->branch->name,
                    'in_service' => $asset->in_service_date->format('d M Y'), 'period' => $period->name,
                    'period_dates' => $period->start_date->format('d M Y').' to '.$period->end_date->format('d M Y'),
                    'amount' => AccountingDecimal::string($amount), 'latest_posted' => $upper->format('d M Y'),
                    'remark' => $opening->isPositive() ? 'Has opening accumulated depreciation; periods before activation are treated as covered by it.' : '',
                ];
            }
        }

        $totals = $rows === [] ? [] : [['asset_code' => 'Total regular amounts not charged (review only)', 'amount' => $this->sum($rows, 'amount'), '_style' => 'total']];
        $meta = ['Review status' => self::MISSED_PERIOD_LABEL, 'Branch' => $scope['label'], 'Assets' => 'Active assets only', 'Convention' => $convention->label()];
        if (filled($filters['category_id'] ?? null)) {
            $meta['Category'] = (string) AssetCategory::query()->whereKey((int) $filters['category_id'])->value('name');
        }

        return $this->report('missed-periods', [
            ['key' => 'asset_code', 'label' => 'Asset code'], ['key' => 'name', 'label' => 'Name'], ['key' => 'category', 'label' => 'Category'], ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'in_service', 'label' => 'In service'], ['key' => 'period', 'label' => 'Missed period'], ['key' => 'period_dates', 'label' => 'Period dates'],
            ['key' => 'amount', 'label' => 'Regular amount not charged', 'type' => 'money'], ['key' => 'latest_posted', 'label' => 'Branch posted up to'], ['key' => 'remark', 'label' => 'Remark'],
        ], $rows, $totals, $meta, $this->withPolicy(self::MISSED_PERIOD_LABEL.'. A period is listed when an active asset has no posted depreciation for it although depreciation was posted for its branch for that or a later period. Amounts are the regular straight-line charge, limited to what is still depreciable. Nothing is posted and no catch-up entry is proposed.'));
    }

    /**
     * One row per asset in scope with figures as of the date. Two queries in
     * total: the assets, and one grouped sum of their posted depreciation.
     *
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function assetRows(array $branchIds, array $filters, CarbonImmutable $asOf): array
    {
        $status = FixedAssetStatus::tryFrom((string) ($filters['status'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));
        $assets = FixedAsset::query()->with(['category:id,code,name', 'branch:id,name'])->whereIn('branch_id', $branchIds)
            ->whereDate('acquisition_date', '<=', $asOf->toDateString())
            ->when($status, fn ($query) => $query->where('status', $status->value), fn ($query) => $query->where('status', '!=', FixedAssetStatus::Draft->value))
            ->when(filled($filters['category_id'] ?? null), fn ($query) => $query->where('category_id', (int) $filters['category_id']))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('asset_code', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%')->orWhere('location', 'like', '%'.$search.'%')->orWhere('custodian', 'like', '%'.$search.'%')))
            ->get()->sortBy(fn (FixedAsset $asset): string => $asset->branch->name.' '.$asset->asset_code)->values();
        $postedTotals = $this->postedTotals($branchIds, $asOf);

        return $assets->map(function (FixedAsset $asset) use ($postedTotals): array {
            $cost = AccountingDecimal::of($asset->acquisition_cost);
            $residual = AccountingDecimal::of($asset->residual_value);
            $opening = AccountingDecimal::of($asset->opening_accumulated_depreciation);
            $posted = $postedTotals[$asset->id] ?? BigDecimal::zero()->toScale(4);
            $accumulated = $opening->plus($posted);

            return [
                'asset_code' => $asset->asset_code, 'name' => $asset->name, 'category' => $asset->category->name, 'category_code' => $asset->category->code,
                'category_id' => $asset->category_id, 'branch' => $asset->branch->name, 'branch_id' => $asset->branch_id, 'location' => (string) $asset->location,
                'in_service' => $asset->in_service_date->format('d M Y'), 'life' => $asset->useful_life_months, 'status' => $asset->status->label(),
                'cost' => AccountingDecimal::string($cost), 'residual' => AccountingDecimal::string($residual), 'opening' => AccountingDecimal::string($opening),
                'posted' => AccountingDecimal::string($posted), 'accumulated' => AccountingDecimal::string($accumulated),
                'net_book_value' => AccountingDecimal::string($cost->minus($accumulated)), 'remaining' => AccountingDecimal::string($cost->minus($residual)->minus($accumulated)),
            ];
        })->all();
    }

    /**
     * Active lines of posted runs: the only depreciation that counts.
     *
     * @param  list<int>  $branchIds
     */
    private function postedLines(array $branchIds): Builder
    {
        return DB::table('depreciation_run_lines as line')
            ->join('depreciation_runs as run', 'run.id', '=', 'line.depreciation_run_id')
            ->join('accounting_periods as period', 'period.id', '=', 'line.accounting_period_id')
            ->where('line.active_key', DepreciationRunLine::ACTIVE)
            ->where('run.status', DepreciationRunStatus::Posted->value)
            ->whereIn('run.branch_id', $branchIds);
    }

    /**
     * Posted depreciation per asset in one grouped query.
     *
     * @param  list<int>  $branchIds
     * @return array<int, BigDecimal>
     */
    private function postedTotals(array $branchIds, ?CarbonImmutable $asOf): array
    {
        return $this->postedLines($branchIds)
            ->when($asOf, fn ($query) => $query->whereDate('period.end_date', '<=', $asOf->toDateString()))
            ->groupBy('line.fixed_asset_id')->selectRaw('line.fixed_asset_id, SUM(line.depreciation_amount) as posted')
            ->pluck('posted', 'fixed_asset_id')
            // MySQL returns the exact DECIMAL; SQLite returns a float, from which the 4-decimal value is restored.
            ->map(fn (mixed $sum): BigDecimal => BigDecimal::of((string) $sum)->toScale(4, RoundingMode::HalfUp))->all();
    }

    /** @param array<string, mixed> $filters */
    private function asOf(array $filters): CarbonImmutable
    {
        return filled($filters['as_of'] ?? null) ? CarbonImmutable::parse($filters['as_of'])->startOfDay() : CarbonImmutable::today();
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function assetMeta(array $scope, array $filters, CarbonImmutable $asOf): array
    {
        $status = FixedAssetStatus::tryFrom((string) ($filters['status'] ?? ''));
        $meta = ['Branch' => $scope['label'], 'As of' => $asOf->format('d M Y'), 'Status' => $status ? $status->label() : 'All activated assets (drafts excluded)'];
        if (filled($filters['category_id'] ?? null)) {
            $meta['Category'] = (string) AssetCategory::query()->whereKey((int) $filters['category_id'])->value('name');
        }
        if (filled($filters['search'] ?? null)) {
            $meta['Search'] = (string) $filters['search'];
        }

        return $meta;
    }

    private function derivationNote(): string
    {
        return $this->withPolicy('Accumulated depreciation is opening accumulated depreciation plus posted depreciation runs for periods ending on or before the as-of date; reversed and unposted runs are excluded. Status is the current status. Acquisition cost is shown for information: this report creates no journal.');
    }

    private function withPolicy(string $note): string
    {
        return $this->policy->isApproved() ? $note : self::POLICY_NOTE.' '.$note;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function totalRow(string $labelKey, array $rows, array $keys): array
    {
        if ($rows === []) {
            return [];
        }
        $total = [$labelKey => 'Total', '_style' => 'total'];
        foreach ($keys as $key) {
            $total[$key] = $this->sum($rows, $key);
        }

        return [$total];
    }

    /** @param iterable<int, array<string, mixed>> $rows */
    private function sum(iterable $rows, string $key): string
    {
        $total = BigDecimal::zero()->toScale(4);
        foreach ($rows as $row) {
            $total = $total->plus(AccountingDecimal::of($row[$key]));
        }

        return AccountingDecimal::string($total);
    }

    /**
     * @param  list<array{key: string, label: string, type?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $totals
     * @param  array<string, string>  $meta
     */
    private function report(string $key, array $columns, array $rows, array $totals, array $meta, ?string $note, ?string $prompt = null): OperationalReport
    {
        return new OperationalReport($key, self::REPORTS[$key]['title'], self::REPORTS[$key]['description'], $columns, $rows, $totals, $meta, $note, [], $prompt, self::PDF_LAYOUTS[$key]);
    }
}
