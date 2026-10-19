<?php

use App\Enums\SystemRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\ApprovalAuthorityService;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->otherBranch = Branch::factory()->create();
    $this->administrator = protectionUser(SystemRole::Administrator, $this->branch);
    $this->administrator->branches()->attach($this->otherBranch);
    $this->director = protectionUser(SystemRole::Director, $this->branch);
});

function protectionUser(SystemRole $role, Branch $branch): User
{
    $user = User::factory()->create();
    $user->assignRole($role->value);
    $user->branches()->attach($branch);

    return $user;
}

/** A complete user-management form that would change every field of the Director account. */
function directorTakeoverForm(User $director, Branch $branch): array
{
    return [
        'name' => 'Taken Over', 'email' => 'attacker@example.test', 'password' => 'TakeoverPassword123!', 'password_confirmation' => 'TakeoverPassword123!',
        'is_active' => '0', 'roles' => [SystemRole::Approver->value], 'branches' => [(string) $branch->id], 'authority_reason' => 'Administrator takeover attempt',
    ];
}

test('administrator lists and views a director read only without an edit action', function () {
    $accountant = protectionUser(SystemRole::Accountant, $this->branch);
    $this->actingAs($this->administrator);

    $list = $this->get(route('users.index'))->assertOk()->assertSee($this->director->name)->getContent();
    expect($list)->toContain(route('users.show', $this->director))
        ->not->toContain(route('users.edit', $this->director))
        ->toContain(route('users.edit', $accountant));

    $this->get(route('users.show', $this->director))->assertOk()->assertSee('Director account — read-only')->assertDontSee(route('users.edit', $this->director), false);
    $this->get(route('users.show', $accountant))->assertOk()->assertSee(route('users.edit', $accountant), false);
});

test('administrator cannot open or submit any change to a director account', function () {
    $before = $this->director->fresh()->only(['name', 'email', 'password', 'is_active']);
    $this->actingAs($this->administrator)->get(route('users.edit', $this->director))->assertForbidden();

    $full = directorTakeoverForm($this->director, $this->otherBranch);
    $unchanged = ['name' => $this->director->name, 'email' => $this->director->email, 'password' => '', 'is_active' => '1', 'roles' => [SystemRole::Director->value], 'branches' => [(string) $this->branch->id]];
    $attempts = [
        'everything' => $full,
        'name' => [...$unchanged, 'name' => 'Renamed'],
        'email' => [...$unchanged, 'email' => 'attacker@example.test'],
        'password' => [...$unchanged, 'password' => 'TakeoverPassword123!', 'password_confirmation' => 'TakeoverPassword123!'],
        'active status' => [...$unchanged, 'is_active' => '0'],
        'branches' => [...$unchanged, 'branches' => [(string) $this->branch->id, (string) $this->otherBranch->id]],
        'roles' => [...$unchanged, 'roles' => [SystemRole::Approver->value], 'authority_reason' => 'Removing Director authority'],
    ];
    foreach ($attempts as $case => $form) {
        $this->put(route('users.update', $this->director), $form)->assertForbidden();
        $this->patch(route('users.update', $this->director), $form)->assertForbidden();
    }

    $director = $this->director->fresh();
    expect($director->only(['name', 'email', 'password', 'is_active']))->toBe($before)
        ->and($director->getRoleNames()->all())->toBe([SystemRole::Director->value])
        ->and($director->branches()->pluck('branches.id')->all())->toBe([$this->branch->id])
        ->and(app(ApprovalAuthorityService::class)->holdsDirectorRole($director))->toBeTrue()
        ->and(AuditLog::query()->where('auditable_id', $director->id)->whereIn('event', ['user.access_updated', 'approval_authority.director_revoked'])->exists())->toBeFalse();
});

