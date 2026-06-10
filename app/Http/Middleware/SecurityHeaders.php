<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options',        'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy',        'strict-origin-when-cross-origin');
        $response->headers->set('X-XSS-Protection',       '1; mode=block');
        $response->headers->set('Permissions-Policy',     'camera=(), microphone=(), geolocation=()');

        $csp = implode('; ', [
            // Fallback for any directive not explicitly listed
            "default-src 'self'",
            // Scripts: own + inline (many Blade views) + reCAPTCHA + admin Tailwind CDN
            "script-src 'self' 'unsafe-inline' https://www.google.com https://www.gstatic.com https://cdn.tailwindcss.com",
            // Styles: own + inline + Font Awesome + Google Fonts + admin Tailwind CDN
            "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://cdn.tailwindcss.com",
            // Fonts: Font Awesome + Google Fonts
            "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com",
            // Images: own + base64 + Google Maps tiles
            "img-src 'self' data: https://maps.google.com https://maps.gstatic.com https://*.googleapis.com",
            // Frames: Google Maps embed (contact page) + reCAPTCHA
            "frame-src https://maps.google.com https://www.google.com",
            // AJAX: own routes only
            "connect-src 'self'",
            // Forms may only submit to own routes or Google OAuth
            "form-action 'self' https://accounts.google.com",
            // Blocks base-tag hijacking
            "base-uri 'self'",
            // No plugins (Flash, Java, etc.)
            "object-src 'none'",
            // Belt-and-suspenders: no framing from outside (mirrors X-Frame-Options)
            "frame-ancestors 'none'",
        ]);
        $response->headers->set('Content-Security-Policy', $csp);

        return $response;
    }
}
