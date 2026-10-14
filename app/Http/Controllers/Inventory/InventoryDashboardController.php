<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\InventoryMovementStatus;
use App\Enums\InventoryMovementType;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Services\BranchAccess;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InventoryDashboardController extends Controller
{
    /** Real counts only, limited to the branches the user may see. */
    public function __invoke(Request $request, BranchAccess $access, InventoryService $inventory): View
    {
        $branchIds = $access->scope($request->user())->pluck('branches.id');

        return view('inventory.dashboard', [
            'activeItems' => InventoryItem::query()->where('is_active', true)->count(),
            'inactiveItems' => InventoryItem::query()->where('is_active', false)->count(),
            'lowStockCount' => DB::query()->fromSub($inventory->lowStockQuery($branchIds), 'low_stock')->count(),
            'pendingAdjustments' => InventoryMovement::query()->whereIn('branch_id', $branchIds)->where('type', InventoryMovementType::Adjustment->value)->where('status', InventoryMovementStatus::Pending->value)->count(),
            'recentMovements' => InventoryMovement::query()->with(['item', 'branch', 'location', 'creator'])->whereIn('branch_id', $branchIds)->orderByDesc('id')->limit(10)->get(),
            'allowsNegativeStock' => $inventory->allowsNegativeStock(),
        ]);
    }
}
