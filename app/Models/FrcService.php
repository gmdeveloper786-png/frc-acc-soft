<?php

namespace App\Models;

use Database\Factories\FrcServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['frc_service_id', 'name', 'status', 'revenue_account_id', 'last_synced_at'])]
class FrcService extends Model
{
    /** @use HasFactory<FrcServiceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'service_id');
    }
}
