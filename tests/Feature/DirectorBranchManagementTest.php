<?php

use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\ApprovalAuthorityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->branches = Branch::factory()->count(6)->create()->sortBy('id')->values();
    $this->director = User::factory()->create(['email' => 'test.director@example.test']);
    $this->director->assignRole(SystemRole::Director->value);
    $this->director->branches()->attach($this->branches->take(3)->pluck('id'));
    $this->administrator = User::factory()->create();
    $this->administrator->assignRole(SystemRole::Administrator->value);
    $this->administrator->branches()->attach($this->branches->pluck('id'));
});

/** @param list<int> $ids */
function branchCommand(string $action, string $email, array $ids, array $overrides = []): array
{
    return ['action' => $action, '--email' => $email, '--branch' => $ids, '--reason' => 'Owner letter FRC-2026-09 extends Director coverage', '--database' => DB::connection()->getDatabaseName(), ...$overrides];
}

function directorBranchIds(User $user): array
{
    return $user->branches()->orderBy('branches.id')->pluck('branches.id')->map(fn ($id): int => (int) $id)->all();
}

test('adding branches keeps existing assignments and the rest of the account and is audited once', function () {
    $ids = $this->branches->pluck('id')->all();
    $before = $this->director->fresh()->only(['name', 'email', 'password', 'is_active', 'is_administrator']);
    $permissions = $this->director->getAllPermissions()->pluck('name')->sort()->values()->all();

    $this->artisan('app:director-authority', branchCommand('add-branches', $this->director->email, array_slice($ids, 3)))
        ->expectsOutputToContain('Current branches: '.$this->branches[0]->id.' '.$this->branches[0]->name)
        ->expectsOutputToContain('Adding: '.$this->branches[3]->id.' '.$this->branches[3]->name)
        ->expectsConfirmation('Add these branches for Director '.$this->director->name.'?', 'yes')
        ->assertSuccessful();

    $director = $this->director->fresh();
    $log = AuditLog::query()->where('event', 'approval_authority.director_branches_changed')->where('auditable_id', $director->id)->sole();
    expect(directorBranchIds($director))->toBe($ids)
        ->and($director->only(['name', 'email', 'password', 'is_active', 'is_administrator']))->toBe($before)
        ->and($director->getRoleNames()->all())->toBe([SystemRole::Director->value])
        ->and($director->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe($permissions)
        ->and(app(ApprovalAuthorityService::class)->holdsDirectorRole($director))->toBeTrue()
        ->and($log->actor_id)->toBeNull()
        ->and($log->created_at)->not->toBeNull()
        ->and($log->properties)->toMatchArray([
            'branch_ids_before' => array_slice($ids, 0, 3), 'branch_ids_after' => $ids, 'added' => array_slice($ids, 3), 'removed' => [],
            'reason' => 'Owner letter FRC-2026-09 extends Director coverage', 'database' => DB::connection()->getDatabaseName(), 'environment' => app()->environment(), 'authorized_by' => null,
        ])
        ->and($log->properties['source'])->toStartWith('console:');

    // Repeating the same command changes and records nothing, without asking for confirmation.
    $this->artisan('app:director-authority', branchCommand('add-branches', $this->director->email, array_slice($ids, 3)))
        ->expectsOutputToContain('Every named branch is already assigned. No change was made.')->assertSuccessful();
    expect(AuditLog::query()->where('event', 'approval_authority.director_branches_changed')->count())->toBe(1)
        ->and(DB::table('branch_user')->where('user_id', $director->id)->count())->toBe(6);
});

test('only branches not yet assigned are added and only assigned ones are removed', function () {
    $ids = $this->branches->pluck('id')->all();

    $this->artisan('app:director-authority', branchCommand('add-branches', $this->director->email, [$ids[2], $ids[3], $ids[3]]))
        ->expectsConfirmation('Add these branches for Director '.$this->director->name.'?', 'yes')->assertSuccessful();
    expect(directorBranchIds($this->director))->toBe(array_slice($ids, 0, 4))
        ->and(AuditLog::query()->where('event', 'approval_authority.director_branches_changed')->latest('id')->value('properties')['added'])->toBe([$ids[3]]);

    $this->artisan('app:director-authority', branchCommand('remove-branches', $this->director->email, [$ids[1], $ids[5]]))
        ->expectsOutputToContain('Removing: '.$ids[1].' '.$this->branches[1]->name)
        ->expectsConfirmation('Remove these branches for Director '.$this->director->name.'?', 'yes')->assertSuccessful();
    expect(directorBranchIds($this->director))->toBe([$ids[0], $ids[2], $ids[3]])
        ->and(AuditLog::query()->where('event', 'approval_authority.director_branches_changed')->latest('id')->value('properties'))->toMatchArray(['removed' => [$ids[1]], 'added' => [], 'branch_ids_after' => [$ids[0], $ids[2], $ids[3]]]);

    $this->artisan('app:director-authority', branchCommand('remove-branches', $this->director->email, [$ids[5]]))
        ->expectsOutputToContain('None of the named branches is assigned. No change was made.')->assertSuccessful();

    // A Director keeps at least one branch; removing them all is refused and changes nothing.
    $this->artisan('app:director-authority', branchCommand('remove-branches', $this->director->email, [$ids[0], $ids[2], $ids[3]]))
        ->expectsConfirmation('Remove these branches for Director '.$this->director->name.'?', 'yes')->assertFailed();
    expect(directorBranchIds($this->director))->toBe([$ids[0], $ids[2], $ids[3]])
        ->and(AuditLog::query()->where('event', 'approval_authority.director_branches_changed')->count())->toBe(2);
});

test('the command refuses wrong targets databases branches and unconfirmed runs', function () {
    $ids = $this->branches->pluck('id')->all();
    $approver = User::factory()->create();
    $approver->assignRole(SystemRole::Approver->value);
    $add = fn (array $overrides = [], ?array $branchIds = null) => branchCommand('add-branches', $this->director->email, $branchIds ?? [$ids[4]], $overrides);

    $this->artisan('app:director-authority', $add(['--database' => 'frc_accounting_software_other']))->assertFailed();
    $this->artisan('app:director-authority', $add(['--email' => 'nobody@example.test']))->assertFailed();
    $this->artisan('app:director-authority', branchCommand('add-branches', $approver->email, [$ids[4]]))->expectsOutputToContain('does not hold Director authority')->assertFailed();
    $this->artisan('app:director-authority', branchCommand('add-branches', $this->administrator->email, [$ids[4]]))->assertFailed();
    $this->artisan('app:director-authority', $add([], []))->assertFailed();
    $this->artisan('app:director-authority', $add([], [999999]))->assertFailed();
    $this->artisan('app:director-authority', $add([], [(string) $ids[4], 'abc']))->assertFailed();
    $this->artisan('app:director-authority', $add())->expectsConfirmation('Add these branches for Director '.$this->director->name.'?', 'no')->assertFailed();
    $this->artisan('app:director-authority', $add(['--reason' => 'short']))->expectsConfirmation('Add these branches for Director '.$this->director->name.'?', 'yes')->assertFailed();

    expect(directorBranchIds($this->director))->toBe(array_slice($ids, 0, 3))
        ->and($approver->branches()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'approval_authority.director_branches_changed')->exists())->toBeFalse();
});

