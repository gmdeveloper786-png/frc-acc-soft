<?php

namespace App\Http\Controllers\AccountingSetup;

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
    public function create(): View
    {
        $parents = Account::query()->where('is_postable', false)->where('is_active', true)->orderBy('code')->get();

        return view('accounting-setup.accounts.create', compact('parents'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAccountRequest $request, AccountService $service): RedirectResponse
    {
        $account = $service->create($request->validated(), $request->user());

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
        $parents = Account::query()->where('is_postable', false)->where('is_active', true)->whereKeyNot($account->id)->orderBy('code')->get();

        return view('accounting-setup.accounts.edit', compact('account', 'parents'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAccountRequest $request, Account $account, AccountService $service): RedirectResponse
    {
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
