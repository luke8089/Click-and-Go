<?php

namespace App\Http\Controllers;

use App\Mail\PaymentConfirmationMail;
use App\Models\MpesaTransaction;
use App\Models\Order;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class MpesaController extends Controller
{
    /**
     * Daraja calls this endpoint with the payment result.
     * Route must be public and excluded from CSRF.
     */
    public function callback(Request $request)
    {
        $body = $request->all();
        $stk  = $body['Body']['stkCallback'] ?? null;

        // Log only non-PII fields — never log phone numbers, receipt numbers, or amounts
        Log::info('M-Pesa callback received', [
            'checkout_request_id' => $stk['CheckoutRequestID'] ?? 'unknown',
            'merchant_request_id' => $stk['MerchantRequestID'] ?? 'unknown',
            'result_code'         => $stk['ResultCode']        ?? 'unknown',
            'result_desc'         => $stk['ResultDesc']        ?? 'unknown',
        ]);

        if (!$stk) {
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }

        $checkoutRequestId = $stk['CheckoutRequestID'];
        $resultCode        = (string) $stk['ResultCode'];
        $resultDesc        = $stk['ResultDesc'] ?? '';

        $transaction = MpesaTransaction::where('checkout_request_id', $checkoutRequestId)->first();

        if (!$transaction) {
            Log::warning('M-Pesa callback: no matching transaction', compact('checkoutRequestId'));
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }

        if ($resultCode === '0') {
            $items   = collect($stk['CallbackMetadata']['Item'] ?? []);
            $receipt = $items->firstWhere('Name', 'MpesaReceiptNumber')['Value'] ?? null;

            $transaction->update([
                'result_code'          => $resultCode,
                'result_desc'          => $resultDesc,
                'mpesa_receipt_number' => $receipt,
                'status'               => 'completed',
                'raw_callback'         => $body,
            ]);

            $wasAlreadyPaid = $transaction->order->payment_status === 'paid';
            $transaction->order->update([
                'payment_status'       => 'paid',
                'status'               => 'processing',
                'mpesa_transaction_id' => $receipt,
            ]);

            if (!$wasAlreadyPaid && $transaction->order->shipping_email) {
                try {
                    Mail::to($transaction->order->shipping_email)
                        ->send(new PaymentConfirmationMail($transaction->order->fresh()));
                } catch (\Exception) {}
            }
        } else {
            $transaction->update([
                'result_code'  => $resultCode,
                'result_desc'  => $resultDesc,
                'status'       => 'failed',
                'raw_callback' => $body,
            ]);

            $transaction->order->update(['payment_status' => 'failed']);
        }

        // Always acknowledge to Daraja
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    /**
     * Resend STK push for an order whose payment is still pending.
     */
    public function resend(Order $order)
    {
        if (auth()->check()) {
            if ($order->user_id !== auth()->id()) abort(403);
        } else {
            if ($order->user_id !== null) abort(403);
        }

        // Rate limit: 5 resend attempts per order per minute
        $rateLimitKey = 'mpesa-resend:' . $order->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return response()->json(['success' => false, 'message' => 'Too many resend attempts. Please wait a minute before trying again.']);
        }
        RateLimiter::hit($rateLimitKey, 60);

        $order = $order->fresh();

        if ($order->payment_status === 'paid') {
            return response()->json(['success' => false, 'paid' => true, 'message' => 'Payment already completed. Redirecting…']);
        }

        $lastTx = $order->mpesaTransactions()->latest()->first();
        $phone  = $lastTx?->phone;

        if (!$phone) {
            return response()->json(['success' => false, 'message' => 'We could not find a phone number for this order. Please contact support.']);
        }

        // Guard against double-charging: if a pending transaction is < 60 s old,
        // query M-Pesa first to confirm its actual state before sending a new prompt.
        if ($lastTx->status === 'pending' && $lastTx->checkout_request_id
            && $lastTx->created_at->diffInSeconds(now()) < 60
        ) {
            $mpesa        = new MpesaService();
            $qResult      = $mpesa->stkQuery($lastTx->checkout_request_id);
            $responseCode = (string) ($qResult['ResponseCode'] ?? '');
            $resultCode   = isset($qResult['ResultCode']) ? (string) $qResult['ResultCode'] : null;

            $terminalCodes = ['1', '1032', '1037', '2001', '17'];

            if ($responseCode === '0' && $resultCode === '0') {
                $items   = collect($qResult['CallbackMetadata']['Item'] ?? []);
                $receipt = $items->firstWhere('Name', 'MpesaReceiptNumber')['Value'] ?? null;

                $lastTx->update([
                    'result_code'          => '0',
                    'result_desc'          => $qResult['ResultDesc'] ?? 'Success',
                    'mpesa_receipt_number' => $receipt,
                    'status'               => 'completed',
                ]);

                $wasAlreadyPaid = $order->payment_status === 'paid';
                $order->update([
                    'payment_status'       => 'paid',
                    'status'               => 'processing',
                    'mpesa_transaction_id' => $receipt,
                ]);

                if (!$wasAlreadyPaid && $order->shipping_email) {
                    try {
                        Mail::to($order->shipping_email)->send(new PaymentConfirmationMail($order->fresh()));
                    } catch (\Exception) {}
                }

                return response()->json(['success' => false, 'paid' => true, 'message' => 'Payment already completed. Redirecting…']);
            }

            if ($responseCode === '0' && in_array($resultCode, $terminalCodes, true)) {
                // Confirmed terminal failure — fall through to send a new prompt.
            } else {
                return response()->json(['success' => false, 'message' => 'A payment is already in progress. Please wait a moment before trying again.']);
            }
        }

        $mpesa  = new MpesaService();
        $result = $mpesa->stkPush($phone, $order->total, $order->id, 'Click Go Order');

        MpesaTransaction::create([
            'order_id'            => $order->id,
            'phone'               => $phone,
            'amount'              => $order->total,
            'checkout_request_id' => $result['checkout_request_id'] ?? null,
            'merchant_request_id' => $result['merchant_request_id'] ?? null,
            'status'              => $result['success'] ? 'pending' : 'failed',
        ]);

        if ($result['success']) {
            $order->update(['payment_status' => 'pending']);
        }

        $failMessage = match(true) {
            str_contains($result['message'] ?? '', 'authenticate') => 'Unable to reach M-Pesa right now. Please try again in a moment.',
            str_contains($result['message'] ?? '', 'Connection')   => 'Connection to M-Pesa failed. Please check your network and try again.',
            default => 'Failed to send the payment prompt. Please try again.',
        };

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success'] ? 'Payment prompt sent! Check your phone.' : $failMessage,
        ]);
    }

    /**
     * Frontend polls this to check if payment landed.
     * Actively queries M-Pesa (STK Query) so it works even when the
     * callback URL cannot be reached (e.g. local / XAMPP development).
     */
    public function status(Order $order)
    {
        if (auth()->check()) {
            if ($order->user_id !== auth()->id()) abort(403);
        } else {
            if ($order->user_id !== null) abort(403);
        }

        $order = $order->fresh();
        $tx    = $order->mpesaTransactions()->latest()->first();

        // Only query M-Pesa when payment is still pending and we have a checkout request ID.
        // Rate-limited to once every 10 seconds per order to avoid Daraja blocking.
        $queryCacheKey = 'mpesa_query_' . $order->id;
        if ($order->payment_status === 'pending' && $tx?->checkout_request_id && !Cache::has($queryCacheKey)) {
            Cache::put($queryCacheKey, true, now()->addSeconds(10));

            $mpesa        = new MpesaService();
            $result       = $mpesa->stkQuery($tx->checkout_request_id);
            $responseCode = (string) ($result['ResponseCode'] ?? '');
            $resultCode   = isset($result['ResultCode']) ? (string) $result['ResultCode'] : null;

            if ($responseCode === '0' && $resultCode === '0') {
                // STK Query confirmed payment. Receipt only arrives via callback webhook;
                // it won't be present here, so we mark paid on ResultCode alone.
                $items   = collect($result['CallbackMetadata']['Item'] ?? []);
                $receipt = $items->firstWhere('Name', 'MpesaReceiptNumber')['Value'] ?? null;

                $tx->update([
                    'result_code'          => '0',
                    'result_desc'          => $result['ResultDesc'] ?? 'Success',
                    'mpesa_receipt_number' => $receipt,
                    'status'               => 'completed',
                ]);

                $wasAlreadyPaid = $order->payment_status === 'paid';
                $order->update([
                    'payment_status'       => 'paid',
                    'status'               => 'processing',
                    'mpesa_transaction_id' => $receipt,
                ]);

                $order->refresh();
                $tx->refresh();

                if (!$wasAlreadyPaid && $order->shipping_email) {
                    try {
                        Mail::to($order->shipping_email)->send(new PaymentConfirmationMail($order));
                    } catch (\Exception) {}
                }

            } elseif ($responseCode === '0' && $resultCode !== null && $resultCode !== '0') {
                $terminalCodes = ['1', '1032', '1037', '2001', '17'];

                if (in_array($resultCode, $terminalCodes, true)) {
                    ['title' => $title, 'desc' => $desc] = $this->friendlyFailureMessage($resultCode);

                    $tx->update([
                        'result_code' => $resultCode,
                        'result_desc' => $desc,
                        'status'      => 'failed',
                    ]);

                    $order->update(['payment_status' => 'failed']);
                    $order->refresh();
                    $tx->refresh();
                }
            }
        }

        $failTitle = ($order->payment_status === 'failed' && $tx?->result_code)
            ? $this->friendlyFailureMessage($tx->result_code)['title']
            : null;

        return response()->json([
            'payment_status' => $order->payment_status,
            'order_status'   => $order->status,
            'receipt'        => $tx?->mpesa_receipt_number,
            'result_title'   => $failTitle,
            'result_desc'    => $tx?->result_desc,
        ]);
    }

    private function friendlyFailureMessage(string $code): array
    {
        return match ($code) {
            '1032' => [
                'title' => 'Payment Cancelled',
                'desc'  => 'You cancelled the M-Pesa prompt. Tap Resend Prompt to try again.',
            ],
            '1' => [
                'title' => 'Insufficient Balance',
                'desc'  => 'Your M-Pesa balance is too low. Please top up and tap Resend Prompt.',
            ],
            '2001' => [
                'title' => 'Wrong PIN',
                'desc'  => 'Incorrect M-Pesa PIN entered. Tap Resend Prompt to try again.',
            ],
            '1037' => [
                'title' => 'Request Expired',
                'desc'  => 'The payment prompt expired before you responded. Tap Resend Prompt.',
            ],
            '17' => [
                'title' => 'Transaction Declined',
                'desc'  => 'M-Pesa declined this transaction. Please try again.',
            ],
            default => [
                'title' => 'Payment Failed',
                'desc'  => 'Something went wrong with the payment. Tap Resend Prompt to try again.',
            ],
        };
    }
}
