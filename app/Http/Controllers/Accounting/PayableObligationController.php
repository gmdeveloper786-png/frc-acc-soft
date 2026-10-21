<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\OperationalTransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\PayableObligation;
use App\Services\AccountingDecimal;
use App\Services\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PayableObligationController extends Controller
{
    public function createSettlement(Request $request, PayableObligation $payableObligation, BranchAccess $access): View
    {
        abort_unless($request->user()->can('payments.create') && $access->canAccess($request->user(), $payableObligation->branch), 403);
        $payableObligation->load(['branch', 'payableAccount', 'vendor', 'sourceExpense']);
        abort_unless($payableObligation->sourceExpense?->status === OperationalTransactionStatus::Posted, 404);
        $outstanding = $payableObligation->outstandingAmount();
        abort_unless(AccountingDecimal::of($outstanding)->isPositive(), 422, 'This payable is already fully settled.');

        return view('accounting.payable-obligations.settle', [
            'obligation' => $payableObligation,
            'outstanding' => $outstanding,
            'financialAccounts' => FinancialAccount::query()->where('branch_id', $payableObligation->branch_id)->where('is_active', true)->orderBy('name')->get(),
            'settlementRequestToken' => (string) Str::uuid(),
        ]);
    }
}
