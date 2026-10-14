<?php

namespace App\Services;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AdministratorGuard
{
    /** @param list<string> $newRoles */
    public function ensureMayChange(User $user, bool $willBeActive, array $newRoles): void
    {
        $currentlyAdministrator = $user->hasRole(SystemRole::Administrator->value);
        $willBeAdministrator = in_array(SystemRole::Administrator->value, $newRoles, true);

        if ($currentlyAdministrator && (! $willBeActive || ! $willBeAdministrator)) {
            $otherActiveAdministrators = User::role(SystemRole::Administrator->value)
                ->where('is_active', true)
                ->whereKeyNot($user->getKey())
                ->exists();

            if (! $otherActiveAdministrators) {
                throw ValidationException::withMessages(['roles' => 'The last active Administrator cannot be deactivated or lose the Administrator role.']);
            }
        }
    }
}
