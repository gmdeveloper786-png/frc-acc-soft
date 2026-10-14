<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveInventoryItemRequest;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Services\Inventory\InventoryCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryItemController extends Controller
{
    public function index(Request $request): View
    {
        $items = InventoryItem::query()->with('category')
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query->where('item_code', 'like', '%'.$request->string('search').'%')->orWhere('name', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when(in_array($request->input('status'), ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->orderBy('item_code')->paginate(20)->withQueryString();

        return view('inventory.items.index', ['items' => $items, 'categories' => InventoryCategory::query()->orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('inventory.items.create', ['categories' => InventoryCategory::query()->where('is_active', true)->orderBy('name')->get()]);
    }

    public function store(SaveInventoryItemRequest $request, InventoryCatalogService $catalog): RedirectResponse
    {
        $item = $catalog->saveItem($request->validated(), $request->user());

        return redirect()->route('inventory.items.index')->with('status', "Inventory item {$item->item_code} created. It holds no stock until a receipt is recorded.");
    }

    public function edit(InventoryItem $inventoryItem): View
    {
        return view('inventory.items.edit', [
            'item' => $inventoryItem,
            'categories' => InventoryCategory::query()->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $inventoryItem->category_id))->orderBy('name')->get(),
        ]);
    }

    public function update(SaveInventoryItemRequest $request, InventoryItem $inventoryItem, InventoryCatalogService $catalog): RedirectResponse
    {
        $catalog->saveItem($request->validated(), $request->user(), $inventoryItem);

        return redirect()->route('inventory.items.index')->with('status', 'Inventory item updated.');
    }
}
