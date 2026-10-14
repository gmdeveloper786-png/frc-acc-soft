<?php

namespace App\Services\Reports\Operational;

use App\Models\Branch;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves which branches a report may cover. A branch outside the user's
 * scope is refused, never silently replaced, and the consolidated view of
 * every branch is reserved for users holding branches.view_all.
 */
class ReportBranchScope
{
    public const ALL = 'all';

    public function __construct(private BranchAccess $access) {}

    /**
     * @return array{branches: Collection<int, Branch>, ids: list<int>, selected: ?Branch, consolidated: bool, label: string}
     */
    public function resolve(User $user, mixed $requested): array
    {
        $branches = $this->access->scope($user)->orderBy('name')->get();
        $viewAll = $user->can('branches.view_all');

        if ($requested === self::ALL) {
            abort_unless($viewAll, 403, 'The consolidated view of all branches is not available to you.');
            $requested = null;
        } elseif (filled($requested)) {
            $selected = $branches->firstWhere('id', (int) $requested);
            abort_unless($selected !== null && (string) (int) $requested === (string) $requested, 403);

            return ['branches' => $branches, 'ids' => [$selected->id], 'selected' => $selected, 'consolidated' => false, 'label' => $selected->name];
        }

        if ($viewAll) {
            return ['branches' => $branches, 'ids' => $branches->modelKeys(), 'selected' => null, 'consolidated' => true, 'label' => 'All branches (consolidated)'];
        }

        // Without branches.view_all a report always covers exactly one authorized branch.
        $selected = $branches->first();

        return ['branches' => $branches, 'ids' => $selected ? [$selected->id] : [], 'selected' => $selected, 'consolidated' => false, 'label' => $selected?->name ?? 'No authorized branch'];
    }
}
