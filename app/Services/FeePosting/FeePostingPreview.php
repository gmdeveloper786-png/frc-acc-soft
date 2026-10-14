<?php

namespace App\Services\FeePosting;

use App\Models\FrcEnrollment;
use App\Models\FrcFeePosting;
use App\Models\FrcPayment;
use App\Models\User;
use App\Services\AccountingDecimal;
use App\Services\BranchAccess;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only previews: which records would post, with their exact journal
 * lines, and which are skipped and why. Nothing here writes to the database.
 */
class FeePostingPreview
{
    public function __construct(private FeePostingEligibility $eligibility, private BranchAccess $branchAccess) {}

    /**
     * @param  array{branch_id?: ?int, service_id?: ?int, date_from?: ?string, date_to?: ?string, payment_method?: ?string}  $filters
     * @return array{records: LengthAwarePaginator, plans: array<int, FeePostingPlan>, totals: array{eligible_count: int, eligible_amount: string, skipped_count: int, skipped_amount: string, debit: string, credit: string}}
     */
    public function payments(User $actor, array $filters): array
    {
        $query = FrcPayment::query()->with(['enrollment', 'branch', 'service'])
            ->where('posting_status', '!=', 'posted')
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $id) => $q->where('branch_id', $id))
            ->when($filters['service_id'] ?? null, fn (Builder $q, $id) => $q->where('service_id', $id))
            ->when($filters['payment_method'] ?? null, fn (Builder $q, $method) => $q->where('payment_method', $method))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('payment_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $date) => $q->whereDate('payment_date', '<=', $date))
            ->orderBy('payment_date')->orderBy('id');
        $records = $this->scope($query, $actor)->paginate(50)->withQueryString();
        $plans = [];
        foreach ($records as $payment) {
            $plans[$payment->id] = $this->eligibility->forPayment($payment, $actor);
        }

        return ['records' => $records, 'plans' => $plans, 'totals' => $this->totals($plans, $records->getCollection()->pluck('amount', 'id')->all())];
    }

    /**
     * @param  array{branch_id?: ?int, service_id?: ?int}  $filters
     * @return array{records: LengthAwarePaginator, plans: array<int, FeePostingPlan>, totals: array{eligible_count: int, eligible_amount: string, skipped_count: int, skipped_amount: string, debit: string, credit: string}}
     */
    public function enrollments(User $actor, array $filters): array
    {
        $query = FrcEnrollment::query()->with(['branch', 'service'])
            ->whereIn('source_status', FeePostingEligibility::APPROVED_ENROLLMENT_STATUSES)
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $id) => $q->where('branch_id', $id))
            ->when($filters['service_id'] ?? null, fn (Builder $q, $id) => $q->where('service_id', $id))
            ->orderBy('id');
        $records = $this->scope($query, $actor)->paginate(50)->withQueryString();
        $plans = [];
        foreach ($records as $enrollment) {
            $plans[$enrollment->id] = $this->eligibility->forEnrollment($enrollment, $actor);
        }

        return ['records' => $records, 'plans' => $plans, 'totals' => $this->totals($plans, $records->getCollection()->pluck('final_total', 'id')->all())];
    }

    /**
     * @param  array{branch_id?: ?int, kind?: ?string, status?: ?string}  $filters
     */
    public function postings(User $actor, array $filters): LengthAwarePaginator
    {
        $branchIds = $this->branchAccess->scope($actor)->pluck('id');

        return FrcFeePosting::query()->with(['payment.service', 'enrollment.service', 'journalEntry.branch', 'reversalJournalEntry'])
            ->whereHas('journalEntry', fn (Builder $q) => $q->whereIn('branch_id', $branchIds)->when($filters['branch_id'] ?? null, fn (Builder $q, $id) => $q->where('branch_id', $id)))
            ->when($filters['kind'] ?? null, fn (Builder $q, $kind) => $q->where('kind', $kind))
            ->when($filters['status'] ?? null, fn (Builder $q, $status) => $q->where('status', $status))
            ->latest('id')->paginate(50)->withQueryString();
    }

    /** Restrict to the actor's branches; unmapped records are visible only to all-branch users. */
    private function scope(Builder $query, User $actor): Builder
    {
        if ($actor->can('branches.view_all')) {
            return $query;
        }

        return $query->whereIn('branch_id', $this->branchAccess->scope($actor)->pluck('id'));
    }

    /**
     * @param  array<int, FeePostingPlan>  $plans
     * @param  array<int, mixed>  $amounts
     * @return array{eligible_count: int, eligible_amount: string, skipped_count: int, skipped_amount: string, debit: string, credit: string}
     */
    private function totals(array $plans, array $amounts): array
    {
        $zero = BigDecimal::zero()->toScale(4);
        $sums = ['eligible' => $zero, 'skipped' => $zero, 'debit' => $zero, 'credit' => $zero];
        $counts = ['eligible' => 0, 'skipped' => 0];
        foreach ($plans as $id => $plan) {
            $bucket = $plan->eligible() ? 'eligible' : 'skipped';
            $counts[$bucket]++;
            $sums[$bucket] = $sums[$bucket]->plus(AccountingDecimal::of($plan->eligible() ? $plan->amount : ($amounts[$id] ?? 0)));
            foreach ($plan->lines as $line) {
                $sums['debit'] = $sums['debit']->plus(AccountingDecimal::of($line['debit']));
                $sums['credit'] = $sums['credit']->plus(AccountingDecimal::of($line['credit']));
            }
        }

        return [
            'eligible_count' => $counts['eligible'], 'eligible_amount' => AccountingDecimal::string($sums['eligible']),
            'skipped_count' => $counts['skipped'], 'skipped_amount' => AccountingDecimal::string($sums['skipped']),
            'debit' => AccountingDecimal::string($sums['debit']), 'credit' => AccountingDecimal::string($sums['credit']),
        ];
    }
}
