<?php

namespace App\Models;

use App\Enums\OperationalTransactionStatus;
use App\Services\AccountingDecimal;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Database\Factories\PayableObligationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['source_expense_transaction_id', 'source_journal_entry_id', 'branch_id', 'payable_account_id', 'vendor_id', 'original_amount', 'obligation_date', 'source_reference'])]
class PayableObligation extends Model
{
    /** @use HasFactory<PayableObligationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['original_amount' => 'decimal:4', 'obligation_date' => 'date'];
    }

    public function sourceExpense(): BelongsTo
    {
        return $this->belongsTo(OperationalTransaction::class, 'source_expense_transaction_id');
    }

    public function sourceJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'source_journal_entry_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(OperationalTransaction::class, 'payable_obligation_id');
    }

    public function outstandingAmount(?CarbonInterface $asOf = null): string
    {
        $payments = $this->settlements()
            ->where('status', OperationalTransactionStatus::Posted->value)
            ->when($asOf, fn ($query) => $query->whereDate('transaction_date', '<=', $asOf))
            ->get(['amount']);
        $paid = $payments->reduce(
            fn (BigDecimal $sum, OperationalTransaction $payment): BigDecimal => $sum->plus(AccountingDecimal::of($payment->amount)),
            BigDecimal::zero()->toScale(4),
        );

        return AccountingDecimal::string(AccountingDecimal::of($this->original_amount)->minus($paid));
    }
}
