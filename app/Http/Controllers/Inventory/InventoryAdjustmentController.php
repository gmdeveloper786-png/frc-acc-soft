<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Http\Controllers\Controller;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Services\BranchAccess;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryAdjustmentController extends Controller
{
    /** Adjustments waiting for a second user's decision. */
    public function index(Request $request, BranchAccess $access): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $adjustments = InventoryMovement::query()->with(['item', 'branch', 'location', 'creator'])->whereIn('branch_id', $branches->pluck('id'))
            ->where('type', InventoryMovementType::Adjustment->value)->where('status', InventoryMovementStatus::Pending->value)
            ->when($request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('search'), fn ($query) => $query->whereHas('item', fn ($query) => $query->where('item_code', 'like', '%'.$request->string('search').'%')->orWhere('name', 'like', '%'.$request->string('search').'%')))
            ->orderBy('transaction_date')->orderBy('id')->paginate(20)->withQueryString();

        $onHand = InventoryBalance::query()->whereIn('location_id', $adjustments->pluck('location_id'))->whereIn('item_id', $adjustments->pluck('item_id'))->get()
            ->mapWithKeys(fn (InventoryBalance $balance): array => [$balance->item_id.':'.$balance->location_id => $balance->quantity]);

        return view('inventory.adjustments.index', ['adjustments' => $adjustments, 'branches' => $branches, 'onHand' => $onHand]);
    }

    public function approve(Request $request, InventoryMovement $inventoryMovement, InventoryService $inventory): RedirectResponse
    {
        $inventory->approveAdjustment($inventoryMovement, $request->user());

        return back()->with('status', 'Adjustment approved and applied to the stock balance.');
    }

    public function reject(Request $request, InventoryMovement $inventoryMovement, InventoryService $inventory): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $inventory->rejectAdjustment($inventoryMovement, $data['reason'], $request->user());

        return back()->with('status', 'Adjustment rejected. Stock was not changed.');
    }
}
