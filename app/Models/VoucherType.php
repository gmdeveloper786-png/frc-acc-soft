<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'prefix', 'year_format', 'padding', 'reset_period', 'is_active', 'is_system', 'created_by'])]
class VoucherType extends Model
{
    protected function casts(): array
    {
        return ['padding' => 'integer', 'is_active' => 'boolean', 'is_system' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sequences(): HasMany
    {
        return $this->hasMany(VoucherSequence::class);
    }

    public function journals(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }
}
