<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable recipient category. It only chooses one of the system
 * sources below; admins add categories, never new integrations.
 */
#[Fillable(['name', 'code', 'source_kind', 'description', 'is_active', 'sort_order', 'created_by'])]
class PayeeType extends Model
{
    /** Recipients from the Vendors master. */
    public const SOURCE_VENDOR = 'vendor';

    /** Recipients from the Employees master. */
    public const SOURCE_EMPLOYEE = 'employee';

    /** Recipients kept in the Payee Directory. */
    public const SOURCE_DIRECTORY = 'directory';

    public const SOURCES = [self::SOURCE_VENDOR => 'Vendors master', self::SOURCE_EMPLOYEE => 'Employees master', self::SOURCE_DIRECTORY => 'Payee Directory'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function payees(): HasMany
    {
        return $this->hasMany(Payee::class);
    }

    /** Whether payees or payments refer to this type, which freezes its code and source. */
    public function isReferenced(): bool
    {
        return $this->payees()->exists() || OperationalTransaction::query()->where('payee_type_id', $this->id)->exists();
    }
}
