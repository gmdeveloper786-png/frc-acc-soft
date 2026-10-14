<?php

namespace App\Services\Reports\Operational;

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\Inventory\InventoryService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, quantity-only inventory reports. Only posted movements affect a
 * quantity; pending and rejected adjustments appear in the adjustment audit
 * alone. Nothing here values stock, writes stock or posts to the ledger.
 */
class InventoryReportService
{
    /** @var array<string, array{title: string, description: string}> */
    public const REPORTS = [
        'stock-balance' => ['title' => 'Current Stock Balance', 'description' => 'Quantity on hand per item, branch and location, verified against the sum of posted movements.'],
        'movements' => ['title' => 'Item Movement Register', 'description' => 'Every posted stock movement in a date range with the running quantity per item, branch and location.'],
        'branch-summary' => ['title' => 'Branch-wise Stock Summary', 'description' => 'Quantity on hand per branch and item across all of the branch\'s locations.'],
        'receipts' => ['title' => 'Receive Register', 'description' => 'Stock received in a date range, with the purchase order reference where one was recorded.'],
        'issues' => ['title' => 'Issue Register', 'description' => 'Stock issued in a date range and who it was issued to.'],
        'issued-to' => ['title' => 'Issued-To History', 'description' => 'Quantity issued per recipient and item in a date range.'],
        'transfers' => ['title' => 'Branch Transfer Register', 'description' => 'Transfers between locations and branches with both legs paired: quantity out equals quantity in.'],
        'low-stock' => ['title' => 'Low Stock Report', 'description' => 'Items whose branch quantity is at or below the minimum stock level.'],
        'adjustments' => ['title' => 'Stock Adjustment Audit', 'description' => 'Every adjustment request with who requested it, who approved or rejected it, the reason and the status.'],
    ];

    public const PERMISSION = 'inventory.view';

    public const EXPORT_PERMISSION = 'inventory_reports.export';

    private const PDF_ITEM = ['label' => 'Item', 'keys' => ['item_code', 'item', 'unit']];

    private const PDF_PLACE = ['label' => 'Branch / location', 'keys' => ['branch', 'location']];

    /** Condensed PDF columns per report, so that no quantity is truncated on the page. */
    private const PDF_LAYOUTS = [
        'stock-balance' => [self::PDF_ITEM, self::PDF_PLACE, ['label' => 'On hand', 'keys' => ['quantity']], ['label' => 'Posted movements', 'keys' => ['posted']], ['label' => 'Verification', 'keys' => ['check']]],
        'movements' => [
            ['label' => 'Date / type / reference', 'keys' => ['date', 'type', 'reference']], self::PDF_ITEM, ['label' => 'Branch / location / issued to', 'keys' => ['branch', 'location', 'issued_to']],
            ['label' => 'Qty in', 'keys' => ['quantity_in']], ['label' => 'Qty out', 'keys' => ['quantity_out']], ['label' => 'Running qty', 'keys' => ['running']],
        ],
        'branch-summary' => [['label' => 'Branch', 'keys' => ['branch']], self::PDF_ITEM, ['label' => 'Locations', 'keys' => ['locations']], ['label' => 'On hand', 'keys' => ['quantity']]],
        'receipts' => [
            ['label' => 'Date', 'keys' => ['date']], self::PDF_ITEM, self::PDF_PLACE, ['label' => 'Received', 'keys' => ['quantity']],
            ['label' => 'Reference / PO / by', 'keys' => ['reference', 'purchase_order', 'recorded_by', 'notes']],
        ],
        'issues' => [
            ['label' => 'Date', 'keys' => ['date']], self::PDF_ITEM, self::PDF_PLACE, ['label' => 'Issued', 'keys' => ['quantity']],
            ['label' => 'Issued to / reference / by', 'keys' => ['issued_to', 'reference', 'recorded_by', 'notes']],
        ],
        'issued-to' => [
            ['label' => 'Issued to', 'keys' => ['issued_to']], self::PDF_ITEM, ['label' => 'Issues', 'keys' => ['issues']], ['label' => 'Issued', 'keys' => ['quantity']],
            ['label' => 'First / last issue', 'keys' => ['first_date', 'last_date']],
        ],
        'transfers' => [
            ['label' => 'Date / pairing / reference', 'keys' => ['date', 'check', 'reference']], self::PDF_ITEM, ['label' => 'From', 'keys' => ['from_branch', 'from_location']],
            ['label' => 'To', 'keys' => ['to_branch', 'to_location']], ['label' => 'Qty out', 'keys' => ['quantity_out']], ['label' => 'Qty in', 'keys' => ['quantity_in']],
        ],
        'low-stock' => [
            self::PDF_ITEM, ['label' => 'Category / branch', 'keys' => ['category', 'branch']], ['label' => 'On hand', 'keys' => ['quantity']],
            ['label' => 'Minimum', 'keys' => ['minimum']], ['label' => 'Below by', 'keys' => ['shortfall']],
        ],
        'adjustments' => [
            ['label' => 'Date / status', 'keys' => ['date', 'status']], self::PDF_ITEM, self::PDF_PLACE, ['label' => 'Adjustment', 'keys' => ['quantity']],
            ['label' => 'Reason / requested by', 'keys' => ['reason', 'recorded_by']],
            ['label' => 'Decision', 'keys' => ['approved_by' => 'Approved by ', 'rejected_by' => 'Rejected by ', 'decided_at', 'rejection_reason']],
        ],
    ];

