<?php

namespace App\Http\Controllers\FeePosting;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeePosting\PostFeeRecordsRequest;
use App\Models\FrcEnrollment;
use App\Models\FrcService;
use App\Services\BranchAccess;
use App\Services\FeePosting\FeePostingPreview;
use App\Services\FeePosting\FeePostingService;
use App\Services\FeePosting\FeePostingSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Accrual basis only: recognise enrollment fees as receivable and income. */
class FeeEnrollmentRecognitionController extends Controller
{
    public function index(Request $request, FeePostingPreview $preview, FeePostingSettings $settings, BranchAccess $access): View
    {
        $filters = $request->validate(['branch_id' => ['nullable', 'integer'], 'service_id' => ['nullable', 'integer']]);
        $isAccrual = $settings->isAccrual();

        return view('fee-posting.enrollments', [
            ...($isAccrual ? $preview->enrollments($request->user(), $filters) : ['records' => null, 'plans' => [], 'totals' => null]),
            'isAccrual' => $isAccrual,
            'feeSettings' => $settings->summary(),
            'branches' => $access->scope($request->user())->orderBy('name')->get(),
            'services' => FrcService::query()->orderBy('name')->get(),
            'canPost' => $request->user()->can('frc_integration.post') && $request->user()->can('journals.post'),
        ]);
    }

    public function post(PostFeeRecordsRequest $request, FeePostingService $service, BranchAccess $access): RedirectResponse
    {
        $ids = array_map('intval', $request->validated('ids'));
        foreach (FrcEnrollment::query()->with('branch')->whereKey($ids)->get() as $enrollment) {
            abort_unless($enrollment->branch ? $access->canAccess($request->user(), $enrollment->branch) : $request->user()->can('branches.view_all'), 403);
        }
        $results = $service->postBatch('enrollment', $ids, $request->user());
        $posted = count(array_filter($results, fn (array $result): bool => $result['status'] === 'posted'));

        return back()->with('status', "{$posted} of ".count($results).' selected enrollment(s) recognised.')->with('posting_results', $results);
    }
}
