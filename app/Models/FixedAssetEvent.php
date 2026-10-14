<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of a fixed asset.
 */
#[Fillable(['fixed_asset_id', 'event', 'actor_id', 'details', 'created_at'])]
class FixedAssetEvent extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        $immutable = function (): void {
            throw new LogicException('Fixed asset history cannot be changed or deleted.');
        };
        static::updating($immutable);
        static::deleting($immutable);
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
