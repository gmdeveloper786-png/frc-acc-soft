<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sanitised problem found while syncing or posting one FRC record. */
#[Fillable(['frc_sync_run_id', 'entity', 'external_id', 'code', 'message', 'resolved_at', 'resolved_by'])]
class FrcSyncException extends Model
{
    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FrcSyncRun::class, 'frc_sync_run_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
