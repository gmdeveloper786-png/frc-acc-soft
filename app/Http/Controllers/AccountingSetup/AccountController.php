<?php

namespace App\Http\Controllers\AccountingSetup;

use App\Enums\AccountLevel;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use App\Services\AccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $accounts = Account::query()->with('parent')
            ->when($request->string('search')->trim()->isNotEmpty(), fn ($query) => $query->where(fn ($inner) => $inner->where('code', 'like', '%'.$request->string('search')->trim().'%')->orWhere('name', 'like', '%'.$request->string('search')->trim().'%')))
            ->when($request->filled('type'), fn ($query) => $query->where('account_type', $request->string('type')))
            ->when($request->filled('status'), fn ($query) => $query->where('is_active', $request->string('status') === 'active'))
            ->orderBy('code')->paginate(50)->withQueryString();

        return view('accounting-setup.accounts.index', compact('accounts'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request, AccountService $service): View
    {
        $classes = Account::query()->where('level', AccountLevel::ClassLevel->value)->orderBy('code')->get();
        // Active classes, groups and sub groups can take children; each carries its non-binding next-code preview.
        $parents = Account::query()->where('level', '!=', AccountLevel::Ledger->value)->where('is_active', true)->orderBy('code')->get()
            ->each(function (Account $parent) use ($service): void {
                $parent->setAttribute('next_code_preview', $service->previewNextCode($parent));
                // Under a sub group whose ledgers are all contra (e.g. accumulated depreciation), suggest contra.
                $ledgers = $parent->level === AccountLevel::SubGroup ? $parent->children()->get(['normal_balance', 'account_type']) : collect();
                $parent->setAttribute('contra_children', $ledgers->isNotEmpty() && $ledgers->every(fn (Account $ledger): bool => $ledger->normal_balance !== (in_array($ledger->account_type, [AccountType::Asset, AccountType::Expense], true) ? NormalBalance::Debit : NormalBalance::Credit)));
            });
        // "Add child account" opens the form on a chosen parent.
        $selectedParent = $parents->firstWhere('id', $request->integer('parent'));

        return view('accounting-setup.accounts.create', compact('classes', 'parents', 'selectedParent'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAccountRequest $request, AccountService $service): RedirectResponse
    {
        $account = $service->createChild($request->validated(), $request->user());

        return redirect()->route('accounts.show', $account)->with('status', 'Account created.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Account $account): View
    {
        $account->load(['parent', 'children', 'creator']);

        return view('accounting-setup.accounts.show', compact('account'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Account $account): View
    {
        $account->load('parent');

        return view('accounting-setup.accounts.edit', compact('account'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAccountRequest $request, Account $account, AccountService $service): RedirectResponse
    {
        // Switching an account off through Edit needs the same permission as the Deactivate action.
        abort_if($account->is_active && ! $request->boolean('is_active') && ! $request->user()->can('accounts.deactivate'), 403);
        $service->update($account, $request->validated());

        return redirect()->route('accounts.show', $account)->with('status', 'Account updated.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function activate(Account $account, AccountService $service): RedirectResponse
    {
        $service->setActive($account, true);

        return back()->with('status', 'Account activated.');
    }

    public function deactivate(Account $account, AccountService $service): RedirectResponse
    {
        $service->setActive($account, false);

        return back()->with('status', 'Account deactivated.');
    }
}
