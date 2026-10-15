<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bank_reconciliation_id', 'bank_statement_line_id', 'journal_line_id', 'match_type', 'note', 'matched_by'])]
class BankReconciliationMatch extends Model
{
    public const AUTO = 'auto';

    public const MANUAL = 'manual';

    /** A book line cleared before the first imported statement; it has no statement line. */
    public const BROUGHT_FORWARD = 'brought_forward';

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
