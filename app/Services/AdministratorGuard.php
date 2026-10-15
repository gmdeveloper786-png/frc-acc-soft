<?php

namespace App\Services;

use App\Enums\SystemRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AdministratorGuard
{
    /**
     * A user holding a protected system role is managed only by someone who
     * holds that role too, so a lesser administrator cannot take the account
     * over (password, email, status or roles).
     */
    public function ensureMayManage(User $actor, User $target): void
    {
        $missing = $this->protectedRolesNotHeld($actor, $target->roles()->pluck('name')->all());
        if ($missing !== []) {
            throw ValidationException::withMessages(['roles' => 'Only a user holding the '.implode(', ', $missing).' role can change this user.']);
        }
    }

    /**
     * Changing another user's email or password hands over their access, so
     * it needs every permission that user holds.
     */
    public function ensureMayChangeCredentials(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            return;
        }
        $beyond = $target->getAllPermissions()->pluck('name')->diff($actor->getAllPermissions()->pluck('name'));
        if ($beyond->isNotEmpty()) {
            throw ValidationException::withMessages(['email' => 'This user holds permissions you do not have, so you cannot change their email or password.']);
        }
    }

    public function mayManage(User $actor, User $target): bool
    {
        return $this->protectedRolesNotHeld($actor, $target->roles()->pluck('name')->all()) === [];
    }

    /**
     * An actor may only grant roles whose permissions they hold themselves, and
     * may grant or remove a protected system role only when they hold it.
     * Director authority is guarded separately by ApprovalAuthorityService.
     *
     * @param  list<string>  $newRoles
     */
    public function ensureMayAssignRoles(User $actor, ?User $target, array $newRoles): void
    {
        $current = $target ? $target->roles()->pluck('name')->all() : [];
        $granted = array_values(array_diff($newRoles, $current));
        $revoked = array_values(array_diff($current, $newRoles));

        $protected = $this->protectedRolesNotHeld($actor, [...$granted, ...$revoked]);
        if ($protected !== []) {
            throw ValidationException::withMessages(['roles' => 'Only a user holding the '.implode(', ', $protected).' role can grant or remove it.']);
        }

        $held = $actor->getAllPermissions()->pluck('name')->all();
        $beyond = Role::query()->whereIn('name', $granted)->with('permissions')->get()
            ->filter(fn (Role $role): bool => $role->permissions->pluck('name')->diff($held)->isNotEmpty())
            ->pluck('name')->all();
        if ($beyond !== []) {
            throw ValidationException::withMessages(['roles' => 'You can only grant roles whose permissions you hold yourself: '.implode(', ', $beyond).' carries permissions you do not have.']);
        }
    }

    /**
     * @param  list<string>  $roleNames
     * @return list<string>
     */
    private function protectedRolesNotHeld(User $actor, array $roleNames): array
    {
        $protected = Role::query()->whereIn('name', $roleNames)->where('is_protected', true)->pluck('name');

        return $protected->reject(fn (string $name): bool => $actor->hasRole($name))->values()->all();
    }

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
