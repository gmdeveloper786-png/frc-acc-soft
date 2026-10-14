<?php

namespace App\Http\Controllers\FeePosting;

use App\Enums\AccountingPeriodStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\FeePosting\ReverseFeePostingRequest;
use App\Models\AccountingPeriod;
use App\Models\FrcFeePosting;
use App\Services\BranchAccess;
use App\Services\FeePosting\FeePostingPreview;
use App\Services\FeePosting\FeePostingService;
use App\Services\FeePosting\FeePostingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Posted fee receipts and recognitions, with their journals and reversal. */
class FeePostingController extends Controller
{
    public function index(Request $request, FeePostingPreview $preview, FeePostingSettings $settings, BranchAccess $access): View
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'kind' => ['nullable', Rule::in(['payment', 'enrollment'])],
            'status' => ['nullable', Rule::in(['posted', 'reversed'])],
        ]);

        return view('fee-posting.postings', [
            'postings' => $preview->postings($request->user(), $filters),
            'feeSettings' => $settings->summary(),
            'branches' => $access->scope($request->user())->orderBy('name')->get(),
            'periods' => AccountingPeriod::query()->with('fiscalYear')->where('status', AccountingPeriodStatus::Open->value)->orderBy('start_date')->get(),
            'canReverse' => $request->user()->can('frc_integration.reverse') && $request->user()->can('journals.reverse'),
        ]);
    }

    public function reverse(ReverseFeePostingRequest $request, FrcFeePosting $posting, FeePostingService $service): RedirectResponse
    {
        $data = $request->validated();
        $period = AccountingPeriod::query()->findOrFail($data['accounting_period_id']);
        $service->reverse($posting, $period, $data['transaction_date'], $data['reason'], $request->user());

        return back()->with('status', 'Fee posting reversed. The original journal is kept and an opposite journal was posted.');
    }
}
