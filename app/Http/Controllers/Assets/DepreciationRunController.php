<?php

namespace App\Http\Controllers\Assets;

use App\Enums\DepreciationRunStatus;
use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Models\DepreciationRun;
use App\Services\Assets\AssetPolicy;
use App\Services\Assets\DepreciationRunService;
use App\Services\BranchAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DepreciationRunController extends Controller
{
    public function index(Request $request, BranchAccess $access, AssetPolicy $policy): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $runs = DepreciationRun::query()->with(['branch', 'accountingPeriod', 'creator'])->withCount('lines')->whereIn('branch_id', $branches->pluck('id'))
            ->when($request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when(DepreciationRunStatus::tryFrom($request->string('status')->toString()), fn ($query, DepreciationRunStatus $status) => $query->where('status', $status->value))
            ->when($request->filled('search'), fn ($query) => $query->whereHas('accountingPeriod', fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%')))
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('assets.depreciation-runs.index', ['runs' => $runs, 'branches' => $branches, 'policyApproved' => $policy->isApproved()]);
    }

    /** New run screen. With a branch and period selected it shows a preview; nothing is stored. */
    public function create(Request $request, BranchAccess $access, DepreciationRunService $service, AssetPolicy $policy): View
    {
        $branches = $access->scope($request->user())->whereIn('status', ['publish', 'active'])->orderBy('name')->get();
        $periods = AccountingPeriod::query()->where('status', 'open')->whereHas('fiscalYear', fn ($query) => $query->where('status', 'open'))->orderBy('start_date')->get();
        $branch = $request->filled('branch_id') ? $branches->firstWhere('id', $request->integer('branch_id')) : null;
        $period = $request->filled('accounting_period_id') ? $periods->firstWhere('id', $request->integer('accounting_period_id')) : null;
        abort_if($request->filled('branch_id') && ! $branch, 403);

        return view('assets.depreciation-runs.create', [
            'branches' => $branches, 'periods' => $periods, 'branch' => $branch, 'period' => $period,
            'preview' => $branch && $period ? $service->preview($branch, $period, $request->user()) : null,
            'policyApproved' => $policy->isApproved(), 'convention' => $policy->convention(),
        ]);
    }

    public function store(Request $request, DepreciationRunService $service): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'accounting_period_id' => ['required', 'integer', 'exists:accounting_periods,id'],
        ]);
        $run = $service->prepare((int) $data['branch_id'], (int) $data['accounting_period_id'], $request->user());

        return redirect()->route('depreciation-runs.show', $run)->with('status', 'Depreciation run saved as a draft. Nothing has been posted.');
    }

    public function show(Request $request, DepreciationRun $depreciationRun, BranchAccess $access, DepreciationRunService $service, AssetPolicy $policy): View
    {
        abort_unless($access->canAccess($request->user(), $depreciationRun->branch), 403);
        $depreciationRun->load(['branch', 'accountingPeriod', 'journalEntry', 'creator', 'submitter', 'approver', 'poster', 'reverser', 'lines.asset.depreciationExpenseAccount', 'lines.asset.accumulatedDepreciationAccount']);

        return view('assets.depreciation-runs.show', [
            'run' => $depreciationRun, 'canDecide' => $service->canDecide($depreciationRun, $request->user()),
            'policyApproved' => $policy->isApproved(),
        ]);
    }

    public function submit(Request $request, DepreciationRun $depreciationRun, DepreciationRunService $service): RedirectResponse
    {
        $service->submit($depreciationRun, $request->user());

        return back()->with('status', 'Depreciation run recalculated and submitted for approval.');
    }

    public function approve(Request $request, DepreciationRun $depreciationRun, DepreciationRunService $service): RedirectResponse
    {
        $service->approve($depreciationRun, $request->user());

        return back()->with('status', 'Depreciation run approved. It has not been posted yet.');
    }

    public function reject(Request $request, DepreciationRun $depreciationRun, DepreciationRunService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $service->reject($depreciationRun, $data['reason'], $request->user());

        return back()->with('status', 'Depreciation run returned to draft.');
    }

    public function post(Request $request, DepreciationRun $depreciationRun, DepreciationRunService $service): RedirectResponse
    {
        $run = $service->post($depreciationRun, $request->user());

        return back()->with('status', 'Depreciation posted as voucher '.$run->journalEntry?->voucher_number.'.');
    }

    public function reverse(Request $request, DepreciationRun $depreciationRun, DepreciationRunService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000'], 'reversal_date' => ['nullable', 'date']]);
        $service->reverse($depreciationRun, $data['reason'], $request->user(), $data['reversal_date'] ?? null);

        return back()->with('status', 'Depreciation run reversed. The period can be prepared again.');
    }

    public function discard(Request $request, DepreciationRun $depreciationRun, DepreciationRunService $service): RedirectResponse
    {
        $service->discard($depreciationRun, $request->user());

        return redirect()->route('depreciation-runs.index')->with('status', 'Draft depreciation run discarded.');
    }
}
