<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        $isActiveUser = User::query()
            ->where('email', $request->string('email')->toString())
            ->where('is_active', true)
            ->exists();

        if ($isActiveUser) {
            Password::sendResetLink($request->only('email'));
        }

        return back()->with('status', 'If an active account exists for that email, a password reset link has been sent.');
    }
}
