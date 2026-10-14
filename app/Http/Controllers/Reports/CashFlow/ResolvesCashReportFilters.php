<?php

namespace App\Http\Controllers\Reports\CashFlow;

use App\Models\Branch;
use App\Models\FiscalYear;
use App\Services\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Shared period and branch filters of the cash statements. A branch outside
 * the user's scope is refused, never silently widened.
 */
trait ResolvesCashReportFilters
{
    /**
     * @return array{branches: Collection<int, Branch>, years: Collection<int, FiscalYear>, canViewAll: bool, year: ?FiscalYear, from: ?string, to: ?string, branch: ?Branch, allBranches: bool, ready: bool, meta: array<string, string>}
     */
    protected function cashReportFilters(Request $request): array
    {
        $validated = $request->validate([
            'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
            'from' => ['nullable', 'date', 'required_with:to'],
            'to' => ['nullable', 'date', 'required_with:from', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'string', 'regex:/^(all|\d{1,18})$/'],
        ]);
        $user = $request->user();
        $canViewAll = $user->can('branches.view_all');
        $branches = app(BranchAccess::class)->scope($user)->orderBy('name')->get();
        $years = FiscalYear::query()->orderByDesc('start_date')->get();

        $requestedBranch = $validated['branch_id'] ?? null;
        $branch = null;
        $allBranches = false;
        if ($requestedBranch === 'all') {
            abort_unless($canViewAll, 403);
            $allBranches = true;
        } elseif (filled($requestedBranch)) {
            if (! Branch::query()->whereKey((int) $requestedBranch)->exists()) {
                throw ValidationException::withMessages(['branch_id' => 'The selected branch does not exist.']);
            }
            $branch = $branches->firstWhere('id', (int) $requestedBranch);
            abort_unless($branch !== null, 403);
        } elseif ($canViewAll) {
            $allBranches = true;
        } else {
            $branch = $branches->first();
        }

        $year = filled($validated['fiscal_year_id'] ?? null)
            ? $years->firstWhere('id', (int) $validated['fiscal_year_id'])
            : (filled($validated['from'] ?? null) ? null : $years->firstWhere('is_current', true));
        $from = filled($validated['from'] ?? null) ? Carbon::parse($validated['from'])->toDateString() : $year?->start_date->toDateString();
        $to = filled($validated['to'] ?? null) ? Carbon::parse($validated['to'])->toDateString() : $year?->end_date->toDateString();
        $ready = $from !== null && $to !== null && ($allBranches || $branch !== null);

        return [
            'branches' => $branches, 'years' => $years, 'canViewAll' => $canViewAll, 'year' => $year, 'from' => $from, 'to' => $to,
            'branch' => $branch, 'allBranches' => $allBranches, 'ready' => $ready,
            'meta' => $ready ? [
                'Period' => Carbon::parse($from)->format('d M Y').' to '.Carbon::parse($to)->format('d M Y'),
                'Branch' => $allBranches ? 'All branches (consolidated)' : $branch->name,
            ] : [],
        ];
    }
}
