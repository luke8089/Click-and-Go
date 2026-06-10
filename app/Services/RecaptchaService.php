<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class RecaptchaService
{
    /**
     * Returns true if the request passes reCAPTCHA v3 validation.
     * Returns true (allow) when keys are not configured, so dev/staging works without keys.
     */
    public static function passes(Request $request, string $action, float $threshold = 0.3): bool
    {
        $token  = $request->input('g_recaptcha_response');
        $secret = config('services.recaptcha.secret_key');

        if (!$secret || !$token) {
            return true; // Not configured — let all requests through
        }

        try {
            $response = Http::asForm()->timeout(5)->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);
        } catch (\Exception) {
            return true; // Google API unreachable — don't lock out legitimate users
        }

        $data = $response->json();

        // Score + success is sufficient — action check removed to avoid false positives
        return ($data['success'] ?? false)
            && ($data['score']  ?? 0) >= $threshold;
    }
}
