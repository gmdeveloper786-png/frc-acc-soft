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

    /** @param list<int|string> $branchIds */
    public function actorCanAssign(User $actor, array $branchIds): bool
    {
        if ($actor->can('branches.view_all')) {
            return true;
        }

        return $this->scope($actor)->whereKey($branchIds)->count() === count(array_unique($branchIds));
    }
}
