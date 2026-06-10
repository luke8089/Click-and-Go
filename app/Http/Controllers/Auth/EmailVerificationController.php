<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function notice(Request $request)
    {
        if (auth()->user()->hasVerifiedEmail()) {
            return redirect()->route('account.dashboard');
        }

        // Auto-send once per session so the email is already in the inbox
        // when the user arrives here (fallback if the Registered event failed)
        if (!$request->session()->has('verify_email_sent')) {
            auth()->user()->sendEmailVerificationNotification();
            $request->session()->put('verify_email_sent', true);
        }

        return view('auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request)
    {
        $request->fulfill();

        return redirect()->intended(route('home'))
            ->with('success', 'Email verified! Welcome to Click & Go.');
    }

    public function resend(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('account.dashboard');
        }

        $request->user()->sendEmailVerificationNotification();
        $request->session()->forget('verify_email_sent');

        return back()->with('status', 'Verification link sent! Check your inbox.');
    }
}
