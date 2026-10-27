<?php

namespace App\Models;

use App\Enums\PurchaseBillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Services\AccountingDecimal;
use Brick\Math\BigDecimal;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['po_number', 'vendor_id', 'branch_id', 'po_date', 'expected_delivery_date', 'currency', 'reference', 'description', 'status', 'approval_tier', 'subtotal', 'discount_total', 'tax_total', 'total_amount', 'created_by', 'submitted_by', 'approved_by', 'issued_by', 'closed_by', 'cancelled_by', 'submitted_at', 'approved_at', 'issued_at', 'closed_at', 'cancelled_at', 'rejection_reason', 'closure_reason'])]
class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory;

    /** Commercial terms that are frozen once the order leaves draft. */
    private const LOCKED_FIELDS = ['vendor_id', 'branch_id', 'po_date', 'currency', 'subtotal', 'discount_total', 'tax_total', 'total_amount'];

    protected static function booted(): void
    {
        static::updating(function (PurchaseOrder $order): void {
            if ($order->isDirty('po_number')) {
                throw new LogicException('A purchase order number cannot be changed.');
            }
            $original = PurchaseOrderStatus::from((string) $order->getRawOriginal('status'));
            if (! $original->isEditable() && $order->isDirty(self::LOCKED_FIELDS)) {
                throw new LogicException('Commercial terms of a submitted or approved purchase order cannot be changed.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class, 'po_date' => 'date', 'expected_delivery_date' => 'date',
            'subtotal' => 'decimal:4', 'discount_total' => 'decimal:4', 'tax_total' => 'decimal:4', 'total_amount' => 'decimal:4',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'issued_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    /** Value of linked bills that still count against the order. */
    public function billedAmount(): string
    {
        $billed = $this->activeBills()->get('total_amount')
            ->reduce(fn (BigDecimal $sum, PurchaseBill $bill): BigDecimal => $sum->plus(AccountingDecimal::of($bill->total_amount)), BigDecimal::zero()->toScale(4));

        return AccountingDecimal::string($billed);
    }

    public function remainingAmount(): string
    {
        return AccountingDecimal::string(AccountingDecimal::of($this->total_amount)->minus(AccountingDecimal::of($this->billedAmount())));
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('line_number');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(PurchaseOrderApproval::class)->orderBy('id');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(PurchaseBill::class);
    }

    public function activeBills(): HasMany
    {
        return $this->bills()->whereNotIn('status', [PurchaseBillStatus::Rejected->value, PurchaseBillStatus::Reversed->value, PurchaseBillStatus::Cancelled->value]);
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
