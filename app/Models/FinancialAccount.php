<?php

namespace App\Models;

use Database\Factories\FinancialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'name', 'account_id', 'branch_id', 'scope', 'bank_name', 'account_title', 'account_number', 'account_last_four', 'currency', 'is_active', 'opening_reference', 'created_by'])]
class FinancialAccount extends Model
{
    /** @use HasFactory<FinancialAccountFactory> */
    use HasFactory;

    /** Used only by its own branch. */
    public const SCOPE_BRANCH = 'branch';

    /** Shared by every branch (one organisational bank account); branch_id is its custodian branch. */
    public const SCOPE_ORGANIZATION = 'organization';

    protected $attributes = ['scope' => self::SCOPE_BRANCH];

    public function isShared(): bool
    {
        return $this->scope === self::SCOPE_ORGANIZATION;
    }

    /** Whether a transaction of $branchId may use this account. The transaction keeps its own branch. */
    public function usableIn(int $branchId): bool
    {
        return $this->isShared() || (int) $this->branch_id === $branchId;
    }

    /**
     * Accounts a transaction of any of these branches may use: their own
     * accounts plus every shared account.
     *
     * @param  Builder<self>  $query
     * @param  iterable<int>|\Illuminate\Contracts\Database\Query\Builder|int  $branchIds
     */
    public function scopeUsableIn(Builder $query, mixed $branchIds): void
    {
        $query->where(fn (Builder $inner) => $inner->where('scope', self::SCOPE_ORGANIZATION)
            ->orWhereIn('branch_id', is_int($branchIds) ? [$branchIds] : $branchIds));
    }

    /**
     * Accounts whose book (balance, statements, reconciliation) $user may see:
     * accounts of the user's branches, and shared accounts only with access to
     * every branch, because a shared book holds every branch's entries.
     *
     * @param  Builder<self>  $query
     */
    public function scopeBookVisibleTo(Builder $query, User $user, mixed $branchIds): void
    {
        $query->where(fn (Builder $inner) => $inner->where(fn (Builder $own) => $own->where('scope', self::SCOPE_BRANCH)->whereIn('branch_id', $branchIds))
            ->when($user->can('branches.view_all'), fn (Builder $all) => $all->orWhere('scope', self::SCOPE_ORGANIZATION)));
    }

    /** Whether $user may see this account's book or manage it. */
    public function bookVisibleTo(User $user, bool $branchAccess): bool
    {
        return $this->isShared() ? $user->can('branches.view_all') : $branchAccess;
    }

    protected function casts(): array
    {
        return ['account_number' => 'encrypted', 'is_active' => 'boolean'];
    }

    public function maskedNumber(): ?string
    {
        return $this->account_last_four ? '•••• '.$this->account_last_four : null;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(OperationalTransaction::class);
    }
}
