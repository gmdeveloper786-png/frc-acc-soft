<?php

namespace App\Models;

use App\Enums\FiscalYearStatus;
use Database\Factories\FiscalYearFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'start_date', 'end_date', 'status', 'is_current', 'created_by', 'closed_by', 'closed_at'])]
class FiscalYear extends Model
{
    /** @use HasFactory<FiscalYearFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'status' => FiscalYearStatus::class, 'is_current' => 'boolean', 'closed_at' => 'datetime'];
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class)->orderBy('period_number');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function openingBalanceBatches(): HasMany
    {
        return $this->hasMany(OpeningBalanceBatch::class);
    }

    public function journals(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }
}
