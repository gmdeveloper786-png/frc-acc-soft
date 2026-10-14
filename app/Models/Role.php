<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use LogicException;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * `approval_authority` is deliberately not mass assignable: it is set only by
 * the RBAC seeder and migrations, never from the role administration form.
 */
#[Fillable(['name', 'guard_name', 'is_protected', 'max_branches'])]
class Role extends SpatieRole
{
    protected static function booted(): void
    {
        static::saving(function (Role $role): void {
            if ($role->is_protected && $role->approval_authority !== null) {
                throw new LogicException('A protected system role cannot carry Director approval authority.');
            }
        });
    }

    protected function casts(): array
    {
        return ['is_protected' => 'boolean', 'max_branches' => 'integer'];
    }
}
