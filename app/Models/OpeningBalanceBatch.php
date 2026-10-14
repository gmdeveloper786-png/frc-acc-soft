<?php

namespace App\Models;

use Database\Factories\OpeningBalanceBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['fiscal_year_id', 'branch_id', 'opening_date', 'status', 'total_debit', 'total_credit', 'difference', 'source_reference', 'created_by', 'updated_by', 'approved_by', 'posted_by', 'journal_entry_id', 'approved_at', 'posted_at', 'rejection_reason'])]
class OpeningBalanceBatch extends Model
{
    /** @use HasFactory<OpeningBalanceBatchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['opening_date' => 'date', 'total_debit' => 'decimal:2', 'total_credit' => 'decimal:2', 'difference' => 'decimal:2', 'approved_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningBalanceLine::class)->orderBy('account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
