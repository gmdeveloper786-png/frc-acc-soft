<?php

namespace App\Http\Controllers\PettyCash;

use App\Http\Controllers\Controller;
use App\Http\Requests\PettyCash\SaveImprestFloatRequest;
use App\Models\ImprestFloat;
use App\Models\ImprestReplenishment;
use App\Services\AccountingDecimal;
use App\Services\BranchAccess;
use App\Services\PettyCash\ImprestFloatService;
use App\Services\PettyCash\ImprestLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ImprestFloatController extends Controller
{
    public function index(Request $request, BranchAccess $access, ImprestLedgerService $ledger): View
    {
        $floats = ImprestFloat::query()->with(['branch', 'account', 'sourceAccount'])
            ->whereIn('branch_id', $access->scope($request->user())->select('id'))
            ->orderByDesc('is_active')->orderBy('branch_id')->orderBy('id')->paginate(20)->withQueryString();
        $figures = $floats->getCollection()->mapWithKeys(function (ImprestFloat $float) use ($ledger): array {
            $balance = $ledger->balance($float);

            return [$float->id => ['balance' => AccountingDecimal::string($balance), 'due' => AccountingDecimal::string($ledger->due($float, $balance))]];
        })->all();

        return view('imprest.index', compact('floats', 'figures'));
    }

    public function create(Request $request, BranchAccess $access, ImprestFloatService $service): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $branch = $request->filled('branch_id') ? $branches->firstWhere('id', $request->integer('branch_id')) : ($branches->count() === 1 ? $branches->first() : null);
        abort_if($request->filled('branch_id') && ! $branch, 403);

        return view('imprest.form', [
            'float' => null, 'branches' => $branches, 'branch' => $branch,
            'ledgers' => $branch ? $service->candidateLedgers($branch->id) : collect(),
            'sources' => $branch ? $service->candidateSources($branch->id) : collect(),
        ]);
    }

    public function store(SaveImprestFloatRequest $request, ImprestFloatService $service): RedirectResponse
    {
        $float = $service->create($request->validated(), $request->user());

        return redirect()->route('imprest.show', $float)->with('status', 'Imprest float set up.');
    }

    public function show(Request $request, ImprestFloat $float, BranchAccess $access, ImprestLedgerService $ledger): View
    {
        $this->authorizeBranch($request, $float, $access);
        $float->load(['account', 'sourceAccount.account', 'creator']);
        $summary = $ledger->summary($float);
        $replenishments = ImprestReplenishment::query()->with(['journalEntry', 'creator'])->where('imprest_float_id', $float->id)->latest('id')->paginate(10)->withQueryString();

        return view('imprest.show', compact('float', 'summary', 'replenishments'));
    }

    public function edit(Request $request, ImprestFloat $float, BranchAccess $access, ImprestFloatService $service): View
    {
        $this->authorizeBranch($request, $float, $access);
        $float->load('account');

        return view('imprest.form', [
            'float' => $float, 'branches' => collect([$float->branch]), 'branch' => $float->branch,
            'ledgers' => collect([$float->account]), 'sources' => $service->candidateSources($float->branch_id),
        ]);
    }

    public function update(SaveImprestFloatRequest $request, ImprestFloat $float, ImprestFloatService $service): RedirectResponse
    {
        $service->update($float, $request->validated(), $request->user());

        return redirect()->route('imprest.show', $float)->with('status', 'Imprest float updated.');
    }

    private function authorizeBranch(Request $request, ImprestFloat $float, BranchAccess $access): void
    {
        $float->loadMissing('branch');
        abort_unless($access->canAccess($request->user(), $float->branch), 403);
    }
}
