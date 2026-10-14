<?php

namespace App\Models;

use App\Enums\BudgetAlertLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Auditable record that a budget line reached the warning threshold or was
 * overrun. Recorded once per budget, account, period scope and level.
 */
#[Fillable(['budget_id', 'account_id', 'accounting_period_id', 'period_scope', 'level', 'utilisation_percentage', 'actual_amount', 'budget_amount', 'warning_percentage', 'detected_by', 'detected_at'])]
class BudgetAlert extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        $immutable = function (): void {
            throw new LogicException('Budget alerts are an audit record and cannot be changed or deleted.');
        };
        static::updating($immutable);
        static::deleting($immutable);
    }

    protected function casts(): array
    {
        return [
            'level' => BudgetAlertLevel::class, 'utilisation_percentage' => 'decimal:2',
            'actual_amount' => 'decimal:4', 'budget_amount' => 'decimal:4',
            'warning_percentage' => 'integer', 'detected_at' => 'datetime',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function detector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'detected_by');
    }
}
