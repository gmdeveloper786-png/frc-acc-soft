<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['fiscal_year_id', 'branch_id', 'opening_date', 'original_filename', 'stored_path', 'file_hash', 'status', 'total_debit', 'total_credit', 'difference', 'created_by', 'applied_at'])]
class OpeningBalanceImport extends Model
{
    protected function casts(): array
    {
        return ['opening_date' => 'date', 'total_debit' => 'decimal:2', 'total_credit' => 'decimal:2', 'difference' => 'decimal:2', 'applied_at' => 'datetime'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(OpeningBalanceImportRow::class)->orderBy('row_number');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
