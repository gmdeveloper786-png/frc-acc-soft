<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveInventoryCategoryRequest;
use App\Models\InventoryCategory;
use App\Services\Inventory\InventoryCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $categories = InventoryCategory::query()->withCount('items')
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('inventory.categories.index', ['categories' => $categories]);
    }

    public function store(SaveInventoryCategoryRequest $request, InventoryCatalogService $catalog): RedirectResponse
    {
        $category = $catalog->saveCategory($request->validated(), $request->user());

        return back()->with('status', "Category {$category->name} added.");
    }

    public function update(SaveInventoryCategoryRequest $request, InventoryCategory $inventoryCategory, InventoryCatalogService $catalog): RedirectResponse
    {
        $catalog->saveCategory($request->validated(), $request->user(), $inventoryCategory);

        return back()->with('status', 'Category updated.');
    }
}
