<?php

namespace App\Http\Controllers\FeePosting;

use App\Http\Controllers\Controller;
use App\Http\Requests\FeePosting\SaveFeeSettlementMappingRequest;
use App\Models\Branch;
use App\Models\FeeSettlementMapping;
use App\Services\BranchAccess;
use App\Services\FeePosting\FeePostingSettings;
use App\Services\FeePosting\FeeSettlementMappingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeeSettlementMappingController extends Controller
{
    public function index(Request $request, BranchAccess $access, FeeSettlementMappingService $service, FeePostingSettings $settings): View
    {
        $branches = $access->scope($request->user())->orderBy('name')->paginate(10)->withQueryString();
        $mappings = FeeSettlementMapping::query()->with('financialAccount.account')->whereIn('branch_id', $branches->pluck('id'))->get()
            ->groupBy('branch_id')->map(fn ($rows) => $rows->keyBy('payment_method'));
        $rows = [];
        foreach ($branches as $branch) {
            $candidates = $service->candidateAccounts($branch->id);
            foreach (FeeSettlementMappingService::METHODS as $method => $label) {
                $mapping = $mappings->get($branch->id)?->get($method);
                $required = FeeSettlementMappingService::requiredType($method);
                $rows[$branch->id][$method] = [
                    'label' => $label,
                    'required_type' => $required,
                    'mapping' => $mapping,
                    'issue' => $mapping ? $service->accountIssue($mapping->financialAccount, $branch->id, $method) : null,
                    'options' => $candidates->where('type', $required)->values(),
                ];
            }
        }

        return view('fee-posting.settlement-mappings', [
            'branches' => $branches,
            'rows' => $rows,
            'feeSettings' => $settings->summary(),
            'canManage' => $request->user()->can('fee_settlement.manage'),
        ]);
    }

    public function update(SaveFeeSettlementMappingRequest $request, FeeSettlementMappingService $service): RedirectResponse
    {
        $data = $request->validated();
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $accountId = isset($data['financial_account_id']) ? (int) $data['financial_account_id'] : null;
        $mapping = $service->save($branch, $data['payment_method'], $accountId, $request->user());

        return back()->with('status', $mapping ? 'Settlement mapping saved.' : 'Settlement mapping cleared.');
    }
}
