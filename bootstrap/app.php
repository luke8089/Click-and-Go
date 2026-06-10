<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$basePath = dirname(__DIR__);

$app = Application::configure(basePath: $basePath)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust only Cloudflare and HostPinnacle's internal nginx ranges.
        // Using '*' would let any attacker spoof X-Forwarded-For and bypass rate limits.
        // Cloudflare IPs: https://www.cloudflare.com/ips/
        $middleware->trustProxies(at: [
            // Cloudflare IPv4
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            // Cloudflare IPv6
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
            // HostPinnacle internal nginx (private/loopback ranges)
            '127.0.0.1/8',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'admin'          => \App\Http\Middleware\AdminMiddleware::class,
            'guest'          => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'mpesa.verify'   => \App\Http\Middleware\VerifyMpesaCallback::class,
            'verified'       => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'checkout'       => \App\Http\Middleware\CheckoutMiddleware::class,
        ]);

        // Daraja posts to this URL — exclude from CSRF verification
        $middleware->validateCsrfTokens(except: [
            'mpesa/callback',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 419 Token Mismatch — redirect gracefully instead of showing the ugly error page
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, $request) {
            $back = url()->previous();
            $home = url('/');

            // If the previous page was the login or register form, go back there with a message
            // For any other form, redirect back so the user doesn't lose their page context
            return redirect($back && $back !== url()->current() ? $back : $home)
                ->with('error', 'Your session expired — please try again.');
        });
    })->create();

// On HostPinnacle the public files live in public_html/, not clickandgo/public/
if (is_dir($basePath . '/../public_html')) {
    $app->usePublicPath(realpath($basePath . '/../public_html'));
}

return $app;
