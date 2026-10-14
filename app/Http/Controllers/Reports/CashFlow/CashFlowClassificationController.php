<?php

namespace App\Http\Controllers\Reports\CashFlow;

use App\Enums\AccountType;
use App\Enums\CashFlowActivity;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CashFlowClassification;
use App\Services\Reports\CashFlow\CashFlowClassificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CashFlowClassificationController extends Controller
{
    public function index(Request $request, CashFlowClassificationService $classifications): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'account_type' => ['nullable', Rule::enum(AccountType::class)],
            'activity' => ['nullable', Rule::in(['unclassified', ...array_column(CashFlowActivity::cases(), 'value')])],
        ]);
        $search = $filters['search'] ?? null;
        $activity = $filters['activity'] ?? null;
        $classified = fn () => CashFlowClassification::query()->select('account_id');

        $ledgers = $classifications->classifiable()
            ->when(filled($search), function ($query) use ($search): void {
                $term = '%'.addcslashes((string) $search, '\\%_').'%';
                $query->where(fn ($inner) => $inner->where('code', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->when(filled($filters['account_type'] ?? null), fn ($query) => $query->where('account_type', $filters['account_type']))
            ->when($activity === 'unclassified', fn ($query) => $query->whereNotIn('id', $classified()))
            ->when(filled($activity) && $activity !== 'unclassified', fn ($query) => $query->whereIn('id', $classified()->where('activity', $activity)))
            ->orderBy('sort_order')->orderBy('code')->paginate(20)->withQueryString();

        return view('reports.cash-flow.classification', [
            'ledgers' => $ledgers,
            'classifications' => CashFlowClassification::query()->with('updater:id,name')->whereIn('account_id', $ledgers->pluck('id'))->get()->keyBy('account_id'),
            'activities' => CashFlowActivity::cases(),
            'accountTypes' => AccountType::cases(),
            'chartIsEmpty' => ! Account::query()->exists(),
            'unclassifiedCount' => $classifications->classifiable()->whereNotIn('id', $classified())->count(),
        ]);
    }

    public function update(Request $request, Account $account, CashFlowClassificationService $classifications): RedirectResponse
    {
        $validated = $request->validate(['activity' => ['nullable', Rule::enum(CashFlowActivity::class)]]);
        $activity = filled($validated['activity'] ?? null) ? CashFlowActivity::from($validated['activity']) : null;
        $classifications->set($account, $activity, $request->user());

        return back()->with('status', $activity
            ? "{$account->code} {$account->name} is classified as {$activity->label()}."
            : "The classification of {$account->code} {$account->name} was cleared.");
    }
}
