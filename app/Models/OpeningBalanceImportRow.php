<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['opening_balance_import_id', 'row_number', 'account_code', 'account_id', 'debit', 'credit', 'source_reference', 'validation_errors'])]
class OpeningBalanceImportRow extends Model
{
    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2', 'validation_errors' => 'array'];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceImport::class, 'opening_balance_import_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
