<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\RecaptchaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function showForm()
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email', 'max:150']]);

        // Bot check — same response on fail to avoid enumeration leak
        if (!RecaptchaService::passes($request, 'forgot_password')) {
            return back()->with('status', 'If that email address is registered, a password reset link is on its way. Check your inbox (and spam folder).');
        }

        // Send only if email exists, but always show the same message to prevent enumeration
        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Exception) {
            // Mail failure is silent — same response regardless
        }

        return back()->with('status', 'If that email address is registered, a password reset link is on its way. Check your inbox (and spam folder).');
    }
}
