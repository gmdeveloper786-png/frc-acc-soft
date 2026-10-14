<?php

namespace App\Http\Controllers\Budgets;

use App\Enums\BudgetStatus;
use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveBudgetRequest;
use App\Models\Budget;
use App\Models\FiscalYear;
use App\Models\FrcService;
use App\Services\AccountingDecimal;
use App\Services\BranchAccess;
use App\Services\Budgets\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BudgetController extends Controller
{
    public function index(Request $request, BranchAccess $access): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->get();
        $budgets = Budget::query()->with(['fiscalYear', 'branch', 'service', 'creator'])->whereIn('branch_id', $branches->pluck('id'))
            ->when($request->filled('branch_id') && $branches->contains('id', $request->integer('branch_id')), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('fiscal_year_id'), fn ($query) => $query->where('fiscal_year_id', $request->integer('fiscal_year_id')))
            ->when($request->filled('service_id'), fn ($query) => $request->input('service_id') === 'all' ? $query->whereNull('service_id') : $query->where('service_id', $request->integer('service_id')))
            ->when(BudgetStatus::tryFrom($request->string('status')->toString()), fn ($query, BudgetStatus $status) => $query->where('status', $status->value))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderByDesc('fiscal_year_id')->orderBy('branch_id')->orderBy('name')->orderByDesc('version')->paginate(20)->withQueryString();

        return view('budgets.index', [
            'budgets' => $budgets, 'branches' => $branches,
            'years' => FiscalYear::query()->orderByDesc('start_date')->get(),
            'services' => FrcService::query()->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request, BranchAccess $access, BudgetService $service): View
    {
        $years = FiscalYear::query()->where('status', '!=', FiscalYearStatus::Closed->value)->orderByDesc('start_date')->get();
        $selected = old('fiscal_year_id', $request->query('fiscal_year_id'));
        $year = $selected ? $years->firstWhere('id', (int) $selected) : ($years->firstWhere('is_current', true) ?? $years->first());

        return view('budgets.create', [...$this->formData($request, $access, $service, $year, null), 'years' => $years]);
    }

    public function store(SaveBudgetRequest $request, BudgetService $service): RedirectResponse
    {
        $budget = $service->create($request->budgetData(), $request->user());

        return redirect()->route('budgets.show', $budget)->with('status', "Budget {$budget->name} saved as draft. No accounting entry was created.");
    }

    public function show(Request $request, Budget $budget, BranchAccess $access, BudgetService $service): View
    {
        $this->guardBranch($request, $budget, $access);
        $budget->load(['fiscalYear', 'branch', 'service', 'creator', 'submitter', 'approver', 'lines.account', 'approvals.actor']);

        return view('budgets.show', [
            'budget' => $budget,
            'periods' => $budget->fiscalYear->periods()->get(),
            'grid' => $budget->lines->sortBy(fn ($line): string => $line->account->code)->groupBy('account_id'),
            'versions' => $budget->versions()->with(['creator', 'approver'])->get(),
            'canApprove' => $service->canApprove($budget, $request->user()),
        ]);
    }

    public function edit(Request $request, Budget $budget, BranchAccess $access, BudgetService $service): View
    {
        $this->guardBranch($request, $budget, $access);
        abort_unless($budget->status->isEditable(), 403, 'Only a draft or returned budget can be edited.');

        return view('budgets.edit', [...$this->formData($request, $access, $service, $budget->fiscalYear, $budget), 'budget' => $budget->load(['branch', 'service'])]);
    }

    public function update(SaveBudgetRequest $request, Budget $budget, BudgetService $service): RedirectResponse
    {
        $service->update($budget, $request->budgetData(), $request->user());

        return redirect()->route('budgets.show', $budget)->with('status', 'Budget draft updated.');
    }

    /**
     * Server-side "spread annual amount" helper. The budget form calls it for
     * one ledger row and writes the returned period amounts into its grid, so
     * the exact rounding rule lives in one place. Nothing is saved.
     */
    public function spread(Request $request, BudgetService $service): JsonResponse
    {
        $data = $request->validate([
            'fiscal_year_id' => ['required', 'integer', 'exists:fiscal_years,id'],
            'annual_amount' => ['required', 'decimal:0,4', 'gte:0'],
        ], [], ['annual_amount' => 'annual amount']);

        $amounts = $service->spreadAnnual(FiscalYear::query()->findOrFail($data['fiscal_year_id']), $data['annual_amount']);

        return response()->json(['amounts' => collect($amounts)->map(fn (string $amount, int $periodId): array => ['period_id' => $periodId, 'amount' => $amount])->values()->all()]);
    }

    public function submit(Request $request, Budget $budget, BudgetService $service): RedirectResponse
    {
        $service->submit($budget, $request->user());

        return back()->with('status', 'Budget submitted for approval.');
    }

    public function approve(Request $request, Budget $budget, BudgetService $service): RedirectResponse
    {
        $data = $request->validate(['comments' => ['nullable', 'string', 'max:2000']]);
        $service->approve($budget, $request->user(), $data['comments'] ?? null);

        return back()->with('status', 'Budget approved. No accounting entry was created.');
    }

    public function reject(Request $request, Budget $budget, BudgetService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $service->reject($budget, $data['reason'], $request->user());

        return back()->with('status', 'Budget returned for correction.');
    }

    public function revise(Request $request, Budget $budget, BudgetService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $revision = $service->revise($budget, $data['reason'], $request->user());

        return redirect()->route('budgets.edit', $revision)->with('status', "Version {$revision->version} created as a draft. The approved version stays in force until this revision is approved.");
    }

    private function guardBranch(Request $request, Budget $budget, BranchAccess $access): void
    {
        abort_unless($access->canAccess($request->user(), $budget->branch), 403);
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, BranchAccess $access, BudgetService $service, ?FiscalYear $year, ?Budget $budget): array
    {
        return [
            'year' => $year,
            'periods' => $year ? $year->periods()->get() : collect(),
            'branches' => $access->scope($request->user())->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
            'services' => FrcService::query()->whereIn('status', ['publish', 'active'])->orderBy('name')->get(),
            'ledgers' => $service->budgetableAccounts()->orderBy('code')->get(['id', 'code', 'name', 'account_type']),
            'gridLines' => $this->gridLines($budget),
        ];
    }

    /**
     * The lines the grid starts with: the document of a submission that failed
     * validation when there is one, otherwise the stored lines of the budget.
     *
     * @return list<array{account_id: int, period_id: int, amount: string}>
     */
    private function gridLines(?Budget $budget): array
    {
        $previous = old('lines_json');
        $decoded = is_string($previous) ? json_decode($previous, true, 3) : null;
        if (is_array($decoded) && array_is_list($decoded)) {
            return collect($decoded)->filter(fn (mixed $entry): bool => is_array($entry))->map(fn (array $entry): array => [
                'account_id' => is_scalar($entry['account_id'] ?? null) ? (int) $entry['account_id'] : 0,
                'period_id' => is_scalar($entry['period_id'] ?? null) ? (int) $entry['period_id'] : 0,
                'amount' => is_scalar($entry['amount'] ?? null) ? (string) $entry['amount'] : '',
            ])->values()->all();
        }
        if (! $budget) {
            return [];
        }

        return DB::table('budget_lines')->where('budget_id', $budget->id)->orderBy('id')->get(['account_id', 'accounting_period_id', 'amount'])
            ->map(fn (object $line): array => ['account_id' => (int) $line->account_id, 'period_id' => (int) $line->accounting_period_id, 'amount' => AccountingDecimal::string(AccountingDecimal::of($line->amount))])->all();
    }
}
