<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('profile access is protected', function () {
    $this->get(route('profile.edit'))->assertRedirect(route('login'));
});

test('active users can view and update their profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Accounting Administrator',
        'email' => 'accounts@example.test',
    ])->assertSessionHas('status');

    expect($user->fresh()->name)->toBe('Accounting Administrator')
        ->and($user->fresh()->email)->toBe('accounts@example.test');

    $audit = AuditLog::query()->where('event', 'profile.updated')->sole();
    expect($audit->actor_id)->toBe($user->id)
        ->and($audit->properties['fields'])->toContain('name')->toContain('email');
});

test('active users can change their password', function () {
    $user = User::factory()->create(['password' => 'OldValidPassword123!']);

    $this->actingAs($user)->put(route('profile.password.update'), [
        'current_password' => 'OldValidPassword123!',
        'password' => 'NewValidPassword123!',
        'password_confirmation' => 'NewValidPassword123!',
    ])->assertSessionHas('status');

    expect(Hash::check('NewValidPassword123!', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->password_changed_at)->not->toBeNull();

    $audit = AuditLog::query()->where('event', 'profile.password_changed')->sole();
    expect($audit->actor_id)->toBe($user->id)
        ->and(json_encode($audit->properties))->not->toContain('NewValidPassword123!');
});