test('profile and password endpoints only ever change the signed in user', function () {
    $directorEmail = $this->director->email;
    $directorPassword = $this->director->password;

    $this->actingAs($this->administrator)->patch(route('profile.update'), ['name' => 'Admin Renamed', 'email' => $directorEmail, 'id' => $this->director->id])->assertSessionHasErrors('email');
    $this->patch(route('profile.update'), ['name' => 'Admin Renamed', 'email' => $this->administrator->email, 'id' => $this->director->id, 'is_active' => '0'])->assertSessionHasNoErrors();
    $this->put(route('profile.password.update'), ['current_password' => 'password', 'password' => 'NewAdminPassword123!', 'password_confirmation' => 'NewAdminPassword123!', 'id' => $this->director->id]);
    // A reset needs the emailed token, which an administrator never receives for a Director.
    $this->post(route('password.update'), ['token' => 'forged-token', 'email' => $directorEmail, 'password' => 'TakeoverPassword123!', 'password_confirmation' => 'TakeoverPassword123!']);

    expect($this->director->fresh()->password)->toBe($directorPassword)
        ->and($this->director->fresh()->email)->toBe($directorEmail)
        ->and($this->administrator->fresh()->name)->toBe('Admin Renamed')
        ->and($this->administrator->fresh()->is_active)->toBeTrue();
});

test('a director changes only their own name and email through the profile and cannot escalate', function () {
    $this->actingAs($this->director)->get(route('users.edit', $this->director))->assertForbidden();
    $this->put(route('users.update', $this->director), [...directorTakeoverForm($this->director, $this->otherBranch), 'roles' => [SystemRole::Director->value, SystemRole::Administrator->value]])->assertForbidden();

    $this->patch(route('profile.update'), ['name' => 'Director Renamed', 'email' => 'director.new@example.test', 'roles' => [SystemRole::Administrator->value], 'is_administrator' => '1'])->assertSessionHasNoErrors();
    $director = $this->director->fresh();
    expect($director->name)->toBe('Director Renamed')->and($director->email)->toBe('director.new@example.test')
        ->and($director->getRoleNames()->all())->toBe([SystemRole::Director->value])
        ->and($director->is_administrator)->toBeFalse()
        ->and($director->branches()->pluck('branches.id')->all())->toBe([$this->branch->id]);
});

test('an authorized director manages another director through user management with an audited reason', function () {
    $appointer = protectionUser(SystemRole::Director, $this->branch);
    $appointer->givePermissionTo(['users.update', 'users.assign_roles', 'branches.assign_users']);
    $form = ['name' => 'Updated Director', 'email' => $this->director->email, 'password' => '', 'is_active' => '1', 'roles' => [SystemRole::Director->value], 'branches' => [(string) $this->branch->id]];

    $this->actingAs($appointer)->get(route('users.edit', $this->director))->assertOk()->assertSee('name="authority_reason"', false);
    $this->put(route('users.update', $this->director), $form)->assertRedirect()->assertSessionHasNoErrors();
    expect($this->director->fresh()->name)->toBe('Updated Director');

    $this->put(route('users.update', $this->director), [...$form, 'roles' => [SystemRole::Approver->value]])->assertSessionHasErrors('authority_reason');
    $this->put(route('users.update', $this->director), [...$form, 'roles' => [SystemRole::Approver->value], 'password' => 'NewDirectorPass123!', 'password_confirmation' => 'NewDirectorPass123!', 'authority_reason' => 'Board minute 14 ends the Director term'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $log = AuditLog::query()->where('event', 'approval_authority.director_revoked')->where('auditable_id', $this->director->id)->firstOrFail();
    $logged = AuditLog::query()->where('auditable_id', $this->director->id)->get()->map(fn (AuditLog $entry) => json_encode($entry->properties))->implode(' ');
    expect($log->properties['reason'])->toBe('Board minute 14 ends the Director term')
        ->and($this->director->fresh()->hasRole(SystemRole::Director->value))->toBeFalse()
        ->and($logged)->not->toContain('NewDirectorPass123!')->not->toContain('password_confirmation');

    // A Director never edits their own account through user management.
    $this->get(route('users.edit', $appointer))->assertForbidden();
});
