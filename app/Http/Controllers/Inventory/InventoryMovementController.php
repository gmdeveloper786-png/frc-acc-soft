<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryMovementRequest;
use App\Http\Requests\StoreInventoryTransferRequest;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Services\BranchAccess;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryMovementController extends Controller
{
    /** Stock movement history across the branches the user may see. */
    public function index(Request $request, BranchAccess $access): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $movements = InventoryMovement::query()->with(['item', 'branch', 'location', 'creator', 'reviewer', 'purchaseOrder'])->whereIn('branch_id', $branches->pluck('id'))
            ->when($request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('item_id'), fn ($query) => $query->where('item_id', $request->integer('item_id')))
            ->when(InventoryMovementType::tryFrom($request->string('type')->toString()), fn ($query, InventoryMovementType $type) => $query->where('type', $type->value))
            ->when(InventoryMovementStatus::tryFrom($request->string('status')->toString()), fn ($query, InventoryMovementStatus $status) => $query->where('status', $status->value))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('transaction_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('transaction_date', '<=', $request->date('to')))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query->where('reference', 'like', '%'.$request->string('search').'%')->orWhere('issued_to', 'like', '%'.$request->string('search').'%')))
            ->orderByDesc('transaction_date')->orderByDesc('id')->paginate(20)->withQueryString();

        return view('inventory.movements.index', ['movements' => $movements, 'branches' => $branches, 'items' => InventoryItem::query()->orderBy('item_code')->get(['id', 'item_code', 'name'])]);
    }

    public function create(Request $request, BranchAccess $access, InventoryService $inventory, string $kind): View
    {
        $branchIds = $access->scope($request->user())->pluck('branches.id');

        return view('inventory.movements.create', [
            ...$this->formData($branchIds),
            'kind' => $kind,
            'allowsNegativeStock' => $inventory->allowsNegativeStock(),
            'purchaseOrders' => $kind === 'receipt'
                ? PurchaseOrder::query()->with(['vendor', 'branch', 'lines'])->whereIn('branch_id', $branchIds)->whereIn('status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::Issued->value])->orderByDesc('po_date')->orderByDesc('id')->limit(200)->get()
                : collect(),
        ]);
    }

    public function store(StoreInventoryMovementRequest $request, InventoryService $inventory): RedirectResponse
    {
        $data = $request->validated();

        [$movement, $message] = match ($request->kind()) {
            'receipt' => [$inventory->receive($data, $request->user()), 'Stock received. No accounting entry was posted.'],
            'issue' => [$inventory->issue($data, $request->user()), 'Stock issued.'],
            'return' => [$inventory->returnStock($data, $request->user()), 'Returned stock recorded.'],
            'adjustment' => [$inventory->requestAdjustment($data, $request->user()), 'Adjustment submitted. Stock changes only after another user approves it.'],
        };

        return redirect()->route('inventory.movements.index', ['item_id' => $movement->item_id])->with('status', $message);
    }

    public function createTransfer(Request $request, BranchAccess $access): View
    {
        return view('inventory.movements.transfer', $this->formData($access->scope($request->user())->pluck('branches.id')));
    }

    public function storeTransfer(StoreInventoryTransferRequest $request, InventoryService $inventory): RedirectResponse
    {
        $legs = $inventory->transfer($request->validated(), $request->user());

        return redirect()->route('inventory.movements.index', ['item_id' => $legs['out']->item_id])->with('status', 'Stock transferred. Both legs were recorded together.');
    }

    /**
     * @param  iterable<int>  $branchIds
     * @return array<string, mixed>
     */
    private function formData(iterable $branchIds): array
    {
        return [
            'items' => InventoryItem::query()->where('is_active', true)->orderBy('item_code')->get(),
            'locations' => InventoryLocation::query()->with('branch')->where('is_active', true)->whereIn('branch_id', collect($branchIds)->all())->orderBy('name')->get()->sortBy(fn (InventoryLocation $location): string => $location->branch->name.'|'.$location->name)->groupBy(fn (InventoryLocation $location): string => $location->branch->name),
        ];
    }
}
