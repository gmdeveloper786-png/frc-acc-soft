<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('an active user can reset their password', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'NewValidPassword123!',
            'password_confirmation' => 'NewValidPassword123!',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        return true;
    });

    expect(Hash::check('NewValidPassword123!', $user->fresh()->password))->toBeTrue();
});

test('inactive users are not sent password reset links', function () {
    Notification::fake();
    $user = User::factory()->inactive()->create();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHas('status');

    Notification::assertNothingSent();
});
