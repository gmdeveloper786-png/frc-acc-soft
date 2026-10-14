<?php

namespace App\Http\Controllers\FeePosting;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeePosting\PostFeeRecordsRequest;
use App\Models\FrcPayment;
use App\Models\FrcService;
use App\Services\BranchAccess;
use App\Services\FeePosting\FeePostingPreview;
use App\Services\FeePosting\FeePostingService;
use App\Services\FeePosting\FeePostingSettings;
use App\Services\FeePosting\FeeSettlementMappingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FeePaymentPostingController extends Controller
{
    /** Preview only: shows what would be posted and what is skipped. Writes nothing. */
    public function index(Request $request, FeePostingPreview $preview, FeePostingSettings $settings, BranchAccess $access): View
    {
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', 'string', Rule::in(array_keys(FeeSettlementMappingService::METHODS))],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        return view('fee-posting.payments', [
            ...$preview->payments($request->user(), $filters),
            'feeSettings' => $settings->summary(),
            'branches' => $access->scope($request->user())->orderBy('name')->get(),
            'services' => FrcService::query()->orderBy('name')->get(),
            'methods' => FeeSettlementMappingService::METHODS,
            'canPost' => $request->user()->can('frc_integration.post') && $request->user()->can('journals.post'),
        ]);
    }

    public function post(PostFeeRecordsRequest $request, FeePostingService $service, BranchAccess $access): RedirectResponse
    {
        $ids = array_map('intval', $request->validated('ids'));
        foreach (FrcPayment::query()->with('branch')->whereKey($ids)->get() as $payment) {
            abort_unless($payment->branch ? $access->canAccess($request->user(), $payment->branch) : $request->user()->can('branches.view_all'), 403);
        }
        $results = $service->postBatch('payment', $ids, $request->user());
        $posted = count(array_filter($results, fn (array $result): bool => $result['status'] === 'posted'));

        return back()->with('status', "{$posted} of ".count($results).' selected payment(s) posted.')->with('posting_results', $results);
    }
}
