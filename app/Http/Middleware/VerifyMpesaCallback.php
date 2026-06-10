<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block M-Pesa callback requests that do not originate from Safaricom's
 * known IP ranges. Anyone who can reach the callback URL can otherwise
 * POST a fake "payment succeeded" payload and mark an order as paid.
 *
 * Production Safaricom Daraja callback IPs (verified from Safaricom docs):
 *   196.201.214.0/24
 *   196.201.215.0/24
 *
 * Set MPESA_VERIFY_IP=false in .env to disable enforcement during local
 * development (sandbox callbacks come from localhost).
 */
class VerifyMpesaCallback
{
    /**
     * Safaricom production callback IP ranges (CIDR notation).
     * Also accepts exact IPs for the sandbox testing server.
     */
    private const ALLOWED_CIDRS = [
        '196.201.214.0/24',
        '196.201.215.0/24',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Allow bypass only outside production (e.g. local sandbox testing).
        // On production the check always runs — MPESA_VERIFY_IP=false is ignored.
        if (!app()->isProduction() && !config('mpesa.verify_ip', true)) {
            return $next($request);
        }

        $ip = $request->ip();

        if (!$this->isAllowed($ip)) {
            Log::warning('M-Pesa callback blocked: IP not in Safaricom allowlist', [
                'ip'     => $ip,
                'method' => $request->method(),
                'url'    => $request->fullUrl(),
                'body'   => $request->all(),
            ]);

            return response()->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    private function isAllowed(string $ip): bool
    {
        foreach (self::ALLOWED_CIDRS as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns true when $ip falls inside $cidr (supports both IPv4 CIDR
     * blocks like 196.201.214.0/24 and exact IPs like 127.0.0.1).
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);

        $ipLong     = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bits == 0 ? 0 : (~0 << (32 - (int) $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
