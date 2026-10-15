<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only workflow history for a staff advance.
 */
#[Table('staff_advance_history')]
#[Fillable(['staff_advance_id', 'action', 'from_status', 'to_status', 'actor_id', 'comments', 'created_at'])]
class StaffAdvanceHistory extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        $immutable = function (): void {
            throw new LogicException('Staff advance history cannot be changed or deleted.');
        };
        static::updating($immutable);
        static::deleting($immutable);
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(StaffAdvance::class, 'staff_advance_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
