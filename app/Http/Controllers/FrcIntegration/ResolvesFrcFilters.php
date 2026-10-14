<?php

namespace App\Http\Controllers\FrcIntegration;

use App\Models\Branch;
use App\Models\FrcService;
use App\Services\BranchAccess;
use App\Services\Frc\FinancialSync\FrcPaymentListService;
use App\Services\Frc\FinancialSync\FrcRecordNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Shared, validated filters of the FRC mirror screens. A branch outside the
 * user's scope is refused, never silently widened.
 */
trait ResolvesFrcFilters
{
    /**
     * @return array{filters: array<string, mixed>, branches: Collection<int, Branch>, services: Collection<int, FrcService>, meta: array<string, string>}
     */
    protected function frcFilters(Request $request, bool $paymentFilters = true): array
    {
        $rules = [
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'service_id' => ['nullable', 'integer', 'exists:frc_services,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
        if ($paymentFilters) {
            $rules += [
                'method' => ['nullable', Rule::in(FrcRecordNormalizer::PAYMENT_METHODS)],
                'status' => ['nullable', Rule::in(FrcRecordNormalizer::PAYMENT_STATUSES)],
                'posting_status' => ['nullable', Rule::in(FrcPaymentListService::POSTING_STATUSES)],
                'search' => ['nullable', 'string', 'max:60'],
            ];
        }
        $filters = $request->validate($rules);
        $branches = app(BranchAccess::class)->scope($request->user())->orderBy('name')->get();
        $services = FrcService::query()->orderBy('name')->get(['id', 'name']);

        $branch = null;
        if (filled($filters['branch_id'] ?? null)) {
            $branch = $branches->firstWhere('id', (int) $filters['branch_id']);
            abort_unless($branch !== null, 403);
        }
        $service = filled($filters['service_id'] ?? null) ? $services->firstWhere('id', (int) $filters['service_id']) : null;
        foreach (['from', 'to'] as $date) {
            if (filled($filters[$date] ?? null)) {
                $filters[$date] = Carbon::parse($filters[$date])->toDateString();
            }
        }

        $meta = [
            'Payment dates' => (filled($filters['from'] ?? null) ? Carbon::parse($filters['from'])->format('d M Y') : 'Beginning').' to '.(filled($filters['to'] ?? null) ? Carbon::parse($filters['to'])->format('d M Y') : 'today'),
            'Branch' => $branch?->name ?? ($request->user()->can('branches.view_all') ? 'All branches (including unmapped)' : 'All my branches'),
            'Service' => $service?->name ?? 'All services',
        ];

        return ['filters' => $filters, 'branches' => $branches, 'services' => $services, 'meta' => $meta];
    }
}