    private const DATED = ['movements', 'receipts', 'issues', 'issued-to', 'transfers', 'adjustments'];

    public function __construct(private ReportBranchScope $scope, private InventoryService $inventory) {}

    /** Whether the report takes a date range. */
    public static function isDated(string $report): bool
    {
        return in_array($report, self::DATED, true);
    }

    /** @param array<string, mixed> $filters */
    public function build(string $report, User $user, array $filters): OperationalReport
    {
        abort_unless(isset(self::REPORTS[$report]), 404);
        abort_unless($user->is_active && $user->can(self::PERMISSION), 403);
        $scope = $this->scope->resolve($user, $filters['branch_id'] ?? null);
        [$from, $to] = $this->range($filters);
        $meta = $this->meta($report, $scope, $filters, $from, $to);

        return match ($report) {
            'stock-balance' => $this->stockBalance($scope['ids'], $filters, $meta),
            'movements' => $this->movementRegister($scope['ids'], $filters, $from, $to, $meta),
            'branch-summary' => $this->branchSummary($scope['ids'], $filters, $meta),
            'receipts' => $this->receipts($scope['ids'], $filters, $from, $to, $meta),
            'issues' => $this->issues($scope['ids'], $filters, $from, $to, $meta),
            'issued-to' => $this->issuedTo($scope['ids'], $filters, $from, $to, $meta),
            'transfers' => $this->transfers($scope['ids'], $filters, $from, $to, $meta),
            'low-stock' => $this->lowStock($scope['ids'], $filters, $meta),
            'adjustments' => $this->adjustments($scope['ids'], $filters, $from, $to, $meta),
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
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $meta
     */
    private function stockBalance(array $branchIds, array $filters, array $meta): OperationalReport
    {
        $balances = InventoryBalance::query()->with(['item:id,item_code,name,unit_of_measure,category_id', 'branch:id,name', 'location:id,name'])
            ->whereIn('branch_id', $branchIds)
            ->when(filled($filters['location_id'] ?? null), fn ($query) => $query->where('location_id', (int) $filters['location_id']))
            ->whereHas('item', fn ($query) => $this->filterItems($query, $filters, ''))
            ->get()->sortBy(fn (InventoryBalance $balance): string => $balance->item->item_code.' '.$balance->branch->name.' '.$balance->location->name)->values();

        // One grouped query recomputes every balance from the ledger of posted movements.
        $ledger = DB::table('inventory_movements as movement')->join('inventory_items as item', 'item.id', '=', 'movement.item_id')
            ->where('movement.status', InventoryMovementStatus::Posted->value)->whereIn('movement.branch_id', $branchIds)
            ->when(filled($filters['location_id'] ?? null), fn ($query) => $query->where('movement.location_id', (int) $filters['location_id']))
            ->tap(fn ($query) => $this->filterItems($query, $filters, 'item.'))
            ->groupBy('movement.item_id', 'movement.branch_id', 'movement.location_id')
            ->selectRaw('movement.item_id, movement.branch_id, movement.location_id, SUM(movement.quantity) as posted')->get()
            ->mapWithKeys(fn (object $row): array => [$row->item_id.':'.$row->branch_id.':'.$row->location_id => ['posted' => $this->decimal($row->posted), 'row' => $row]]);

        $mismatches = 0;
        $rows = [];
        foreach ($balances as $balance) {
            $key = $balance->item_id.':'.$balance->branch_id.':'.$balance->location_id;
            $posted = $ledger[$key]['posted'] ?? BigDecimal::zero()->toScale(4);
            $ledger->forget($key);
            $matches = AccountingDecimal::of($balance->quantity)->isEqualTo($posted);
            $mismatches += $matches ? 0 : 1;
            $rows[] = [
                'item_code' => $balance->item->item_code, 'item' => $balance->item->name, 'unit' => $balance->item->unit_of_measure, 'branch' => $balance->branch->name,
                'location' => $balance->location->name, 'quantity' => $balance->quantity, 'posted' => AccountingDecimal::string($posted), 'check' => $matches ? 'Match' : 'MISMATCH',
            ];
        }
        // Posted movements with no balance row at all are a mismatch too; the missing balance is left blank, not shown as zero.
        if ($ledger->isNotEmpty()) {
            $items = InventoryItem::query()->whereKey($ledger->pluck('row.item_id')->unique())->get()->keyBy('id');
            $branches = Branch::query()->whereKey($ledger->pluck('row.branch_id')->unique())->pluck('name', 'id');
            $locations = DB::table('inventory_locations')->whereIn('id', $ledger->pluck('row.location_id')->unique())->pluck('name', 'id');
            foreach ($ledger as $entry) {
                $mismatches++;
                $item = $items[$entry['row']->item_id];
                $rows[] = [
                    'item_code' => $item->item_code, 'item' => $item->name, 'unit' => $item->unit_of_measure, 'branch' => (string) $branches[$entry['row']->branch_id],
                    'location' => (string) ($locations[$entry['row']->location_id] ?? ''), 'quantity' => '', 'posted' => AccountingDecimal::string($entry['posted']), 'check' => 'MISMATCH — no balance row',
                ];
            }
        }

        $warnings = $mismatches > 0 ? [$mismatches.' stock balance(s) do not equal the sum of their posted movements. Review them before relying on this report.'] : [];
        $note = 'Quantities only; stock is not valued.'.($mismatches === 0 && $rows !== [] ? ' Verified: every listed balance equals the sum of its posted movements.' : '');

        return $this->report('stock-balance', [
            ['key' => 'item_code', 'label' => 'Item code'], ['key' => 'item', 'label' => 'Item'], ['key' => 'unit', 'label' => 'Unit'], ['key' => 'branch', 'label' => 'Branch'], ['key' => 'location', 'label' => 'Location'],
            ['key' => 'quantity', 'label' => 'Quantity on hand', 'type' => 'quantity'], ['key' => 'posted', 'label' => 'Sum of posted movements', 'type' => 'quantity'], ['key' => 'check', 'label' => 'Verification'],
        ], $rows, $this->totals('item_code', $rows, ['quantity', 'posted']), $meta, $note, $warnings);
    }

    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $meta
     */
    private function movementRegister(array $branchIds, array $filters, CarbonImmutable $from, CarbonImmutable $to, array $meta): OperationalReport
    {
        // Quantity on hand before the first date, per item, branch and location, in one grouped query.
        $opening = DB::table('inventory_movements as movement')->join('inventory_items as item', 'item.id', '=', 'movement.item_id')
            ->where('movement.status', InventoryMovementStatus::Posted->value)->whereIn('movement.branch_id', $branchIds)
            ->whereDate('movement.transaction_date', '<', $from->toDateString())
            ->when(filled($filters['location_id'] ?? null), fn ($query) => $query->where('movement.location_id', (int) $filters['location_id']))
            ->tap(fn ($query) => $this->filterItems($query, $filters, 'item.'))
            ->groupBy('movement.item_id', 'movement.branch_id', 'movement.location_id')
            ->selectRaw('movement.item_id, movement.branch_id, movement.location_id, SUM(movement.quantity) as quantity')->get()
            ->mapWithKeys(fn (object $row): array => [$row->item_id.':'.$row->branch_id.':'.$row->location_id => $this->decimal($row->quantity)]);

        $movements = $this->movements($branchIds, $filters, $from, $to)->where('status', InventoryMovementStatus::Posted->value)
            ->orderBy('item_id')->orderBy('branch_id')->orderBy('location_id')->orderBy('transaction_date')->orderBy('id')->get();

        // The running quantity always covers every movement type; a type or text filter only narrows which rows are listed.
        $type = InventoryMovementType::tryFrom((string) ($filters['type'] ?? ''));
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $running = [];
        $rows = [];
        foreach ($movements as $movement) {
            $key = $movement->item_id.':'.$movement->branch_id.':'.$movement->location_id;
            $quantity = AccountingDecimal::of($movement->quantity);
            $running[$key] = ($running[$key] ?? $opening[$key] ?? BigDecimal::zero()->toScale(4))->plus($quantity);
            if ($type && $movement->type !== $type) {
                continue;
            }
            if ($search !== '' && ! str_contains(mb_strtolower($movement->reference.' '.$movement->issued_to.' '.$movement->notes), $search)) {
                continue;
            }
            $rows[] = [
                ...$this->movementColumns($movement), 'type' => $movement->type->label(), 'issued_to' => (string) $movement->issued_to,
                'quantity_in' => $quantity->isPositive() ? AccountingDecimal::string($quantity) : '',
                'quantity_out' => $quantity->isNegative() ? AccountingDecimal::string($quantity->abs()) : '',
                'running' => AccountingDecimal::string($running[$key]),
            ];
        }

        return $this->report('movements', [
            ['key' => 'date', 'label' => 'Date'], ['key' => 'item_code', 'label' => 'Item code'], ['key' => 'item', 'label' => 'Item'], ['key' => 'unit', 'label' => 'Unit'],
            ['key' => 'branch', 'label' => 'Branch'], ['key' => 'location', 'label' => 'Location'], ['key' => 'type', 'label' => 'Type'], ['key' => 'reference', 'label' => 'Reference'],
            ['key' => 'issued_to', 'label' => 'Issued to'], ['key' => 'quantity_in', 'label' => 'Quantity in', 'type' => 'quantity'], ['key' => 'quantity_out', 'label' => 'Quantity out', 'type' => 'quantity'],
            ['key' => 'running', 'label' => 'Running quantity', 'type' => 'quantity'], ['key' => 'recorded_by', 'label' => 'Recorded by'],
        ], $rows, $this->totals('date', $rows, ['quantity_in', 'quantity_out']), $meta, 'Posted movements only. The running quantity is per item, branch and location, ordered by date, and starts from the quantity on hand before the first date.');
    }

    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $meta
     */
    private function branchSummary(array $branchIds, array $filters, array $meta): OperationalReport
    {
        $rows = DB::table('inventory_balances as stock')
            ->join('inventory_items as item', 'item.id', '=', 'stock.item_id')->join('branches as branch', 'branch.id', '=', 'stock.branch_id')
            ->whereIn('stock.branch_id', $branchIds)->tap(fn ($query) => $this->filterItems($query, $filters, 'item.'))
            ->groupBy('stock.branch_id', 'stock.item_id', 'branch.name', 'item.item_code', 'item.name', 'item.unit_of_measure')
            ->orderBy('branch.name')->orderBy('item.item_code')
            ->selectRaw('branch.name as branch, item.item_code, item.name as item, item.unit_of_measure as unit, COUNT(*) as locations, SUM(stock.quantity) as quantity')->get()
            ->map(fn (object $row): array => [
                'branch' => $row->branch, 'item_code' => $row->item_code, 'item' => $row->item, 'unit' => $row->unit,
                'locations' => (int) $row->locations, 'quantity' => AccountingDecimal::string($this->decimal($row->quantity)),
            ])->all();

        return $this->report('branch-summary', [
            ['key' => 'branch', 'label' => 'Branch'], ['key' => 'item_code', 'label' => 'Item code'], ['key' => 'item', 'label' => 'Item'], ['key' => 'unit', 'label' => 'Unit'],
            ['key' => 'locations', 'label' => 'Locations', 'type' => 'number'], ['key' => 'quantity', 'label' => 'Quantity on hand', 'type' => 'quantity'],
        ], $rows, $this->totals('branch', $rows, ['quantity']), $meta, 'Quantities only; stock is not valued.');
    }

    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $meta
     */
    private function receipts(array $branchIds, array $filters, CarbonImmutable $from, CarbonImmutable $to, array $meta): OperationalReport
    {
        $rows = $this->movements($branchIds, $filters, $from, $to, true)->with('purchaseOrder:id,po_number')
            ->where('status', InventoryMovementStatus::Posted->value)->where('type', InventoryMovementType::Receipt->value)
            ->orderBy('transaction_date')->orderBy('id')->get()
            ->map(fn (InventoryMovement $movement): array => [
                ...$this->movementColumns($movement), 'quantity' => $movement->quantity,
                'purchase_order' => (string) ($movement->purchaseOrder?->po_number ?? ''), 'notes' => (string) $movement->notes,
            ])->all();

        return $this->report('receipts', [
            ['key' => 'date', 'label' => 'Date'], ['key' => 'item_code', 'label' => 'Item code'], ['key' => 'item', 'label' => 'Item'], ['key' => 'unit', 'label' => 'Unit'],
            ['key' => 'branch', 'label' => 'Branch'], ['key' => 'location', 'label' => 'Location'], ['key' => 'quantity', 'label' => 'Quantity received', 'type' => 'quantity'],
            ['key' => 'reference', 'label' => 'Reference'], ['key' => 'purchase_order', 'label' => 'Purchase order'], ['key' => 'notes', 'label' => 'Notes'], ['key' => 'recorded_by', 'label' => 'Received by'],
        ], $rows, $this->totals('date', $rows, ['quantity']), $meta, 'Posted receipts only. A purchase order reference is traceability only; quantities are not valued.');
    }

    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $meta
     */
    private function issues(array $branchIds, array $filters, CarbonImmutable $from, CarbonImmutable $to, array $meta): OperationalReport
    {
        $rows = $this->issueQuery($branchIds, $filters, $from, $to)->orderBy('transaction_date')->orderBy('id')->get()
            ->map(fn (InventoryMovement $movement): array => [
                ...$this->movementColumns($movement), 'issued_to' => (string) $movement->issued_to,
                'quantity' => AccountingDecimal::string(AccountingDecimal::of($movement->quantity)->abs()), 'notes' => (string) $movement->notes,
            ])->all();

        return $this->report('issues', [
            ['key' => 'date', 'label' => 'Date'], ['key' => 'item_code', 'label' => 'Item code'], ['key' => 'item', 'label' => 'Item'], ['key' => 'unit', 'label' => 'Unit'],
            ['key' => 'branch', 'label' => 'Branch'], ['key' => 'location', 'label' => 'Location'], ['key' => 'issued_to', 'label' => 'Issued to'],
            ['key' => 'quantity', 'label' => 'Quantity issued', 'type' => 'quantity'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'notes', 'label' => 'Notes'], ['key' => 'recorded_by', 'label' => 'Issued by'],
        ], $rows, $this->totals('date', $rows, ['quantity']), $meta, 'Posted issues only; quantities are not valued.');
    }

    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $meta
     */
    private function issuedTo(array $branchIds, array $filters, CarbonImmutable $from, CarbonImmutable $to, array $meta): OperationalReport
    {
        $groups = $this->issueQuery($branchIds, $filters, $from, $to)->withOnly([])->toBase()
            ->groupBy('issued_to', 'item_id')
            ->selectRaw('issued_to, item_id, COUNT(*) as issues, SUM(quantity) as quantity, MIN(transaction_date) as first_date, MAX(transaction_date) as last_date')->get();
        $items = InventoryItem::query()->whereKey($groups->pluck('item_id')->unique())->get(['id', 'item_code', 'name', 'unit_of_measure'])->keyBy('id');

        $rows = $groups->map(fn (object $group): array => [
            'issued_to' => (string) $group->issued_to, 'item_code' => $items[$group->item_id]->item_code, 'item' => $items[$group->item_id]->name, 'unit' => $items[$group->item_id]->unit_of_measure,
            'issues' => (int) $group->issues, 'quantity' => AccountingDecimal::string($this->decimal($group->quantity)->abs()),
            'first_date' => CarbonImmutable::parse((string) $group->first_date)->format('d M Y'), 'last_date' => CarbonImmutable::parse((string) $group->last_date)->format('d M Y'),
        ])->sortBy(fn (array $row): string => mb_strtolower($row['issued_to']).' '.$row['item_code'])->values()->all();

        return $this->report('issued-to', [
            ['key' => 'issued_to', 'label' => 'Issued to'], ['key' => 'item_code', 'label' => 'Item code'], ['key' => 'item', 'label' => 'Item'], ['key' => 'unit', 'label' => 'Unit'],
            ['key' => 'issues', 'label' => 'Issues', 'type' => 'number'], ['key' => 'quantity', 'label' => 'Quantity issued', 'type' => 'quantity'],
            ['key' => 'first_date', 'label' => 'First issue'], ['key' => 'last_date', 'label' => 'Last issue'],
        ], $rows, $this->totals('issued_to', $rows, ['quantity']), $meta, 'Posted issues only, grouped by the recipient exactly as recorded. Returns are not netted off.');
    }

    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $meta
     */
    private function transfers(array $branchIds, array $filters, CarbonImmutable $from, CarbonImmutable $to, array $meta): OperationalReport
    {
        // A transfer is listed when either leg belongs to a branch in scope; both legs are then loaded so it can be paired.
        $groups = $this->movements($branchIds, $filters, $from, $to)->where('status', InventoryMovementStatus::Posted->value)
            ->whereNotNull('transfer_group')->distinct()->toBase()->pluck('transfer_group');
        $legs = InventoryMovement::query()->with(['item:id,item_code,name,unit_of_measure', 'branch:id,name', 'location:id,name', 'creator:id,name'])
            ->whereIn('transfer_group', $groups)->where('status', InventoryMovementStatus::Posted->value)
            ->orderBy('transaction_date')->orderBy('id')->get()->groupBy('transfer_group');

        $problems = 0;
        $rows = [];
        foreach ($legs as $pair) {
            $out = $pair->firstWhere('type', InventoryMovementType::TransferOut);
            $in = $pair->firstWhere('type', InventoryMovementType::TransferIn);
            $first = $out ?? $in ?? $pair->first();
            $quantityOut = $out ? AccountingDecimal::of($out->quantity)->abs() : null;
            $quantityIn = $in ? AccountingDecimal::of($in->quantity) : null;
            $check = match (true) {
                ! $out || ! $in || $pair->count() !== 2 => 'UNPAIRED',
                ! $quantityOut->isEqualTo($quantityIn) => 'QUANTITY MISMATCH',
                default => 'Paired',
            };
            $problems += $check === 'Paired' ? 0 : 1;
            $rows[] = [
                'date' => $first->transaction_date->format('d M Y'), 'item_code' => $first->item->item_code, 'item' => $first->item->name, 'unit' => $first->item->unit_of_measure,
                'from_branch' => (string) $out?->branch->name, 'from_location' => (string) $out?->location->name, 'to_branch' => (string) $in?->branch->name, 'to_location' => (string) $in?->location->name,
                'quantity_out' => $quantityOut ? AccountingDecimal::string($quantityOut) : '', 'quantity_in' => $quantityIn ? AccountingDecimal::string($quantityIn) : '',
                'check' => $check, 'reference' => (string) $first->reference, 'recorded_by' => (string) $first->creator?->name,
            ];
        }

        return $this->report('transfers', [
            ['key' => 'date', 'label' => 'Date'], ['key' => 'item_code', 'label' => 'Item code'], ['key' => 'item', 'label' => 'Item'], ['key' => 'unit', 'label' => 'Unit'],
            ['key' => 'from_branch', 'label' => 'From branch'], ['key' => 'from_location', 'label' => 'From location'], ['key' => 'to_branch', 'label' => 'To branch'], ['key' => 'to_location', 'label' => 'To location'],
            ['key' => 'quantity_out', 'label' => 'Quantity out', 'type' => 'quantity'], ['key' => 'quantity_in', 'label' => 'Quantity in', 'type' => 'quantity'],
            ['key' => 'check', 'label' => 'Pairing'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'recorded_by', 'label' => 'Transferred by'],
        ], $rows, $this->totals('date', $rows, ['quantity_out', 'quantity_in']), $meta, 'Each row is one transfer with its two legs. A transfer is listed when either leg belongs to the selected branch.',
            $problems > 0 ? [$problems.' transfer(s) are not correctly paired. Review them.'] : []);
    }

    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $meta
     */
    private function lowStock(array $branchIds, array $filters, array $meta): OperationalReport
    {
        // The low-stock rule itself belongs to the inventory module and is reused unchanged.
        $low = $this->inventory->lowStockQuery($branchIds)->tap(fn ($query) => $this->filterItems($query, $filters, 'item.'))->get();
        $items = InventoryItem::query()->with('category:id,name')->whereKey($low->pluck('item_id')->unique())->get()->keyBy('id');
        $branches = Branch::query()->whereKey($low->pluck('branch_id')->unique())->pluck('name', 'id');

        $rows = $low->map(function (object $row) use ($items, $branches): array {
            $item = $items[$row->item_id];
            $quantity = $this->decimal($row->total_quantity);
            $minimum = AccountingDecimal::of($item->minimum_stock_level);

            return [
                'item_code' => $item->item_code, 'item' => $item->name, 'category' => (string) $item->category?->name, 'unit' => $item->unit_of_measure, 'branch' => (string) $branches[$row->branch_id],
                'quantity' => AccountingDecimal::string($quantity), 'minimum' => AccountingDecimal::string($minimum), 'shortfall' => AccountingDecimal::string($minimum->minus($quantity)),
            ];
        })->sortBy(fn (array $row): string => $row['branch'].' '.$row['item_code'])->values()->all();

        return $this->report('low-stock', [
            ['key' => 'item_code', 'label' => 'Item code'], ['key' => 'item', 'label' => 'Item'], ['key' => 'category', 'label' => 'Category'], ['key' => 'unit', 'label' => 'Unit'], ['key' => 'branch', 'label' => 'Branch'],
            ['key' => 'quantity', 'label' => 'Quantity on hand', 'type' => 'quantity'], ['key' => 'minimum', 'label' => 'Minimum level', 'type' => 'quantity'], ['key' => 'shortfall', 'label' => 'Below minimum by', 'type' => 'quantity'],
        ], $rows, [], $meta, 'An active item is low in a branch where it is stocked when the branch quantity is at or below a minimum level greater than zero.');
    }

    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $meta
     */
    private function adjustments(array $branchIds, array $filters, CarbonImmutable $from, CarbonImmutable $to, array $meta): OperationalReport
    {
        $status = InventoryMovementStatus::tryFrom((string) ($filters['status'] ?? ''));
        $rows = $this->movements($branchIds, $filters, $from, $to, true)->with('reviewer:id,name')
            ->where('type', InventoryMovementType::Adjustment->value)
            ->when($status, fn ($query) => $query->where('status', $status->value))
            ->orderBy('transaction_date')->orderBy('id')->get()
            ->map(fn (InventoryMovement $movement): array => [
                ...$this->movementColumns($movement), 'quantity' => $movement->quantity,
                'status' => $movement->status === InventoryMovementStatus::Posted ? 'Approved' : $movement->status->label(),
                'reason' => (string) $movement->notes, 'requested_at' => (string) $movement->created_at?->format('d M Y H:i'),
                'approved_by' => $movement->status === InventoryMovementStatus::Posted ? (string) $movement->reviewer?->name : '',
                'rejected_by' => $movement->status === InventoryMovementStatus::Rejected ? (string) $movement->reviewer?->name : '',
                'decided_at' => (string) $movement->approved_at?->format('d M Y H:i'), 'rejection_reason' => (string) $movement->rejection_reason,
            ])->all();

        return $this->report('adjustments', [
            ['key' => 'date', 'label' => 'Date'], ['key' => 'item_code', 'label' => 'Item code'], ['key' => 'item', 'label' => 'Item'], ['key' => 'unit', 'label' => 'Unit'],
            ['key' => 'branch', 'label' => 'Branch'], ['key' => 'location', 'label' => 'Location'], ['key' => 'quantity', 'label' => 'Adjustment quantity', 'type' => 'quantity'],
            ['key' => 'status', 'label' => 'Status'], ['key' => 'reason', 'label' => 'Reason'], ['key' => 'recorded_by', 'label' => 'Requested by'], ['key' => 'requested_at', 'label' => 'Requested at'],
            ['key' => 'approved_by', 'label' => 'Approved by'], ['key' => 'rejected_by', 'label' => 'Rejected by'], ['key' => 'decided_at', 'label' => 'Decided at'], ['key' => 'rejection_reason', 'label' => 'Rejection reason'],
        ], $rows, [], $meta, 'Every adjustment request is listed. Only an approved adjustment changed stock; pending and rejected requests never affect a balance.');
    }

    /**
     * Movements of the branches in scope within the date range.
     *
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @return Builder<InventoryMovement>
     */
    private function movements(array $branchIds, array $filters, CarbonImmutable $from, CarbonImmutable $to, bool $withSearch = false): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return InventoryMovement::query()->with(['item:id,item_code,name,unit_of_measure', 'branch:id,name', 'location:id,name', 'creator:id,name'])
            ->whereIn('branch_id', $branchIds)
            ->whereDate('transaction_date', '>=', $from->toDateString())->whereDate('transaction_date', '<=', $to->toDateString())
            ->when(filled($filters['location_id'] ?? null), fn ($query) => $query->where('location_id', (int) $filters['location_id']))
            ->when(filled($filters['item_id'] ?? null), fn ($query) => $query->where('item_id', (int) $filters['item_id']))
            ->when(filled($filters['category_id'] ?? null), fn ($query) => $query->whereIn('item_id', InventoryItem::query()->where('category_id', (int) $filters['category_id'])->select('id')))
            ->when($withSearch && $search !== '', fn ($query) => $query->where(fn ($query) => $query->where('reference', 'like', '%'.$search.'%')->orWhere('issued_to', 'like', '%'.$search.'%')->orWhere('notes', 'like', '%'.$search.'%')));
    }

    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     * @return Builder<InventoryMovement>
     */
    private function issueQuery(array $branchIds, array $filters, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $recipient = trim((string) ($filters['issued_to'] ?? ''));

        return $this->movements($branchIds, $filters, $from, $to, true)
            ->where('status', InventoryMovementStatus::Posted->value)->where('type', InventoryMovementType::Issue->value)
            ->when($recipient !== '', fn ($query) => $query->where('issued_to', 'like', '%'.$recipient.'%'));
    }

    /**
     * Item filters shared by the balance queries. The prefix is the item
     * table alias ("item.") or empty when the query is on the item itself.
     *
     * @param  array<string, mixed>  $filters
     */
    private function filterItems(mixed $query, array $filters, string $prefix): mixed
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return $query
            ->when(filled($filters['item_id'] ?? null), fn ($query) => $query->where($prefix.'id', (int) $filters['item_id']))
            ->when(filled($filters['category_id'] ?? null), fn ($query) => $query->where($prefix.'category_id', (int) $filters['category_id']))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where($prefix.'item_code', 'like', '%'.$search.'%')->orWhere($prefix.'name', 'like', '%'.$search.'%')));
    }

    /** @return array<string, string> */
    private function movementColumns(InventoryMovement $movement): array
    {
        return [
            'date' => $movement->transaction_date->format('d M Y'), 'item_code' => $movement->item->item_code, 'item' => $movement->item->name, 'unit' => $movement->item->unit_of_measure,
            'branch' => $movement->branch->name, 'location' => $movement->location->name, 'reference' => (string) $movement->reference, 'recorded_by' => (string) $movement->creator?->name,
        ];
    }

    /**
     * Date range of a dated report; it defaults to the current month to date.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(array $filters): array
    {
        $to = filled($filters['date_to'] ?? null) ? CarbonImmutable::parse($filters['date_to'])->startOfDay() : CarbonImmutable::today();
        $from = filled($filters['date_from'] ?? null) ? CarbonImmutable::parse($filters['date_from'])->startOfDay() : $to->startOfMonth();

        return [$from, $to];
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function meta(string $report, array $scope, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $meta = ['Branch' => $scope['label']];
        $meta[self::isDated($report) ? 'Period' : 'As of'] = self::isDated($report) ? $from->format('d M Y').' to '.$to->format('d M Y') : now()->format('d M Y H:i');
        if (filled($filters['item_id'] ?? null)) {
            $item = InventoryItem::query()->find((int) $filters['item_id']);
            $meta['Item'] = $item ? $item->item_code.' — '.$item->name : 'Unknown item';
        }
        if (filled($filters['category_id'] ?? null)) {
            $meta['Category'] = (string) InventoryCategory::query()->whereKey((int) $filters['category_id'])->value('name');
        }
        if (filled($filters['location_id'] ?? null)) {
            $meta['Location'] = (string) DB::table('inventory_locations')->where('id', (int) $filters['location_id'])->value('name');
        }
        if ($report === 'movements' && ($type = InventoryMovementType::tryFrom((string) ($filters['type'] ?? '')))) {
            $meta['Type'] = $type->label();
        }
        if ($report === 'adjustments' && ($status = InventoryMovementStatus::tryFrom((string) ($filters['status'] ?? '')))) {
            $meta['Status'] = $status === InventoryMovementStatus::Posted ? 'Approved' : $status->label();
        }
        if (in_array($report, ['issues', 'issued-to'], true) && filled($filters['issued_to'] ?? null)) {
            $meta['Issued to'] = (string) $filters['issued_to'];
        }
        if (filled($filters['search'] ?? null)) {
            $meta['Search'] = (string) $filters['search'];
        }

        return $meta;
    }

    /**
     * A total row, only when every listed row uses the same unit of measure:
     * quantities in different units are never added together.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function totals(string $labelKey, array $rows, array $keys): array
    {
        $units = array_values(array_unique(array_column($rows, 'unit')));
        if (count($units) !== 1) {
            return [];
        }
        $total = [$labelKey => 'Total ('.$units[0].')', '_style' => 'total'];
        foreach ($keys as $key) {
            $sum = BigDecimal::zero()->toScale(4);
            foreach ($rows as $row) {
                $sum = $sum->plus(AccountingDecimal::of($row[$key] === '' ? '0' : $row[$key]));
            }
            $total[$key] = AccountingDecimal::string($sum);
        }

        return [$total];
    }

    /** MySQL returns SUM of a DECIMAL exactly; SQLite returns a float, from which the 4-decimal value is restored. */
    private function decimal(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? '0'))->toScale(4, RoundingMode::HalfUp);
    }

    /**
     * @param  list<array{key: string, label: string, type?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $totals
     * @param  array<string, string>  $meta
     * @param  list<string>  $warnings
     */
    private function report(string $key, array $columns, array $rows, array $totals, array $meta, ?string $note, array $warnings = []): OperationalReport
    {
        return new OperationalReport($key, self::REPORTS[$key]['title'], self::REPORTS[$key]['description'], $columns, $rows, $totals, $meta, $note, $warnings, null, self::PDF_LAYOUTS[$key]);
    }
}
