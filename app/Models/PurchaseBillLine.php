<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['purchase_bill_id', 'account_id', 'service_id', 'purchase_order_line_id', 'line_number', 'description', 'quantity', 'unit_amount', 'line_total'])]
class PurchaseBillLine extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_amount' => 'decimal:4', 'line_total' => 'decimal:4'];
    }

    public function purchaseBill(): BelongsTo
    {
        return $this->belongsTo(PurchaseBill::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(FrcService::class, 'service_id');
    }
}
