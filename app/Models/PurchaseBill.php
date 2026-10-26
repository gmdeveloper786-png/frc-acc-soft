<?php

namespace App\Models;

use App\Enums\OperationalTransactionStatus;
use App\Enums\PurchaseBillStatus;
use App\Services\AccountingDecimal;
use Brick\Math\BigDecimal;
use Database\Factories\PurchaseBillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['vendor_id', 'branch_id', 'payable_account_id', 'bill_number', 'invoice_date', 'due_date', 'purchase_order_reference', 'purchase_order_id', 'po_exception_status', 'po_exception_reason', 'po_exception_approved_by', 'po_exception_approved_at', 'description', 'total_amount', 'status', 'attachment_path', 'journal_entry_id', 'created_by', 'submitted_by', 'approved_by', 'submitted_at', 'approved_at', 'posted_at', 'rejection_reason'])]
class PurchaseBill extends Model
{
    /** @use HasFactory<PurchaseBillFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['status' => PurchaseBillStatus::class, 'invoice_date' => 'date', 'due_date' => 'date', 'total_amount' => 'decimal:4', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'po_exception_approved_at' => 'datetime'];
    }

    public function outstandingAmount(): string
    {
        // List pages eager load postedPayments so this does not run one query per bill.
        $payments = $this->relationLoaded('postedPayments') ? $this->postedPayments : $this->postedPayments()->get(['amount']);
        $paid = $payments
            ->reduce(fn (BigDecimal $sum, OperationalTransaction $payment): BigDecimal => $sum->plus(AccountingDecimal::of($payment->amount)), BigDecimal::zero()->toScale(4));

        return AccountingDecimal::string(AccountingDecimal::of($this->total_amount)->minus($paid));
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseBillLine::class)->orderBy('line_number');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OperationalTransaction::class);
    }

    public function postedPayments(): HasMany
    {
        return $this->payments()->where('status', OperationalTransactionStatus::Posted->value);
    }

    /** Payments that hold part of the bill: posted ones and those still in approval (not rejected or reversed). */
    public function reservingPayments(): HasMany
    {
        return $this->payments()->whereNotIn('status', [OperationalTransactionStatus::Rejected->value, OperationalTransactionStatus::Reversed->value]);
    }

    /** What a new payment may still settle: the total less every reserving payment, as the vendor payment workflow enforces. */
    public function availableToPayAmount(): string
    {
        $reserved = $this->reservingPayments()->get(['amount'])
            ->reduce(fn (BigDecimal $sum, OperationalTransaction $payment): BigDecimal => $sum->plus(AccountingDecimal::of($payment->amount)), BigDecimal::zero()->toScale(4));

        return AccountingDecimal::string(AccountingDecimal::of($this->total_amount)->minus($reserved));
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
