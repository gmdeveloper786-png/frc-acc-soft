<?php

namespace App\Http\Controllers\AccountingSetup;

use App\Enums\AccountMappingKey;
use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\AccountMapping;
use App\Models\DonationCategory;
use App\Models\FinancialAccount;
use App\Models\FrcService;
use App\Services\AccountMappingService;
use App\Services\BranchAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountMappingController extends Controller
{
    public function index(Request $request, AccountMappingService $mappings, BranchAccess $access): View
    {
        $current = AccountMapping::query()->with(['account', 'updater'])->get()->keyBy(fn (AccountMapping $mapping): string => $mapping->mapping_key->value);
        $controls = collect(AccountMappingKey::cases())->map(fn (AccountMappingKey $key): array => [
            'key' => $key,
            'mapping' => $current->get($key->value),
            'valid' => $current->has($key->value) && $mappings->isUsable($current->get($key->value)->account, $key->accountType()),
            'options' => $mappings->eligibleAccounts($key->accountType()),
        ]);

        $services = FrcService::query()->with('revenueAccount')
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->when($request->string('mapped')->toString() === 'yes', fn ($query) => $query->whereNotNull('revenue_account_id'))
            ->when($request->string('mapped')->toString() === 'no', fn ($query) => $query->whereNull('revenue_account_id'))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('accounting-setup.account-mappings.index', [
            'controls' => $controls,
            'services' => $services,
            'incomeAccounts' => $mappings->eligibleAccounts(AccountType::Income),
            'financialAccounts' => FinancialAccount::query()->with(['account', 'branch'])->usableIn($access->scope($request->user())->select('id'))->orderBy('type')->orderBy('name')->get(),
            'donationCategories' => DonationCategory::query()->with('incomeAccount')->orderBy('name')->get(),
            'mappings' => $mappings,
        ]);
    }

    public function update(Request $request, string $mappingKey, AccountMappingService $mappings): RedirectResponse
    {
        $key = AccountMappingKey::tryFrom($mappingKey) ?? abort(404);
        $data = $request->validate(['account_id' => ['nullable', 'integer', 'exists:accounts,id']]);
        $mappings->set($key, filled($data['account_id'] ?? null) ? (int) $data['account_id'] : null, $request->user());

        return back()->with('status', $key->label().' mapping saved.');
    }

    public function updateService(Request $request, FrcService $service, AccountMappingService $mappings): RedirectResponse
    {
        $data = $request->validate(['account_id' => ['nullable', 'integer', 'exists:accounts,id']]);
        $mappings->setServiceRevenue($service, filled($data['account_id'] ?? null) ? (int) $data['account_id'] : null, $request->user());

        return back()->with('status', "Fee income mapping for {$service->name} saved.");
    }
}
