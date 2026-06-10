<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Services\RecaptchaService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function showRegistrationForm()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        // Honeypot
        if ($request->filled('company_name')) {
            return redirect()->route('home');
        }

        // Per-email+IP limit: 5 attempts per email per IP per hour
        // Two users on the same network registering different emails are never affected by each other
        $emailKey = 'register|email|' . strtolower(trim($request->input('email', ''))) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($emailKey, 5)) {
            return back()
                ->withInput()
                ->withErrors(['email' => 'Too many attempts for this email. Please try again later.']);
        }

        // Loose IP-only ceiling (30/hour) to stop bots — high enough that a whole office is never blocked
        $ipKey = 'register|ip|' . $request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 30)) {
            return back()
                ->withInput()
                ->withErrors(['email' => 'Too many registration attempts from your network. Please try again later.']);
        }

        $request->validate([
            'name'     => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\p{L}\s\'\-\.]+$/u'],
            'email'    => ['required', 'email:rfc', 'max:150'],
            'phone'    => ['nullable', 'string', 'max:20', 'regex:/^[+\d][\d\s\-\(\)]{5,18}\d$/'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'terms'    => ['accepted'],
        ], [
            'name.regex'  => 'Name may only contain letters, spaces, hyphens, and apostrophes.',
            'phone.regex' => 'Please enter a valid phone number.',
            'password'    => 'Password must be at least 8 characters and include uppercase, lowercase, and a number.',
            'terms'       => 'You must accept the Terms & Conditions and Privacy Policy to create an account.',
        ]);

        RateLimiter::hit($emailKey, 3600);
        RateLimiter::hit($ipKey, 3600);

        if (!RecaptchaService::passes($request, 'register')) {
            return back()->withInput()->withErrors(['email' => 'We detected unusual activity. Please refresh the page and try again.']);
        }

        // Check for duplicate email with clear, actionable feedback
        $email        = strtolower(trim($request->email));
        $existingUser = User::where('email', $email)->first();

        if ($existingUser) {
            if ($existingUser->google_id && !$existingUser->password) {
                // Google-only account — guide them to use Google sign-in
                return back()
                    ->withInput($request->except('password', 'password_confirmation'))
                    ->withErrors(['email' => 'This email is linked to a Google account. Use "Continue with Google" below to sign in.']);
            }

            // Manual account already exists
            return back()
                ->withInput($request->except('password', 'password_confirmation'))
                ->withErrors(['email' => 'An account with this email already exists.']);
        }

        $user = User::create([
            'name'     => strip_tags(trim($request->name)),
            'email'    => strtolower(trim($request->email)),
            'phone'    => $request->phone ? preg_replace('/[^\+\d\s\-\(\)]/', '', $request->phone) : null,
            'password' => Hash::make($request->password),
        ]);

        $user->role = 'customer';
        $user->save();

        // Link a guest order to this new account if one was placed before registering
        if ($prefill = session('guest_order_prefill')) {
            if (!empty($prefill['order_id'])) {
                Order::where('id', $prefill['order_id'])
                     ->whereNull('user_id')
                     ->update(['user_id' => $user->id]);
            }
            session()->forget('guest_order_prefill');
        }

        try {
            event(new Registered($user));
        } catch (\Exception) {
            // Mail failure must never break registration — user can resend from verify page
        }

        return redirect()->route('login')->with('success', 'Account created! Please check your inbox to verify your email before signing in.');
    }
}
