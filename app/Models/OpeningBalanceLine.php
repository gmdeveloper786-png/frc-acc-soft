<?php

namespace App\Models;

use Database\Factories\OpeningBalanceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['opening_balance_batch_id', 'account_id', 'debit', 'credit', 'source_reference'])]
class OpeningBalanceLine extends Model
{
    /** @use HasFactory<OpeningBalanceLineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceBatch::class, 'opening_balance_batch_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
