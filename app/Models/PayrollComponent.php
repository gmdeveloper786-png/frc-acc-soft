<?php

namespace App\Models;

use App\Enums\PayrollComponentCategory;
use App\Enums\PayrollComponentType;
use Database\Factories\PayrollComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable earning or deduction. For a deduction, account_id is the
 * ledger credited on accrual; for an earning it optionally overrides the
 * Salary Expense mapping.
 */
#[Fillable(['code', 'name', 'type', 'category', 'account_id', 'is_active', 'sort_order'])]
class PayrollComponent extends Model
{
    /** @use HasFactory<PayrollComponentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['type' => PayrollComponentType::class, 'category' => PayrollComponentCategory::class, 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function isEarning(): bool
    {
        return $this->type === PayrollComponentType::Earning;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function runLineItems(): HasMany
    {
        return $this->hasMany(PayrollRunLineItem::class);
    }
}
