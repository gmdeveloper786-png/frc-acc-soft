<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\User;
use App\Services\ApprovalAuthorityService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

#[Signature('app:director-authority {action : grant, revoke, add-branches or remove-branches}
    {--email= : Email of the user}
    {--reason= : Owner approval reference (at least 10 characters)}
    {--database= : Name of the target database; must match the configured connection}
    {--branch=* : Branch id to assign, add or remove (repeat for several)}
    {--authorized-by= : Email of another active Director who authorized the change, required when one exists}
    {--create : Create the user as a new account holding only the Director role}
    {--name= : Full name of the new user (with --create)}')]
#[Description('Trusted provisioning: appoint or remove a Director, or change a Director branches, from the server console')]
class ManageDirectorAuthority extends Command
{
    public function handle(ApprovalAuthorityService $authority): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['grant', 'revoke', 'add-branches', 'remove-branches'], true)) {
            $this->error('Action must be "grant", "revoke", "add-branches" or "remove-branches".');

            return self::FAILURE;
        }

        // The operator names the database they intend to change, so a command run against the wrong .env changes nothing.
        $database = (string) DB::connection()->getDatabaseName();
        $this->line('Environment: '.app()->environment().' | Database: '.$database);
        $named = (string) ($this->option('database') ?: $this->ask('Type the target database name to continue'));
        if ($named !== $database) {
            $this->error('The named database does not match the configured connection. No change was made.');

            return self::FAILURE;
        }

        $create = $action === 'grant' && (bool) $this->option('create');
        $email = (string) ($this->option('email') ?: $this->ask('Email of the user'));
        $user = User::query()->where('email', $email)->first();
        if ($create && $user) {
            $this->error('A user with that email already exists. Run without --create to appoint the existing user.');

            return self::FAILURE;
        }
        if (! $create && ! $user) {
            $this->error('No user exists with that email.');

            return self::FAILURE;
        }
        if (in_array($action, ['add-branches', 'remove-branches'], true)) {
            return $this->changeBranches($authority, $user, $action === 'add-branches', $database);
        }
        $holdsAuthority = $user && $user->roles()->whereIn('name', $authority->directorRoleNames())->exists();
        if ($action === 'grant' && $holdsAuthority) {
            $this->error('This user already holds Director authority. No change was made.');

            return self::FAILURE;
        }
        $reason = (string) ($this->option('reason') ?: $this->ask('Reason (owner approval reference)'));

        $branches = collect();
        $authorizedBy = null;
        if ($action === 'grant') {
            $branches = $this->branchesOption();
            if ($branches === null) {
                return self::FAILURE;
            }

            // The first Director is appointed by the console operator; any further one needs a sitting Director's authorization.
            [$authorized, $authorizedBy] = $this->authorizingDirector($authority, $user);
            if (! $authorized) {
                return self::FAILURE;
            }
        }

        $password = null;
        $name = $user?->name;
        if ($create) {
            $name = (string) ($this->option('name') ?: $this->ask('Full name of the new user'));
            $password = (string) $this->secret('Password for the new user');
            $validator = Validator::make(
                ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => (string) $this->secret('Confirm password')],
                ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'confirmed', Password::defaults()]],
            );
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $error) {
                    $this->error($error);
                }

                return self::FAILURE;
            }
        }

        if ($branches->isNotEmpty()) {
            $this->line('Assigned branches: '.$branches->pluck('name')->implode(', '));
        }
        // The confirmation defaults to "no", so an unattended run changes nothing.
        if (! $this->confirm(ucfirst($action)." Director approval authority for {$name} <{$email}>?", false)) {
            $this->info('No change was made.');

            return self::FAILURE;
        }

        $context = ['environment' => app()->environment(), 'database' => $database];
        try {
            DB::transaction(function () use ($authority, $action, $create, $user, $name, $email, $password, $branches, $authorizedBy, $reason, $context): void {
                if ($create) {
                    $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'is_active' => true, 'is_administrator' => false, 'password_changed_at' => now()]);
                }
                if ($action === 'grant') {
                    $context += ['created_user' => $create, 'branch_ids_before' => $user->branches()->pluck('branches.id')->all(), 'branch_ids' => $branches->pluck('id')->all(), 'authorized_by' => $authorizedBy?->id];
                    $user->branches()->sync($branches->pluck('id'));
                }
                $authority->provision($user, $action === 'grant', $reason, 'console:'.get_current_user(), $context);
            });
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info($action === 'grant' ? 'Director authority granted and audited.' : 'Director authority removed and audited.');

        return self::SUCCESS;
    }

    /**
     * Add or remove branches of an existing Director, keeping every other
     * assignment and everything else on the account.
     */
    private function changeBranches(ApprovalAuthorityService $authority, User $user, bool $adding, string $database): int
    {
        if (! $user->roles()->whereIn('name', $authority->directorRoleNames())->exists()) {
            $this->error('This user does not hold Director authority. Use the grant action to appoint a Director.');

            return self::FAILURE;
        }
        $branches = $this->branchesOption();
        if ($branches === null) {
            return self::FAILURE;
        }
        // A Director never widens their own authority: another sitting Director must authorize, if one exists.
        [$authorized, $authorizedBy] = $this->authorizingDirector($authority, $user);
        if (! $authorized) {
            return self::FAILURE;
        }
        $reason = (string) ($this->option('reason') ?: $this->ask('Reason (owner approval reference)'));

        $current = $user->branches()->orderBy('branches.id')->get();
        $changing = $adding
            ? $branches->reject(fn (Branch $branch): bool => $current->contains($branch))
            : $branches->filter(fn (Branch $branch): bool => $current->contains($branch));
        $result = $adding ? $current->merge($changing)->sortBy('id') : $current->reject(fn (Branch $branch): bool => $changing->contains($branch));
        $label = fn ($set): string => $set->map(fn (Branch $branch): string => $branch->id.' '.$branch->name)->implode(', ') ?: 'none';

        $this->line("Director: {$user->name} <{$user->email}> (user {$user->id})");
        $this->line('Current branches: '.$label($current));
        $this->line(($adding ? 'Adding: ' : 'Removing: ').$label($changing));
        $this->line('Branches after the change: '.$label($result));
        if ($changing->isEmpty()) {
            $this->info($adding ? 'Every named branch is already assigned. No change was made.' : 'None of the named branches is assigned. No change was made.');

            return self::SUCCESS;
        }
        if (! $this->confirm(($adding ? 'Add' : 'Remove').' these branches for Director '.$user->name.'?', false)) {
            $this->info('No change was made.');

            return self::FAILURE;
        }

        $ids = $branches->pluck('id')->all();
        try {
            $authority->changeDirectorBranches($user, $adding ? $ids : [], $adding ? [] : $ids, $reason, 'console:'.get_current_user(), [
                'environment' => app()->environment(), 'database' => $database, 'authorized_by' => $authorizedBy?->id,
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info('Director branches updated and audited.');

        return self::SUCCESS;
    }

    /**
     * Branches named with --branch. Every value must be the id of an existing
     * branch; a repeated id counts once.
     *
     * @return Collection<int, Branch>|null
     */
    private function branchesOption(): ?Collection
    {
        $values = collect((array) $this->option('branch'))->map(fn ($id): string => trim((string) $id))->unique()->values();
        $ids = $values->filter(fn (string $id): bool => ctype_digit($id))->map(fn (string $id): int => (int) $id)->unique()->values();
        $branches = Branch::query()->whereKey($ids)->orderBy('id')->get();
        if ($values->isEmpty() || $ids->count() !== $values->count() || $branches->count() !== $ids->count()) {
            $this->error('Name each branch with --branch=<id>; every value must be the id of an existing branch.');

            return null;
        }

        return $branches;
    }

    /**
     * When another active Director exists, --authorized-by must name one who
     * holds the authority to appoint. The target never authorizes themselves.
     *
     * @return array{0: bool, 1: User|null}
     */
    private function authorizingDirector(ApprovalAuthorityService $authority, ?User $target): array
    {
        $directors = $authority->activeDirectors($target);
        if ($directors->isEmpty()) {
            return [true, null];
        }
        $director = $directors->first(fn (User $director): bool => $director->email === $this->option('authorized-by') && $director->can('approval_authority.assign'));
        if (! $director) {
            $this->error('Another Director exists. This change needs --authorized-by=<email> naming an active Director, other than the target, who holds the authority to appoint.');
        }

        return [$director !== null, $director];
    }
}
