<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['fixed_asset_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'uploaded_by'])]
class FixedAssetDocument extends Model
{
    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
