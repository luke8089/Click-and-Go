<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MpesaService
{
    private string $consumerKey;
    private string $consumerSecret;
    private string $shortcode;
    private string $passkey;
    private string $callbackUrl;
    private string $tokenUrl;
    private string $stkPushUrl;
    private string $stkQueryUrl;

    public function __construct()
    {
        $base = config('mpesa.base_url');

        $this->consumerKey   = config('mpesa.consumer_key', '');
        $this->consumerSecret= config('mpesa.consumer_secret', '');
        $this->shortcode     = config('mpesa.shortcode', '174379');
        $this->passkey       = config('mpesa.passkey', '');
        $this->callbackUrl   = config('mpesa.callback_url', '');
        $this->tokenUrl      = $base . '/oauth/v1/generate?grant_type=client_credentials';
        $this->stkPushUrl    = $base . '/mpesa/stkpush/v1/processrequest';
        $this->stkQueryUrl   = $base . '/mpesa/stkpushquery/v1/query';
    }

    // ── Access token via cURL (matches the reference directorate implementation) ──

    public function getAccessToken(): ?string
    {
        if (empty($this->consumerKey) || empty($this->consumerSecret)) {
            Log::error('M-Pesa: MPESA_CONSUMER_KEY or MPESA_CONSUMER_SECRET not set in .env');
            return null;
        }

        $cacheKey = 'mpesa_access_token_' . md5($this->consumerKey);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $verifySsl = app()->isProduction();

        $ch = curl_init($this->tokenUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER,    ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER,         false);
        curl_setopt($ch, CURLOPT_USERPWD,        $this->consumerKey . ':' . $this->consumerSecret);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT,        15);

        $result = curl_exec($ch);
        $err    = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            Log::error('M-Pesa token cURL error', ['error' => $err]);
            return null;
        }

        $data = json_decode($result);

        if (isset($data->access_token)) {
            // Daraja tokens last 3600 s — cache for 55 minutes to avoid expiry at the boundary
            Cache::put($cacheKey, $data->access_token, now()->addMinutes(55));
            return $data->access_token;
        }

        Log::error('M-Pesa token failed', ['status' => $status, 'body' => $result]);
        return null;
    }

    // ── STK Push ──────────────────────────────────────────────────────────────────

    public function stkPush(string $phone, float $amount, int $orderId, string $description = 'Payment'): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return ['success' => false, 'message' => 'Could not authenticate with M-Pesa. Check your consumer key and secret.'];
        }

        $timestamp = date('YmdHis');
        $password  = base64_encode($this->shortcode . $this->passkey . $timestamp);
        $phone     = $this->formatPhone($phone);

        $payload = json_encode([
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => (int) ceil($amount),
            'PartyA'            => $phone,
            'PartyB'            => $this->shortcode,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $this->callbackUrl,
            'AccountReference'  => 'ORD-' . $orderId,
            'TransactionDesc'   => substr(str_replace(['&', '<', '>'], [' ', '', ''], $description), 0, 13),
        ]);

        $verifySsl = app()->isProduction();

        $ch = curl_init($this->stkPushUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);
        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT,        20);

        $result = curl_exec($ch);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            Log::error('M-Pesa STK cURL error', ['error' => $err, 'order_id' => $orderId]);
            return ['success' => false, 'message' => 'Connection to M-Pesa failed: ' . $err];
        }

        $response = json_decode($result, true);
        Log::info('M-Pesa STK push', ['order_id' => $orderId, 'phone' => $phone, 'response' => $response]);

        if (isset($response['ResponseCode']) && $response['ResponseCode'] === '0') {
            return [
                'success'             => true,
                'checkout_request_id' => $response['CheckoutRequestID'],
                'merchant_request_id' => $response['MerchantRequestID'],
                'response'            => $response,
            ];
        }

        return [
            'success'  => false,
            'message'  => $response['errorMessage'] ?? ($response['ResponseDescription'] ?? 'STK push failed'),
            'response' => $response,
        ];
    }

    // ── STK Query (check payment status manually) ─────────────────────────────────

    public function stkQuery(string $checkoutRequestId): array
    {
        $token = $this->getAccessToken();

        if (!$token) {
            return ['success' => false, 'message' => 'Could not authenticate with M-Pesa'];
        }

        $timestamp = date('YmdHis');
        $password  = base64_encode($this->shortcode . $this->passkey . $timestamp);

        $payload = json_encode([
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]);

        $verifySsl = app()->isProduction();

        $ch = curl_init($this->stkQueryUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);
        curl_setopt($ch, CURLOPT_POST,           true);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT,        15);

        $result = curl_exec($ch);
        curl_close($ch);

        return json_decode($result, true) ?? [];
    }

    // ── Phone normalisation: any Kenyan format → 254XXXXXXXXX ────────────────────

    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (strlen($phone) === 10 && str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }

        if (str_starts_with($phone, '254')) {
            return $phone;
        }

        return '254' . $phone;
    }
}
