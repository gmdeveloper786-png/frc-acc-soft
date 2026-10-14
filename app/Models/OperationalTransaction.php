<?php

namespace App\Models;

use App\Enums\OperationalTransactionStatus;
use App\Enums\OperationalTransactionType;
use Database\Factories\OperationalTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'status', 'branch_id', 'transaction_date', 'party_name', 'amount', 'financial_account_id', 'contra_account_id', 'payable_account_id', 'vendor_id', 'service_id', 'donation_category_id', 'purchase_bill_id', 'reference_number', 'narration', 'settlement_type', 'approval_tier', 'attachment_path', 'source_system', 'source_reference', 'source_key', 'journal_entry_id', 'created_by', 'submitted_by', 'approved_by', 'disbursed_by', 'submitted_at', 'approved_at', 'disbursed_at', 'posted_at', 'rejection_reason'])]
class OperationalTransaction extends Model
{
    /** @use HasFactory<OperationalTransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['type' => OperationalTransactionType::class, 'status' => OperationalTransactionStatus::class, 'transaction_date' => 'date', 'amount' => 'decimal:4', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'disbursed_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function contraAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'contra_account_id');
    }

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(FrcService::class, 'service_id');
    }

    public function donationCategory(): BelongsTo
    {
        return $this->belongsTo(DonationCategory::class);
    }

    public function purchaseBill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
