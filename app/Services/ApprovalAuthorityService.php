<?php

namespace App\Services;

use App\Models\ApprovalDelegation;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Decides who holds Director approval authority. Authority comes from a role
 * explicitly marked in the database or from an active delegation by such a
 * Director; holding a permission alone never grants it.
 */
class ApprovalAuthorityService
{
    public const DIRECTOR = 'director';

    public function __construct(private BranchAccess $branchAccess, private AppSettings $settings, private AuditLogger $audit) {}

    /** True when one of the user's roles is marked as carrying Director authority. */
    public function holdsDirectorRole(User $user): bool
    {
        // Fails closed for an account that also holds a system administration role.
        return $user->is_active
            && $user->roles()->where('approval_authority', self::DIRECTOR)->exists()
            && ! $user->roles()->where('is_protected', true)->exists();
    }

    /**
     * How the user may give Director approval for this branch and amount:
     * "director_role", "delegation:{id}", or null when not eligible.
     */
    public function directorBasis(User $user, Branch $branch, mixed $amount, string $highValuePermission = 'purchase_orders.approve_high_value', string $standardPermission = 'purchase_orders.approve'): ?string
    {
        if ($this->holdsDirectorRole($user) && $user->can($highValuePermission)) {
            return 'director_role';
        }
        $delegation = $this->activeDelegation($user, $branch, $amount);

        return $delegation && $user->can($standardPermission) ? 'delegation:'.$delegation->id : null;
    }

    /**
     * Delegation stays switched off until the owner approves it. The switch is
     * a database setting that is deliberately absent from the Settings screen.
     */
    public function delegationEnabled(): bool
    {
        return (bool) $this->settings->get('approval.delegation_enabled', false);
    }

    public function activeDelegation(User $user, Branch $branch, mixed $amount): ?ApprovalDelegation
    {
        if (! $this->delegationEnabled() || ! $user->is_active) {
            return null;
        }
        $value = AccountingDecimal::of($amount);

        return ApprovalDelegation::query()->active()->with('delegator')
            ->where('authority', self::DIRECTOR)->where('delegate_id', $user->id)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->orderBy('id')->get()
            ->first(fn (ApprovalDelegation $delegation): bool => $this->holdsDirectorRole($delegation->delegator)
                && ($delegation->max_amount === null || ! $value->isGreaterThan(AccountingDecimal::of($delegation->max_amount))));
    }

