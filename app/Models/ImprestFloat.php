<?php

namespace App\Models;

use Database\Factories\ImprestFloatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A petty cash imprest: the fixed float a custodian holds in one petty cash
 * ledger of one branch. The balance is never stored; it is derived from
 * posted journal lines.
 */
#[Fillable(['branch_id', 'account_id', 'float_amount', 'source_financial_account_id', 'custodian_name', 'is_active', 'active_key', 'created_by', 'updated_by'])]
class ImprestFloat extends Model
{
    /** @use HasFactory<ImprestFloatFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (ImprestFloat $float): void {
            $float->active_key = $float->is_active ? '1' : null;
        });
    }

    protected function casts(): array
    {
        return ['float_amount' => 'decimal:4', 'is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'source_financial_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function replenishments(): HasMany
    {
        return $this->hasMany(ImprestReplenishment::class);
    }

    public function label(): string
    {
        return ($this->branch?->name ?? 'Branch').' — '.($this->account ? $this->account->code.' '.$this->account->name : 'Petty cash');
    }
}
