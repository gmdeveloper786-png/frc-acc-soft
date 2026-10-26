<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A recipient that is neither a vendor nor an employee (landlord, authority, consultant...). */
#[Fillable(['payee_type_id', 'name', 'entity_kind', 'phone', 'email', 'address', 'reference', 'description', 'is_active', 'created_by'])]
class Payee extends Model
{
    public const ENTITY_KINDS = ['organization' => 'Organization', 'individual' => 'Individual'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function payeeType(): BelongsTo
    {
        return $this->belongsTo(PayeeType::class);
    }

    public function isReferenced(): bool
    {
        return OperationalTransaction::query()->where('payee_id', $this->id)->exists();
    }
}
