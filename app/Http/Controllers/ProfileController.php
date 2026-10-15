<?php

namespace App\Http\Controllers;

use App\Http\Requests\PasswordUpdateRequest;
use App\Http\Requests\ProfileUpdateRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('profile.edit');
    }

    public function update(ProfileUpdateRequest $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $changed = array_keys($user->getDirty());
        $user->save();
        if ($changed !== []) {
            $audit->log('profile.updated', $user, ['fields' => $changed]);
        }

        return back()->with('status', 'Profile updated successfully.');
    }

    public function updatePassword(PasswordUpdateRequest $request, AuditLogger $audit): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->validated('password'),
            'password_changed_at' => now(),
        ]);
        $audit->log('profile.password_changed', $request->user());

        return back()->with('status', 'Password changed successfully.');
    }
}
