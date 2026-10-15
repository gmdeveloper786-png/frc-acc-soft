<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'income_account_id', 'fund_id', 'is_active'])]
class DonationCategory extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_account_id');
    }

    /** Default fund of donations in this category; a donation may choose another. */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(OperationalTransaction::class);
    }
}
