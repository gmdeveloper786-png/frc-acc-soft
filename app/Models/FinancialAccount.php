<?php

namespace App\Models;

use Database\Factories\FinancialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'name', 'account_id', 'branch_id', 'bank_name', 'account_title', 'account_number', 'account_last_four', 'currency', 'is_active', 'opening_reference', 'created_by'])]
class FinancialAccount extends Model
{
    /** @use HasFactory<FinancialAccountFactory> */
    use HasFactory;

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
