<?php

namespace App\Models;

use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['vendor_code', 'name', 'contact_person', 'phone', 'email', 'address', 'tax_registration', 'payment_details', 'is_active', 'created_by'])]
class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['payment_details' => 'encrypted', 'is_active' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(PurchaseBill::class);
    }

    public function purchaseBills(): HasMany
    {
        return $this->bills();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(OperationalTransaction::class);
    }
}
