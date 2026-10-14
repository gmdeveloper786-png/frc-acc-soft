<?php

namespace App\Services\Frc\FinancialSync;

use App\Models\FrcEnrollment;
use App\Models\FrcPayment;
use App\Models\FrcSyncException;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * Branch scoping of the FRC mirror. Users with `branches.view_all` see every
 * record, including those whose branch is not resolved locally; everyone
 * else sees only records of their assigned branches.
 */
class FrcMirrorAccess
{
    public function __construct(private BranchAccess $branches) {}

    public function viewsAll(User $user): bool
    {
        return $user->can('branches.view_all');
    }

    /** @return list<int> */
    public function branchIds(User $user): array
    {
        return $this->branches->scope($user)->pluck('branches.id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return Builder<FrcPayment> */
    public function payments(User $user): Builder
    {
        $query = FrcPayment::query();

        return $this->viewsAll($user) ? $query : $query->whereIn('frc_payments.branch_id', $this->branchIds($user));
    }

    /** @return Builder<FrcEnrollment> */
    public function enrollments(User $user): Builder
    {
        $query = FrcEnrollment::query();

        return $this->viewsAll($user) ? $query : $query->whereIn('frc_enrollments.branch_id', $this->branchIds($user));
    }

    /**
     * Exceptions of records the user can see. Exceptions without a mirrored
     * record (e.g. an invalid record) are visible only to all-branch users.
     *
     * @return Builder<FrcSyncException>
     */
    public function exceptions(User $user): Builder
    {
        $query = FrcSyncException::query();
        if ($this->viewsAll($user)) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($user): void {
            $builder->where(fn (Builder $payments) => $payments->where('entity', FrcFinancialApi::Payments)
                ->whereIn('external_id', $this->payments($user)->select('frc_payment_id')))
                ->orWhere(fn (Builder $enrollments) => $enrollments->where('entity', FrcFinancialApi::Enrollments)
                    ->whereIn('external_id', $this->enrollments($user)->select('frc_enrollment_id')));
        });
    }

    public function canViewPayment(User $user, FrcPayment $payment): bool
    {
        return $this->viewsAll($user) || ($payment->branch_id !== null && in_array((int) $payment->branch_id, $this->branchIds($user), true));
    }

    public function canViewException(User $user, FrcSyncException $exception): bool
    {
        return $this->exceptions($user)->whereKey($exception->getKey())->exists();
    }
}
