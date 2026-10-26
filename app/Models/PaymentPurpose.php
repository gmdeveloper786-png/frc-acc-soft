<?php

namespace App\Models;

use App\Enums\PaymentBehaviour;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A configurable business purpose bound to one protected accounting
 * behaviour. Only a direct expense purpose may fix its own debit ledger.
 */
#[Fillable(['name', 'code', 'description', 'behaviour', 'debit_account_id', 'is_active', 'sort_order', 'created_by'])]
class PaymentPurpose extends Model
{
    protected function casts(): array
    {
        return ['behaviour' => PaymentBehaviour::class, 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debit_account_id');
    }

    /** Whether payments use this purpose, which freezes its code, behaviour and debit ledger. */
    public function isReferenced(): bool
    {
        return OperationalTransaction::query()->where('payment_purpose_id', $this->id)->exists();
    }
}