test('with another director in office a change needs that director to authorize it and never the target', function () {
    $ids = $this->branches->pluck('id')->all();
    $other = User::factory()->create();
    $other->assignRole(SystemRole::Director->value);
    $approver = User::factory()->create();
    $approver->assignRole(SystemRole::Approver->value);
    $add = fn (array $overrides = []) => branchCommand('add-branches', $this->director->email, [$ids[4]], $overrides);

    $this->artisan('app:director-authority', $add())->assertFailed();
    $this->artisan('app:director-authority', $add(['--authorized-by' => $this->director->email]))->assertFailed();
    $this->artisan('app:director-authority', $add(['--authorized-by' => $approver->email]))->assertFailed();
    $this->artisan('app:director-authority', $add(['--authorized-by' => $this->administrator->email]))->assertFailed();
    expect(directorBranchIds($this->director))->toBe(array_slice($ids, 0, 3));

    $this->artisan('app:director-authority', $add(['--authorized-by' => $other->email]))
        ->expectsConfirmation('Add these branches for Director '.$this->director->name.'?', 'yes')->assertSuccessful();
    expect(directorBranchIds($this->director))->toBe([...array_slice($ids, 0, 3), $ids[4]])
        ->and(AuditLog::query()->where('event', 'approval_authority.director_branches_changed')->value('properties')['authorized_by'])->toBe($other->id);
});

test('administrator and the director cannot change director branches through the web', function () {
    $ids = $this->branches->pluck('id')->all();
    $form = ['name' => $this->director->name, 'email' => $this->director->email, 'password' => '', 'is_active' => '1', 'roles' => [SystemRole::Director->value], 'branches' => array_map('strval', $ids)];

    $this->actingAs($this->administrator)->get(route('users.edit', $this->director))->assertForbidden();
    $this->put(route('users.update', $this->director), $form)->assertForbidden();
    $this->patch(route('users.update', $this->director), $form)->assertForbidden();

    $this->actingAs($this->director)->put(route('users.update', $this->director), $form)->assertForbidden();
    $this->patch(route('profile.update'), ['name' => $this->director->name, 'email' => $this->director->email, 'branches' => $ids, 'branch_ids' => $ids])->assertSessionHasNoErrors();

    expect(directorBranchIds($this->director))->toBe(array_slice($ids, 0, 3))
        ->and(AuditLog::query()->where('event', 'approval_authority.director_branches_changed')->exists())->toBeFalse();
});
