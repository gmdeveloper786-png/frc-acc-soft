<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One synchronisation attempt for one FRC entity (enrollments or payments). */
#[Fillable(['entity', 'mode', 'status', 'cursor_from', 'cursor_from_id', 'cursor_to', 'cursor_to_id', 'pages', 'fetched', 'created', 'updated', 'unchanged', 'failed', 'error', 'triggered_by', 'started_at', 'finished_at'])]
class FrcSyncRun extends Model
{
    protected function casts(): array
    {
        return ['cursor_from' => 'datetime', 'cursor_to' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(FrcSyncException::class);
    }

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
