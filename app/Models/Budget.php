<?php

namespace App\Models;

use App\Enums\BudgetStatus;
use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A budget is a planning record. It never creates a journal entry and never
 * blocks a transaction.
 */
#[Fillable(['fiscal_year_id', 'branch_id', 'service_id', 'service_scope', 'name', 'version', 'root_budget_id', 'status', 'total_amount', 'created_by', 'submitted_by', 'approved_by', 'submitted_at', 'approved_at', 'rejection_reason', 'revision_reason', 'active_key'])]
class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use HasFactory;

    public const SCOPE_ALL = 'all';

    /** Scope and totals that are frozen once the budget leaves draft. */
    private const LOCKED_FIELDS = ['fiscal_year_id', 'branch_id', 'service_id', 'service_scope', 'name', 'version', 'root_budget_id', 'total_amount', 'created_by', 'revision_reason'];

    /** The only change an approved budget accepts: being superseded by its next version. */
    private const SUPERSEDE_FIELDS = ['status', 'active_key', 'updated_at'];

    protected static function booted(): void
    {
        static::saving(function (Budget $budget): void {
            $budget->service_scope = self::scopeFor($budget->service_id);
            $budget->active_key = $budget->status === BudgetStatus::Approved ? '1' : null;
        });

        static::updating(function (Budget $budget): void {
            $original = BudgetStatus::from((string) $budget->getRawOriginal('status'));

            if ($original === BudgetStatus::Superseded) {
                throw new LogicException('A superseded budget is immutable.');
            }
            if ($original === BudgetStatus::Approved) {
                $isSupersede = $budget->status === BudgetStatus::Superseded
                    && empty(array_diff(array_keys($budget->getDirty()), self::SUPERSEDE_FIELDS));
                if (! $isSupersede) {
                    throw new LogicException('An approved budget is immutable. Create a revision instead.');
                }
            }
            if (! $original->isEditable() && $budget->isDirty(self::LOCKED_FIELDS)) {
                throw new LogicException('Scope and amounts of a submitted or approved budget cannot be changed.');
            }
        });

        static::deleting(function (Budget $budget): void {
            if (! $budget->status->isEditable()) {
                throw new LogicException('A submitted, approved or superseded budget cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => BudgetStatus::class, 'version' => 'integer', 'total_amount' => 'decimal:4',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime',
        ];
    }

    public static function scopeFor(int|string|null $serviceId): string
    {
        return filled($serviceId) ? 'service:'.$serviceId : self::SCOPE_ALL;
    }

    /** Id of the first version of this budget's chain. */
    public function rootId(): int
    {
        return (int) ($this->root_budget_id ?? $this->id);
    }

    /**
     * Every version of the chain this budget belongs to.
     *
     * @return Builder<Budget>
     */
    public function versions(): Builder
    {
        $rootId = $this->rootId();

        return self::query()->where(fn (Builder $query) => $query->whereKey($rootId)->orWhere('root_budget_id', $rootId))->orderBy('version');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(FrcService::class, 'service_id');
    }

    public function root(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_budget_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(BudgetApproval::class)->orderBy('id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(BudgetAlert::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
