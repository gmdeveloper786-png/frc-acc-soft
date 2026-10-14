<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAssetCategoryRequest;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Services\Assets\AssetCategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AssetCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $categories = AssetCategory::query()->with(['assetAccount', 'accumulatedDepreciationAccount', 'depreciationExpenseAccount'])->withCount('assets')
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query->where('code', 'like', '%'.$request->string('search').'%')->orWhere('name', 'like', '%'.$request->string('search').'%')))
            ->when(in_array($request->input('status'), ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->orderBy('code')->paginate(20)->withQueryString();

        return view('assets.categories.index', ['categories' => $categories]);
    }

    public function create(): View
    {
        return view('assets.categories.form', $this->formData());
    }

    public function store(SaveAssetCategoryRequest $request, AssetCategoryService $service): RedirectResponse
    {
        $category = $service->create($request->validated(), $request->user());

        return redirect()->route('asset-categories.index')->with('status', "Asset category {$category->code} created.");
    }

    public function edit(AssetCategory $assetCategory): View
    {
        return view('assets.categories.form', [...$this->formData(), 'category' => $assetCategory]);
    }

    public function update(SaveAssetCategoryRequest $request, AssetCategory $assetCategory, AssetCategoryService $service): RedirectResponse
    {
        $service->update($assetCategory, $request->validated(), $request->user());

        return redirect()->route('asset-categories.index')->with('status', 'Asset category updated. Existing assets keep the accounts they were registered with.');
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        $ledgers = Account::query()->where('level', 'ledger')->where('is_postable', true)->where('is_active', true)->orderBy('code')->get();

        return [
            'assetLedgers' => $ledgers->filter(fn (Account $account): bool => $account->account_type->value === 'asset' && $account->normal_balance->value === 'debit'),
            'contraLedgers' => $ledgers->filter(fn (Account $account): bool => $account->account_type->value === 'asset' && $account->normal_balance->value === 'credit'),
            'expenseLedgers' => $ledgers->filter(fn (Account $account): bool => $account->account_type->value === 'expense'),
        ];
    }
}
