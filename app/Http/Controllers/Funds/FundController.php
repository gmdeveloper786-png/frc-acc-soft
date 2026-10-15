<?php

namespace App\Http\Controllers\Funds;

use App\Http\Controllers\Controller;
use App\Http\Requests\Funds\SaveFundRequest;
use App\Models\Account;
use App\Models\Fund;
use App\Services\Funds\FundService;
use App\Services\Funds\WorkbookFundCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FundController extends Controller
{
    public function index(Request $request, WorkbookFundCodes $workbook): View
    {
        $funds = Fund::query()->with('parent')->withCount('permittedAccounts')
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($query) => $query->where('code', 'like', '%'.$request->string('search').'%')->orWhere('name', 'like', '%'.$request->string('search').'%')))
            ->when(array_key_exists((string) $request->input('category'), Fund::CATEGORIES), fn ($query) => $query->where('category', $request->input('category')))
            ->when(in_array($request->input('status'), ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $request->input('status') === 'active'))
            ->orderBy('code')->paginate(25)->withQueryString();

        return view('funds.index', [
            'funds' => $funds,
            'missingWorkbookCodes' => $workbook->missing(),
        ]);
    }

    public function create(): View
    {
        return view('funds.form', $this->formData(null));
    }

    public function store(SaveFundRequest $request, FundService $service): RedirectResponse
    {
        $fund = $service->create($request->validated(), $request->user());

        return redirect()->route('funds.index')->with('status', "Fund {$fund->code} created.");
    }

    public function edit(Fund $fund): View
    {
        return view('funds.form', $this->formData($fund->load('permittedAccounts')));
    }

    public function update(SaveFundRequest $request, Fund $fund, FundService $service): RedirectResponse
    {
        $service->update($fund, $request->validated(), $request->user());

        return redirect()->route('funds.index')->with('status', "Fund {$fund->code} updated.");
    }

    public function loadWorkbookCodes(Request $request, WorkbookFundCodes $workbook): RedirectResponse
    {
        $created = $workbook->load($request->user());

        return back()->with('status', $created === [] ? 'Every workbook fund code already exists; nothing was changed.' : 'Workbook fund codes loaded: '.implode(', ', $created).'.');
    }

    /** @return array<string, mixed> */
    private function formData(?Fund $fund): array
    {
        return [
            'fund' => $fund,
            'parents' => Fund::query()->whereNull('parent_id')->when($fund, fn ($query) => $query->whereKeyNot($fund->id))->orderBy('code')->get(),
            'chargeLedgers' => Account::query()->where('level', 'ledger')->where('is_postable', true)->where('is_active', true)
                ->whereIn('account_type', ['expense', 'asset'])->orderBy('code')->get(),
        ];
    }
}
