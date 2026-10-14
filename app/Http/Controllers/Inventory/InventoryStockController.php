<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Services\BranchAccess;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryStockController extends Controller
{
    /** Stock on hand per item, branch and location. */
    public function balances(Request $request, BranchAccess $access, InventoryService $inventory): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $balances = InventoryBalance::query()->with(['item.category', 'branch', 'location'])->whereIn('inventory_balances.branch_id', $branches->pluck('id'))
            ->when($request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')), fn ($query) => $query->where('inventory_balances.branch_id', $request->integer('branch_id')))
            ->when($request->filled('location_id'), fn ($query) => $query->where('location_id', $request->integer('location_id')))
            ->when($request->filled('category_id'), fn ($query) => $query->whereHas('item', fn ($query) => $query->where('category_id', $request->integer('category_id'))))
            ->when($request->filled('search'), fn ($query) => $query->whereHas('item', fn ($query) => $query->where('item_code', 'like', '%'.$request->string('search').'%')->orWhere('name', 'like', '%'.$request->string('search').'%')))
            ->when($request->boolean('low_stock'), fn ($query) => $query
                ->whereHas('item', fn ($query) => $query->where('is_active', true)->where('minimum_stock_level', '>', 0))
                ->whereRaw('(select sum(branch_stock.quantity) from inventory_balances as branch_stock where branch_stock.item_id = inventory_balances.item_id and branch_stock.branch_id = inventory_balances.branch_id) <= (select minimum_stock_level from inventory_items where inventory_items.id = inventory_balances.item_id)'))
            ->orderBy('inventory_balances.branch_id')->orderBy('item_id')->orderBy('location_id')->paginate(20)->withQueryString();

        $lowKeys = $inventory->lowStockQuery($balances->pluck('branch_id')->unique())->whereIn('stock.item_id', $balances->pluck('item_id')->unique())->get()
            ->map(fn (object $row): string => $row->item_id.':'.$row->branch_id);

        return view('inventory.balances.index', [
            'balances' => $balances, 'branches' => $branches, 'lowKeys' => $lowKeys,
            'locations' => InventoryLocation::query()->with('branch')->whereIn('branch_id', $branches->pluck('id'))->orderBy('name')->get(),
            'categories' => InventoryCategory::query()->orderBy('name')->get(),
        ]);
    }

    /** Items at or below their minimum level, one row per branch. */
    public function lowStock(Request $request, BranchAccess $access, InventoryService $inventory): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $branchIds = $request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')) ? [$request->integer('branch_id')] : $branches->pluck('id');

        $alerts = $inventory->lowStockQuery($branchIds)
            ->when($request->filled('category_id'), fn ($query) => $query->where('item.category_id', $request->integer('category_id')))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query->where('item.item_code', 'like', '%'.$request->string('search').'%')->orWhere('item.name', 'like', '%'.$request->string('search').'%')))
            ->orderBy('item.name')->orderBy('stock.branch_id')->paginate(20)->withQueryString();

        return view('inventory.low-stock.index', [
            'alerts' => $alerts, 'branches' => $branches,
            'items' => InventoryItem::query()->with('category')->findMany(collect($alerts->items())->pluck('item_id'))->keyBy('id'),
            'branchNames' => Branch::query()->whereKey(collect($alerts->items())->pluck('branch_id'))->pluck('name', 'id'),
            'categories' => InventoryCategory::query()->orderBy('name')->get(),
        ]);
    }
}
