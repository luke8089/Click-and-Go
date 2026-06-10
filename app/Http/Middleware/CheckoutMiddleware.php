<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckoutMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()) {
            // Authenticated but email not verified — send to verification notice
            if ($request->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail
                && !$request->user()->hasVerifiedEmail()) {
                return redirect()->route('verification.notice');
            }
            // Authenticated + verified — allow through
            return $next($request);
        }

        // Guest — only allow through if they explicitly chose "Continue as Guest"
        if (session('guest_checkout')) {
            return $next($request);
        }

        // No session flag — send to gate page
        return redirect()->route('checkout.gate');
    }
}
