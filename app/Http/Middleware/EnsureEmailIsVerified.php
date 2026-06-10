<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (!$request->user() ||
            ($request->user() instanceof MustVerifyEmail &&
             !$request->user()->hasVerifiedEmail())) {

            return $request->expectsJson()
                ? abort(403, 'Your email address is not verified.')
                : redirect()->guest(route('verification.notice'));
        }

        return $next($request);
    }
}
