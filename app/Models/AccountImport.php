<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['original_filename', 'stored_path', 'file_hash', 'status', 'summary', 'created_by', 'confirmed_by', 'confirmed_at'])]
class AccountImport extends Model
{
    protected function casts(): array
    {
        return ['summary' => 'array', 'confirmed_at' => 'datetime'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(AccountImportRow::class)->orderBy('row_number');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
