<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['account_import_id', 'row_number', 'code', 'name', 'level', 'parent_code', 'account_type', 'normal_balance', 'financial_statement', 'source_entity', 'is_postable', 'notes', 'validation_errors', 'review_reasons', 'classification', 'requires_manual_review', 'proposed_decision', 'proposed_reason', 'review_status', 'review_notes', 'reviewed_by', 'reviewed_at', 'is_existing', 'is_selected'])]
class AccountImportRow extends Model
{
    protected function casts(): array
    {
        return ['is_postable' => 'boolean', 'validation_errors' => 'array', 'review_reasons' => 'array', 'requires_manual_review' => 'boolean', 'reviewed_at' => 'datetime', 'is_existing' => 'boolean', 'is_selected' => 'boolean'];
    }

    /** Rows that may only be imported after a documented reviewer decision. */
    public function needsDocumentedApproval(): bool
    {
        return $this->requires_manual_review || $this->proposed_decision !== 'include';
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(AccountImport::class, 'account_import_id');
    }
}
