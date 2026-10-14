<?php

namespace App\Services\Reports\Statements;

use App\Models\Branch;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Support\Collection;

/**
 * The branches a statement covers for one user. Built only through `resolve`,
 * which enforces the user's branch access.
 */
final class StatementScope
{
    /** @param Collection<int, Branch> $branches */
    private function __construct(
        public readonly Collection $branches,
        public readonly bool $consolidated,
        public readonly ?Branch $branch,
        public readonly string $label,
    ) {}

    /**
     * `$requested` is a branch id, the word "all" (every FRC branch, needs
     * `branches.view_all`) or empty (every branch the user may see). A branch
     * outside the user's access is a 403 and is never silently widened.
     */
    public static function resolve(User $actor, mixed $requested): self
    {
        abort_unless($actor->is_active && $actor->can('financial_statements.view'), 403);
        $viewAll = $actor->can('branches.view_all');
        $allowed = app(BranchAccess::class)->scope($actor)->orderBy('name')->get();

        if ($requested === 'all') {
            abort_unless($viewAll, 403);
            $requested = null;
        }

        if (filled($requested)) {
            $branch = $allowed->firstWhere('id', (int) $requested);
            abort_unless($branch !== null, 403);

            return new self(collect([$branch]), false, $branch, $branch->name);
        }

        return new self(
            $allowed,
            $viewAll,
            null,
            $viewAll ? 'All FRC branches (consolidated)' : 'Your authorized branches only: '.($allowed->pluck('name')->implode(', ') ?: 'none'),
        );
    }

    /** @return list<int> */
    public function branchIds(): array
    {
        return $this->branches->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
