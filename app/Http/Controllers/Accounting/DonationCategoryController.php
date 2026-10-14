<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\DonationCategory;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DonationCategoryController extends Controller
{
    public function index(): View
    {
        return view('accounting.donation-categories.index', [
            'categories' => DonationCategory::query()->with('incomeAccount')->orderBy('name')->get(),
            'accounts' => Account::query()->where('level', 'ledger')->where('is_postable', true)->where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function update(Request $request, DonationCategory $donationCategory, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['income_account_id' => ['nullable', 'exists:accounts,id'], 'is_active' => ['required', 'boolean']]);
        if (filled($data['income_account_id'] ?? null) && ! Account::query()->whereKey($data['income_account_id'])->where('account_type', 'income')->where('level', 'ledger')->where('is_postable', true)->where('is_active', true)->exists()) {
            abort(422, 'Active postable ledger account required.');
        }
        $donationCategory->update($data);
        $audit->log('donation_categories.updated', $donationCategory, $data);

        return back()->with('status', 'Donation category mapping updated.');
    }
}
