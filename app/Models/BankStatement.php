<?php

namespace App\Models;

use Database\Factories\BankStatementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['financial_account_id', 'branch_id', 'status', 'original_filename', 'stored_path', 'file_extension', 'file_hash', 'header_row', 'detected_headers', 'column_mapping', 'period_from', 'period_to', 'opening_balance', 'closing_balance', 'line_count', 'uploaded_by', 'imported_at'])]
class BankStatement extends Model
{
    /** @use HasFactory<BankStatementFactory> */
    use HasFactory;

    public const PENDING = 'pending';

    public const IMPORTED = 'imported';

    protected function casts(): array
    {
        return [
            'detected_headers' => 'array',
            'column_mapping' => 'array',
            'period_from' => 'date',
            'period_to' => 'date',
            'opening_balance' => 'decimal:4',
            'closing_balance' => 'decimal:4',
            'imported_at' => 'datetime',
        ];
    }

    public function isImported(): bool
    {
        return $this->status === self::IMPORTED;
    }

    public function statusLabel(): string
    {
        return $this->isImported() ? 'Imported' : 'Awaiting column mapping';
    }

    public function badge(): string
    {
        return $this->isImported() ? 'success' : 'pending';
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('transaction_date')->orderBy('row_number');
    }
}
