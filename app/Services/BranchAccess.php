<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class BranchAccess
{
    /** @return Builder<Branch> */
    public function scope(User $user): Builder
    {
        $query = Branch::query();

        return $user->can('branches.view_all')
            ? $query
            : $query->whereHas('users', fn (Builder $builder) => $builder->whereKey($user->getKey()));
    }

    public function canAccess(User $user, Branch $branch): bool
    {
        return $user->can('branches.view_all') || $user->branches()->whereKey($branch->getKey())->exists();
    }

    /**
     * Users the actor may see and manage: everyone with branches.view_all,
     * otherwise themselves and users who share at least one of their branches.
     *
     * @return Builder<User>
     */
    public function userScope(User $actor): Builder
    {
        $query = User::query();
        if ($actor->can('branches.view_all')) {
            return $query;
        }
        $branchIds = $actor->branches()->pluck('branches.id')->all();

        return $query->where(fn (Builder $builder) => $builder->whereKey($actor->getKey())
            ->orWhereHas('branches', fn (Builder $branches) => $branches->whereKey($branchIds)));
    }

    public function canManageUser(User $actor, User $target): bool
    {
        return $this->userScope($actor)->whereKey($target->getKey())->exists();
    }

    /** @param list<int|string> $branchIds */
    public function actorCanAssign(User $actor, array $branchIds): bool
    {
        if ($actor->can('branches.view_all')) {
            return true;
        }

        return $this->scope($actor)->whereKey($branchIds)->count() === count(array_unique($branchIds));
    }
}
