<?php

namespace App\Models;

use App\Enums\CashFlowActivity;
use Database\Factories\CashFlowClassificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The cash-flow activity an administrator explicitly assigned to a ledger.
 * It is configuration only: it never changes a journal or a balance.
 */
#[Fillable(['account_id', 'activity', 'updated_by'])]
class CashFlowClassification extends Model
{
    /** @use HasFactory<CashFlowClassificationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['activity' => CashFlowActivity::class];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
