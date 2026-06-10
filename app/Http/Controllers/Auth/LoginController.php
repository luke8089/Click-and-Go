<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Mail\LoginAlertMail;
use App\Services\RecaptchaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        // Honeypot — bots fill hidden fields
        if ($request->filled('company_name')) {
            Log::warning('Login honeypot triggered', ['ip' => $request->ip()]);
            return redirect()->route('home');
        }

        $request->validate([
            'email'    => 'required|email|max:150',
            'password' => 'required|string',
        ]);

        if (!RecaptchaService::passes($request, 'login')) {
            Log::warning('Login blocked by reCAPTCHA', ['ip' => $request->ip()]);
            throw ValidationException::withMessages([
                'email' => 'We detected unusual activity. Please refresh the page and try again.',
            ]);
        }

        // Per-email-and-IP rate limit: 5 attempts per 15 minutes
        $throttleKey = Str::lower($request->input('email')) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $minutes = (int) ceil($seconds / 60);
            Log::warning('Login account locked out', [
                'email' => $request->input('email'),
                'ip'    => $request->ip(),
            ]);
            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please wait {$minutes} minute(s) and try again.",
            ]);
        }

        $guestSessionId = session()->getId();

        if (Auth::attempt(
            ['email' => $request->input('email'), 'password' => $request->input('password')],
            $request->boolean('remember')
        )) {
            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();

            // Send login alert email (fire-and-forget, don't break login on failure)
            try {
                Mail::to(Auth::user()->email)->send(new LoginAlertMail(
                    user:      Auth::user(),
                    ip:        $request->ip(),
                    userAgent: $request->userAgent() ?? 'Unknown',
                    time:      now()->format('M d, Y \a\t h:i A'),
                ));
            } catch (\Exception) {}

            if (Auth::user()->isAdmin()) {
                return redirect()->route('admin.dashboard');
            }

            // Merge guest cart into authenticated user's cart
            $sessionItems = CartItem::where('session_id', $guestSessionId)->get();
            foreach ($sessionItems as $item) {
                $userItem = CartItem::firstOrNew([
                    'user_id'    => Auth::id(),
                    'product_id' => $item->product_id,
                ]);
                $userItem->quantity = ($userItem->quantity ?? 0) + $item->quantity;
                $userItem->save();
                $item->delete();
            }

            // Link a guest order to this account if one was placed before logging in
            if ($prefill = session('guest_order_prefill')) {
                if (!empty($prefill['order_id'])) {
                    Order::where('id', $prefill['order_id'])
                         ->whereNull('user_id')
                         ->update(['user_id' => Auth::id()]);
                }
                session()->forget('guest_order_prefill');
                return redirect()->route('account.orders.show', $prefill['order_id'])
                                 ->with('success', 'Your guest order has been linked to your account. You can now track it here.');
            }

            return redirect()->intended(route('home'));
        }

        RateLimiter::hit($throttleKey, 900); // 15-minute window
        $remaining = 5 - RateLimiter::attempts($throttleKey);

        Log::warning('Failed login attempt', [
            'email'     => $request->input('email'),
            'ip'        => $request->ip(),
            'remaining' => max(0, $remaining),
        ]);

        throw ValidationException::withMessages([
            'email' => 'These credentials do not match our records.'
                . ($remaining > 0 && $remaining <= 3 ? " ({$remaining} attempt(s) left before lockout)" : ''),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    }
}
