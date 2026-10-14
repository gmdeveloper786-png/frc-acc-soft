<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveInventoryLocationRequest;
use App\Models\InventoryLocation;
use App\Services\BranchAccess;
use App\Services\Inventory\InventoryCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryLocationController extends Controller
{
    public function index(Request $request, BranchAccess $access): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $locations = InventoryLocation::query()->with('branch')->whereIn('branch_id', $branches->pluck('id'))
            ->when($request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('branch_id')->orderBy('name')->paginate(20)->withQueryString();

        return view('inventory.locations.index', ['locations' => $locations, 'branches' => $branches]);
    }

    public function store(SaveInventoryLocationRequest $request, InventoryCatalogService $catalog): RedirectResponse
    {
        $location = $catalog->saveLocation($request->validated(), $request->user());

        return back()->with('status', "Location {$location->name} added.");
    }

    public function update(SaveInventoryLocationRequest $request, InventoryLocation $inventoryLocation, InventoryCatalogService $catalog): RedirectResponse
    {
        $catalog->saveLocation($request->validated(), $request->user(), $inventoryLocation);

        return back()->with('status', 'Location updated.');
    }
}
