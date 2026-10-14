<?php

namespace App\Models;

use App\Enums\PurchaseBillStatus;
use App\Services\AccountingDecimal;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['purchase_order_id', 'line_number', 'description', 'quantity', 'unit', 'unit_price', 'discount_amount', 'tax_amount', 'line_total', 'account_id', 'service_id'])]
class PurchaseOrderLine extends Model
{
    protected static function booted(): void
    {
        $guard = function (PurchaseOrderLine $line): void {
            $order = PurchaseOrder::query()->find($line->purchase_order_id);
            if ($order && ! $order->status->isEditable()) {
                throw new LogicException('Lines of a submitted or approved purchase order cannot be changed.');
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'discount_amount' => 'decimal:4', 'tax_amount' => 'decimal:4', 'line_total' => 'decimal:4'];
    }

    /** Quantity on linked bills that still count against this line. */
    public function billedQuantity(): string
    {
        $billed = $this->billLines()
            ->whereHas('purchaseBill', fn ($query) => $query->whereNotIn('status', [PurchaseBillStatus::Rejected->value, PurchaseBillStatus::Reversed->value]))
            ->get('quantity')
            ->reduce(fn (BigDecimal $sum, PurchaseBillLine $line): BigDecimal => $sum->plus(AccountingDecimal::of($line->quantity)), BigDecimal::zero()->toScale(4));

        return AccountingDecimal::string($billed);
    }

    public function remainingQuantity(): string
    {
        return AccountingDecimal::string(AccountingDecimal::of($this->quantity)->minus(AccountingDecimal::of($this->billedQuantity())));
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(FrcService::class, 'service_id');
    }

    public function billLines(): HasMany
    {
        return $this->hasMany(PurchaseBillLine::class);
    }
}