    /**
     * Delegate Director authority. Only a Director by role may do so, never to
     * themselves, always with a reason and a bounded period.
     *
     * @param  array{reason: string, starts_at?: string|null, ends_at: string, branch_id?: int|null, max_amount?: string|null}  $data
     */
    public function delegate(User $delegate, array $data, User $actor): ApprovalDelegation
    {
        abort_unless($actor->can('approval_delegations.manage') && $this->holdsDirectorRole($actor), 403);
        if (! $this->delegationEnabled()) {
            throw ValidationException::withMessages(['delegation' => 'Director delegation is disabled. It can be used only after the owner formally approves a delegation policy.']);
        }

        $startsAt = Carbon::parse($data['starts_at'] ?? now());
        $endsAt = Carbon::parse($data['ends_at']);
        $maxDays = max(1, $this->settings->integer('approval.delegation_max_days', 14));
        $errors = [];
        if ($delegate->is($actor)) {
            $errors['delegate_id'] = 'Director authority cannot be delegated to yourself.';
        } elseif (! $delegate->is_active || ! $delegate->can('purchase_orders.approve')) {
            $errors['delegate_id'] = 'The delegate must be an active user who is already an authorized purchase order approver.';
        }
        if (mb_strlen(trim($data['reason'])) < 10) {
            $errors['reason'] = 'A documented reason of at least 10 characters is required.';
        }
        if ($endsAt->lessThanOrEqualTo($startsAt) || $endsAt->isPast() || $startsAt->diffInDays($endsAt) > $maxDays) {
            $errors['ends_at'] = "A delegation must end in the future and last at most {$maxDays} days.";
        }
        if (filled($data['max_amount'] ?? null) && ! AccountingDecimal::of($data['max_amount'])->isPositive()) {
            $errors['max_amount'] = 'The delegated amount limit must be greater than zero.';
        }
        $branch = filled($data['branch_id'] ?? null) ? Branch::query()->find($data['branch_id']) : null;
        if (filled($data['branch_id'] ?? null) && (! $branch || ! $this->branchAccess->canAccess($actor, $branch))) {
            $errors['branch_id'] = 'You can only delegate authority for a branch you can access.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($delegate, $data, $actor, $startsAt, $endsAt, $branch): ApprovalDelegation {
            $delegation = ApprovalDelegation::query()->create([
                'authority' => self::DIRECTOR, 'delegate_id' => $delegate->id, 'delegated_by' => $actor->id,
                'branch_id' => $branch?->id, 'max_amount' => filled($data['max_amount'] ?? null) ? AccountingDecimal::string(AccountingDecimal::of($data['max_amount'])) : null,
                'reason' => trim($data['reason']), 'starts_at' => $startsAt, 'ends_at' => $endsAt,
            ]);
            $this->audit->log('approval_delegations.granted', $delegation, ['delegate_id' => $delegate->id, 'branch_id' => $branch?->id, 'max_amount' => $delegation->max_amount, 'starts_at' => $startsAt->toDateTimeString(), 'ends_at' => $endsAt->toDateTimeString(), 'reason' => $delegation->reason]);

            return $delegation;
        });
    }

    /** Whether the user may grant or remove Director authority through user management. */
    public function mayManageDirectorRole(User $actor): bool
    {
        return $this->holdsDirectorRole($actor) && $actor->can('approval_authority.assign');
    }

    /** @param list<string> $roleNames */
    private function includesProtectedRole(array $roleNames): bool
    {
        return Role::query()->whereIn('name', $roleNames)->where('is_protected', true)->exists();
    }

    /** @return list<string> Names of the roles that carry Director authority. */
    public function directorRoleNames(): array
    {
        return Role::query()->where('approval_authority', self::DIRECTOR)->pluck('name')->all();
    }

    /**
     * Guard a user-management change against granting, removing or taking over
     * Director authority. Returns the authority roles being granted and revoked.
     *
     * @param  list<string>  $newRoles
     * @return array{granted: list<string>, revoked: list<string>}
     */
    public function guardUserChange(?User $target, array $newRoles, bool $credentialsChange, ?string $reason, User $actor): array
    {
        $authorityRoles = $this->directorRoleNames();
        $current = $target ? $target->roles->pluck('name')->intersect($authorityRoles)->values()->all() : [];
        $requested = array_values(array_intersect($newRoles, $authorityRoles));
        $granted = array_values(array_diff($requested, $current));
        $revoked = array_values(array_diff($current, $requested));
        $allowed = $this->mayManageDirectorRole($actor);

        if ($requested !== [] && $this->includesProtectedRole($newRoles)) {
            throw ValidationException::withMessages(['roles' => 'One account cannot hold both a system administration role and Director approval authority. This separation stays in force until the owner explicitly approves otherwise.']);
        }
        if (($granted !== [] || $revoked !== []) && (! $allowed || ($target && $target->is($actor)))) {
            throw ValidationException::withMessages(['roles' => 'Director approval authority can only be granted or removed by an existing Director with that authority, or through the documented Director provisioning procedure.']);
        }
        if (($granted !== [] || $revoked !== []) && mb_strlen(trim((string) $reason)) < 10) {
            throw ValidationException::withMessages(['authority_reason' => 'Record a reason of at least 10 characters for changing Director authority.']);
        }
        if ($credentialsChange && $current !== [] && ! $allowed && ! $target->is($actor)) {
            throw ValidationException::withMessages(['email' => 'The email and password of a user holding Director authority can only be changed by that user or by an existing Director.']);
        }

        return ['granted' => $granted, 'revoked' => $revoked];
    }

    /**
     * Record a change of Director authority. A null actor means the trusted
     * console provisioning procedure.
     *
     * @param  list<string>  $granted
     * @param  list<string>  $revoked
     */
    public function recordRoleChange(User $target, array $granted, array $revoked, string $reason, string $source): void
    {
        foreach (['granted' => $granted, 'revoked' => $revoked] as $action => $roles) {
            if ($roles !== []) {
                $this->audit->log('approval_authority.director_'.$action, $target, ['roles' => $roles, 'reason' => trim($reason), 'source' => $source]);
            }
        }
    }

    /**
     * Trusted provisioning: appoint or remove a Director from the server
     * console. Not reachable from any web route.
     */
    public function provision(User $target, bool $grant, string $reason, string $source): void
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'Record a reason of at least 10 characters.']);
        }
        $roles = $this->directorRoleNames();
        if ($roles === []) {
            throw ValidationException::withMessages(['role' => 'No role carries Director authority. Run the RBAC seeder first.']);
        }
        if ($grant && ! $target->is_active) {
            throw ValidationException::withMessages(['user' => 'Director authority cannot be given to an inactive user.']);
        }
        if ($grant && $this->includesProtectedRole($target->roles()->pluck('name')->all())) {
            throw ValidationException::withMessages(['user' => 'This user holds a system administration role. One account cannot be both Administrator and Director until the owner explicitly approves it.']);
        }

        DB::transaction(function () use ($target, $grant, $reason, $source, $roles): void {
            $held = $target->roles()->whereIn('name', $roles)->pluck('name')->all();
            if ($grant && $held === []) {
                $target->assignRole($roles[0]);
                $this->recordRoleChange($target, [$roles[0]], [], $reason, $source);
            } elseif (! $grant && $held !== []) {
                foreach ($held as $role) {
                    $target->removeRole($role);
                }
                $this->recordRoleChange($target, [], $held, $reason, $source);
            }
        });
    }

    public function revoke(ApprovalDelegation $delegation, User $actor): ApprovalDelegation
    {
        abort_unless($actor->can('approval_delegations.manage') && $this->holdsDirectorRole($actor), 403);
        if ($delegation->revoked_at === null) {
            $delegation->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);
            $this->audit->log('approval_delegations.revoked', $delegation, ['delegate_id' => $delegation->delegate_id]);
        }

        return $delegation;
    }
}
