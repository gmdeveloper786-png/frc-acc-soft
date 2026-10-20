<?php

namespace App\Http\Controllers\AccountingSetup;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOpeningBalanceRequest;
use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\OpeningBalanceBatch;
use App\Services\BranchAccess;
use App\Services\OpeningBalancePostingService;
use App\Services\OpeningBalanceService;
use App\Services\OpeningBalanceValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OpeningBalanceController extends Controller
{
    public function index(Request $request, BranchAccess $access): View
    {
        $allowed = $access->scope($request->user())->pluck('id');
        $batches = OpeningBalanceBatch::query()->with(['fiscalYear', 'branch'])->whereIn('branch_id', $allowed)->latest()->paginate(20);

        return view('accounting-setup.opening-balances.index', compact('batches'));
    }

    public function create(Request $request, BranchAccess $access, OpeningBalanceValidationService $validator): View
    {
        $fiscalYears = FiscalYear::query()->orderByDesc('start_date')->get();
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $accounts = $validator->eligibleAccounts()->with('parent')->orderBy('code')->get();
        $openingDate = $fiscalYears->first()?->start_date->toDateString();

        return view('accounting-setup.opening-balances.create', compact('fiscalYears', 'branches', 'accounts', 'openingDate'));
    }

    public function store(StoreOpeningBalanceRequest $request, OpeningBalanceService $service, BranchAccess $access): RedirectResponse
    {
        $branch = Branch::query()->findOrFail($request->integer('branch_id'));
        abort_unless($access->canAccess($request->user(), $branch), 403);
        $batch = $service->saveDraft($request->validated(), $request->user());

        return redirect()->route('opening-balances.show', $batch)->with('status', 'Balanced opening draft saved. Nothing has been posted.');
    }

    public function show(Request $request, OpeningBalanceBatch $openingBalance, BranchAccess $access, OpeningBalancePostingService $posting): View
    {
        abort_unless($access->canAccess($request->user(), $openingBalance->branch), 403);
        $openingBalance->load(['branch', 'fiscalYear', 'lines.account', 'journalEntry']);
        $canApprove = $posting->canApprove($openingBalance, $request->user());
        $canPost = $posting->canPost($openingBalance, $request->user());
        $canReturn = $posting->canReturn($openingBalance, $request->user());
        $approvalInvalid = $openingBalance->status === 'approved' && ! $posting->approvalIsValid($openingBalance);

        return view('accounting-setup.opening-balances.show', compact('openingBalance', 'canApprove', 'canPost', 'canReturn', 'approvalInvalid'));
    }

    public function approve(Request $request, OpeningBalanceBatch $openingBalance, OpeningBalancePostingService $posting): RedirectResponse
    {
        $posting->approve($openingBalance, $request->user());

        return back()->with('status', 'Opening balance approved.');
    }

    public function returnToDraft(Request $request, OpeningBalanceBatch $openingBalance, OpeningBalancePostingService $posting): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:'.OpeningBalancePostingService::MIN_RETURN_REASON_LENGTH, 'max:2000']]);
        $posting->returnToDraft($openingBalance, $validated['reason'], $request->user());

        return back()->with('status', 'Opening balance returned to draft. It needs a new independent approval before it can be posted.');
    }

    public function post(Request $request, OpeningBalanceBatch $openingBalance, OpeningBalancePostingService $posting): RedirectResponse
    {
        $posting->post($openingBalance, $request->user());

        return back()->with('status', 'Opening balance posted through the Journal Engine.');
    }
}
