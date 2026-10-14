<?php

namespace App\Http\Controllers\Assets;

use App\Enums\FixedAssetStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveFixedAssetRequest;
use App\Models\AssetCategory;
use App\Models\FixedAsset;
use App\Models\FixedAssetDocument;
use App\Models\PurchaseBill;
use App\Models\Vendor;
use App\Services\AccountingDecimal;
use App\Services\Assets\AssetPolicy;
use App\Services\Assets\FixedAssetService;
use App\Services\BranchAccess;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FixedAssetController extends Controller
{
    public function index(Request $request, BranchAccess $access, AssetPolicy $policy): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $assets = FixedAsset::query()->with(['category', 'branch', 'postedLines'])->whereIn('branch_id', $branches->pluck('id'))
            ->when($request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when(FixedAssetStatus::tryFrom($request->string('status')->toString()), fn ($query, FixedAssetStatus $status) => $query->where('status', $status->value))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query->where('asset_code', 'like', '%'.$request->string('search').'%')->orWhere('name', 'like', '%'.$request->string('search').'%')->orWhere('location', 'like', '%'.$request->string('search').'%')->orWhere('custodian', 'like', '%'.$request->string('search').'%')))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('assets.fixed-assets.index', ['assets' => $assets, 'branches' => $branches, 'categories' => AssetCategory::query()->orderBy('code')->get(), 'policyApproved' => $policy->isApproved()]);
    }

    public function create(Request $request, BranchAccess $access, AssetPolicy $policy): View
    {
        return view('assets.fixed-assets.form', $this->formData($request, $access, $policy));
    }

    public function store(SaveFixedAssetRequest $request, FixedAssetService $service): RedirectResponse
    {
        $asset = $service->register($request->validated(), $request->user());

        return redirect()->route('fixed-assets.show', $asset)->with('status', "Fixed asset {$asset->asset_code} registered as a draft. No accounting entry was posted.");
    }

    public function show(Request $request, FixedAsset $fixedAsset, BranchAccess $access, FixedAssetService $service, AssetPolicy $policy): View
    {
        $this->guardBranch($request, $fixedAsset, $access);
        $fixedAsset->load(['category', 'branch', 'vendor', 'purchaseBill', 'creator', 'assetAccount', 'accumulatedDepreciationAccount', 'depreciationExpenseAccount', 'documents.uploader', 'events.actor']);

        return view('assets.fixed-assets.show', [
            'asset' => $fixedAsset, 'schedule' => $service->schedule($fixedAsset),
            'accumulated' => $fixedAsset->accumulatedDepreciation(), 'netBookValue' => $fixedAsset->netBookValue(),
            'policyApproved' => $policy->isApproved(), 'threshold' => (string) $policy->capitalizationThreshold(),
        ]);
    }

    public function edit(Request $request, FixedAsset $fixedAsset, BranchAccess $access, AssetPolicy $policy): View
    {
        $this->guardBranch($request, $fixedAsset, $access);
        abort_if($fixedAsset->status === FixedAssetStatus::Retired, 403, 'A retired fixed asset cannot be edited.');

        return view('assets.fixed-assets.form', [...$this->formData($request, $access, $policy), 'asset' => $fixedAsset]);
    }

    public function update(SaveFixedAssetRequest $request, FixedAsset $fixedAsset, FixedAssetService $service): RedirectResponse
    {
        $service->update($fixedAsset, $request->validated(), $request->user());

        return redirect()->route('fixed-assets.show', $fixedAsset)->with('status', 'Fixed asset updated.');
    }

    public function activate(Request $request, FixedAsset $fixedAsset, FixedAssetService $service): RedirectResponse
    {
        $service->activate($fixedAsset, $request->user());

        return back()->with('status', 'Fixed asset activated. Its financial terms are now frozen. No accounting entry was posted.');
    }

    public function retire(Request $request, FixedAsset $fixedAsset, FixedAssetService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $service->retire($fixedAsset, $data['reason'], $request->user());

        return back()->with('status', 'Fixed asset retired. No disposal entry was posted.');
    }

    public function storeDocument(Request $request, FixedAsset $fixedAsset, FixedAssetService $service): RedirectResponse
    {
        $request->validate(['document' => ['required', 'file', 'mimes:'.implode(',', FixedAssetService::DOCUMENT_EXTENSIONS), 'max:'.FixedAssetService::DOCUMENT_MAX_KILOBYTES]]);
        $service->addDocument($fixedAsset, $request->file('document'), $request->user());

        return back()->with('status', 'Supporting document uploaded.');
    }

    public function downloadDocument(Request $request, FixedAsset $fixedAsset, FixedAssetDocument $document, BranchAccess $access): StreamedResponse
    {
        $this->guardBranch($request, $fixedAsset, $access);
        abort_unless((int) $document->fixed_asset_id === (int) $fixedAsset->id, 404);
        abort_unless(Storage::disk($document->disk)->exists($document->path), 404);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    /** Print-friendly asset register: cost, accumulated depreciation and net book value per branch. */
    public function register(Request $request, BranchAccess $access, AssetPolicy $policy): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $selected = $request->filled('branch_id') ? $branches->firstWhere('id', $request->integer('branch_id')) : null;
        abort_if($request->filled('branch_id') && ! $selected, 403);
        $statuses = $request->boolean('include_retired') ? [FixedAssetStatus::Active, FixedAssetStatus::FullyDepreciated, FixedAssetStatus::Retired] : [FixedAssetStatus::Active, FixedAssetStatus::FullyDepreciated];

        $assets = FixedAsset::query()->with(['category', 'branch', 'postedLines'])
            ->whereIn('branch_id', $selected ? [$selected->id] : $branches->pluck('id'))
            ->whereIn('status', array_map(fn (FixedAssetStatus $status): string => $status->value, $statuses))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->orderBy('branch_id')->orderBy('asset_code')->get();

        $zero = BigDecimal::zero()->toScale(4);
        $groups = $assets->groupBy('branch_id')->map(function ($branchAssets) use ($zero): array {
            $rows = $branchAssets->map(function (FixedAsset $asset): array {
                $accumulated = $asset->accumulatedDepreciation();

                return ['asset' => $asset, 'cost' => $asset->acquisition_cost, 'accumulated' => $accumulated, 'net_book_value' => AccountingDecimal::string(AccountingDecimal::of($asset->acquisition_cost)->minus(AccountingDecimal::of($accumulated)))];
            });
            $sum = fn (string $key): string => AccountingDecimal::string($rows->reduce(fn (BigDecimal $carry, array $row): BigDecimal => $carry->plus(AccountingDecimal::of($row[$key])), $zero));

            return ['branch' => $branchAssets->first()->branch, 'rows' => $rows, 'cost' => $sum('cost'), 'accumulated' => $sum('accumulated'), 'net_book_value' => $sum('net_book_value')];
        })->values();

        return view('assets.fixed-assets.register', ['groups' => $groups, 'branches' => $branches, 'categories' => AssetCategory::query()->orderBy('code')->get(), 'policyApproved' => $policy->isApproved()]);
    }

    private function guardBranch(Request $request, FixedAsset $asset, BranchAccess $access): void
    {
        abort_unless($access->canAccess($request->user(), $asset->branch), 403);
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, BranchAccess $access, AssetPolicy $policy): array
    {
        $branches = $access->scope($request->user())->whereIn('status', ['publish', 'active'])->orderBy('name')->get();

        return [
            'branches' => $branches,
            'categories' => AssetCategory::query()->where('is_active', true)->orderBy('code')->get(),
            'vendors' => Vendor::query()->where('is_active', true)->orderBy('name')->get(),
            'bills' => PurchaseBill::query()->with('vendor')->whereIn('branch_id', $branches->pluck('id'))->whereNotIn('status', ['rejected', 'reversed'])->orderByDesc('invoice_date')->limit(200)->get(),
            'policyApproved' => $policy->isApproved(), 'threshold' => (string) $policy->capitalizationThreshold(),
        ];
    }
}
