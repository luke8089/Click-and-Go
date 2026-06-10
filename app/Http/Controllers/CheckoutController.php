<?php

namespace App\Http\Controllers;

use App\Mail\NewOrderAdminMail;
use App\Mail\OrderConfirmationMail;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\DeliveryService;
use App\Models\MpesaTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PickupStation;
use App\Models\Setting;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function index()
    {
        $isBuyNow = false;

        if (session()->has('buy_now')) {
            $buyNowItems = $this->buildBuyNowItems();
            if ($buyNowItems && $buyNowItems->isNotEmpty()) {
                $items    = $buyNowItems;
                $isBuyNow = true;
            }
        }

        if (!$isBuyNow) {
            $items = $this->getCartItems();
        }

        if ($items->isEmpty()) {
            return redirect()->route('cart.index')->with('error', 'Your cart is empty.');
        }

        // Generate a per-session idempotency token to prevent duplicate orders
        if (!session()->has('checkout_token')) {
            session(['checkout_token' => Str::uuid()->toString()]);
        }

        $subtotal  = $items->sum('subtotal');
        $cartArray = $this->toValidationArray($items);

        // Coupons are not available for Buy It Now orders
        $appliedCoupon      = null;
        $couponDiscount     = 0;
        $couponFreeShipping = false;

        if (!$isBuyNow) {
            if (Setting::get('coupons_enabled', '1') === '1' && $code = session('applied_coupon')) {
                $coupon = Coupon::where('code', $code)->first();
                if ($coupon) {
                    $result = $coupon->validate($subtotal, $cartArray);
                    if ($result['valid']) {
                        $appliedCoupon      = $coupon;
                        $couponDiscount     = $result['discount'];
                        $couponFreeShipping = (bool) $result['free_shipping'];
                    } else {
                        session()->forget('applied_coupon');
                    }
                } else {
                    session()->forget('applied_coupon');
                }
            } elseif (Setting::get('coupons_enabled', '1') !== '1') {
                session()->forget('applied_coupon');
            }
        }

        $discountedSubtotal    = $subtotal - $couponDiscount;
        $freeShippingEnabled   = Setting::get('free_shipping_bar_enabled', '1') === '1';
        $freeShippingThreshold = (float) Setting::get('free_shipping_threshold', 10000);
        $qualifiesForFree      = $couponFreeShipping || ($freeShippingEnabled && $discountedSubtotal >= $freeShippingThreshold);
        $standardShipping      = $qualifiesForFree ? 0 : 300;
        $shipping              = $standardShipping;
        $total                 = $discountedSubtotal + $shipping;
        $user                  = auth()->user();
        $pickupStations        = PickupStation::where('is_active', true)->orderBy('name')->get();
        $deliveryServices      = DeliveryService::active()->get();
        $directDeliveryEnabled = Setting::get('direct_delivery_enabled', '1') === '1';

        return view('checkout.index', compact(
            'items', 'subtotal', 'shipping', 'total', 'user',
            'pickupStations', 'deliveryServices', 'standardShipping', 'freeShippingThreshold',
            'directDeliveryEnabled', 'freeShippingEnabled',
            'appliedCoupon', 'couponDiscount', 'couponFreeShipping', 'isBuyNow'
        ));
    }

    public function placeOrder(Request $request)
    {
        // Rate limit: 5 attempts per user (or guest session) per 10 minutes
        $rateLimitKey = 'place-order:' . (auth()->id() ?? session()->getId());
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return back()->withErrors(['order' => 'Too many order attempts. Please wait ' . ceil($seconds / 60) . ' minute(s) before trying again.']);
        }

        // Idempotency: if this checkout token was already used, return the existing order
        $token = session('checkout_token');
        if ($token) {
            $existing = Order::where('idempotency_key', $token)->first();
            if ($existing) {
                session(['last_order_id' => $existing->id]);
                session()->forget('checkout_token');
                return $existing->payment_method === 'mpesa'
                    ? redirect()->route('checkout.waiting', $existing)
                    : redirect()->route('checkout.success');
            }
        }

        // Concurrent-submission lock: only one request per user (or guest session) at a time
        $lock = Cache::lock('place-order-lock:' . (auth()->id() ?? session()->getId()), 30);
        if (!$lock->get()) {
            return back()->withErrors(['order' => 'Your previous submission is still being processed. Please wait a moment.']);
        }

        try {
            $request->validate([
                'first_name'         => 'required|string|max:50',
                'last_name'          => 'required|string|max:50',
                'email'              => auth()->check() ? 'nullable|email:rfc|max:150' : 'required|email:rfc|max:150',
                'phone'              => 'required|string|max:20',
                'additional_phone'   => 'nullable|string|max:20',
                'country'            => 'required|string|max:100',
                'county'             => 'nullable|string|max:100',
                'city'               => 'required|string|max:100',
                'address'            => 'nullable|string|max:255',
                'additional_info'    => 'nullable|string|max:1000',
                'payment_method'     => 'required|in:mpesa,bank_transfer',
                'mpesa_phone'        => 'required_if:payment_method,mpesa|nullable|string|max:20',
                'delivery_method'    => 'required|in:' . (Setting::get('direct_delivery_enabled', '1') === '1' ? 'delivery,pickup' : 'pickup'),
                'delivery_service'   => 'required_if:delivery_method,delivery|nullable|exists:delivery_services,id',
                'pickup_station_id'  => 'required_if:delivery_method,pickup|nullable|exists:pickup_stations,id',
            ]);

            // Detect buy-now session; fall back to regular cart
            $isBuyNow = false;
            $items    = null;

            if (session()->has('buy_now')) {
                $buyNowItems = $this->buildBuyNowItems();
                if ($buyNowItems && $buyNowItems->isNotEmpty()) {
                    $items    = $buyNowItems;
                    $isBuyNow = true;
                }
            }

            if (!$isBuyNow) {
                $items = $this->getCartItems();
            }

            if ($items->isEmpty()) {
                return redirect()->route('cart.index')->with('error', 'Your cart is empty.');
            }

            $subtotal  = $items->sum('subtotal');
            $cartArray = $this->toValidationArray($items);

            // Coupons are not available for Buy It Now orders
            $couponDiscount = 0;
            $couponCode     = null;
            $appliedCoupon  = null;
            $couponFreeShip = false;

            if (!$isBuyNow && Setting::get('coupons_enabled', '1') === '1' && $code = session('applied_coupon')) {
                $coupon = Coupon::where('code', $code)->first();
                if ($coupon) {
                    $result = $coupon->validate($subtotal, $cartArray);
                    if ($result['valid']) {
                        $couponDiscount = $result['discount'];
                        $couponCode     = $coupon->code;
                        $appliedCoupon  = $coupon;
                        $couponFreeShip = $result['free_shipping'];
                    }
                }
            }

            $discountedSubtotal    = $subtotal - $couponDiscount;
            $freeShippingEnabled   = Setting::get('free_shipping_bar_enabled', '1') === '1';
            $freeShippingThreshold = (float) Setting::get('free_shipping_threshold', 10000);
            $qualifiesForFree      = $couponFreeShip || ($freeShippingEnabled && $discountedSubtotal >= $freeShippingThreshold);
            $pickupStationRecord   = null;
            $pickupStationName     = null;
            if ($request->delivery_method === 'pickup') {
                $pickupStationRecord = PickupStation::find($request->pickup_station_id);
                $shipping            = $qualifiesForFree ? 0 : ($pickupStationRecord ? (float) $pickupStationRecord->price : 0);
                $pickupStationName   = $pickupStationRecord?->name;
            } else {
                $shipping = $qualifiesForFree ? 0 : 300;
            }

            $total = $discountedSubtotal + $shipping;
            $order = null;

            DB::transaction(function () use ($request, $items, $subtotal, $shipping, $total, $pickupStationName, $token, $couponDiscount, $couponCode, $appliedCoupon, &$order, $couponFreeShip, $isBuyNow) {
                $order = Order::create([
                    'user_id'                   => auth()->id(),
                    'idempotency_key'           => $token,
                    'status'                    => 'pending',
                    'payment_method'            => $request->payment_method,
                    'payment_status'            => 'pending',
                    'subtotal'                  => $subtotal,
                    'shipping'                  => $shipping,
                    'tax'                       => 0,
                    'discount'                  => $couponDiscount,
                    'total'                     => $total,
                    'coupon_code'               => $couponCode,
                    'shipping_name'             => trim($request->first_name . ' ' . $request->last_name),
                    'shipping_email'            => auth()->user()?->email ?? $request->email,
                    'shipping_phone'            => $request->phone,
                    'shipping_phone_additional' => $request->additional_phone,
                    'shipping_address'          => $request->address,
                    'shipping_city'             => $request->city,
                    'shipping_state'            => $request->county,
                    'shipping_country'          => $request->country,
                    'notes'                     => $request->additional_info,
                    'delivery_method'           => $request->delivery_method,
                    'delivery_service'          => $request->delivery_method === 'delivery' ? $request->delivery_service : null,
                    'pickup_station_id'         => $request->delivery_method === 'pickup' ? $request->pickup_station_id : null,
                    'pickup_station_name'       => $pickupStationName,
                ]);

                foreach ($items as $item) {
                    OrderItem::create([
                        'order_id'     => $order->id,
                        'product_id'   => $item->product_id,
                        'product_name' => $item->product->name,
                        'product_sku'  => $item->product->sku,
                        'price'        => $item->product->current_price,
                        'quantity'     => $item->quantity,
                        'total'        => $item->subtotal,
                    ]);
                }

                // Buy-now uses a virtual cart; only clear the real cart for normal checkout
                if (!$isBuyNow) {
                    $this->clearCart();
                }

                // Increment coupon usage + record per-user tracking
                if ($appliedCoupon) {
                    $appliedCoupon->increment('used_count');
                    if (auth()->id()) {
                        CouponUsage::create([
                            'coupon_id' => $appliedCoupon->id,
                            'user_id'   => auth()->id(),
                            'order_id'  => $order->id,
                        ]);
                    }
                    session()->forget('applied_coupon');
                }
            });

            RateLimiter::hit($rateLimitKey, 600);
            session(['last_order_id' => $order->id]);
            session()->forget('checkout_token');
            session()->forget('buy_now');

            // Store prefill data so guests can register after checkout and have their order linked
            if (!auth()->check()) {
                session(['guest_order_prefill' => [
                    'name'     => trim($request->first_name . ' ' . $request->last_name),
                    'email'    => $request->email,
                    'phone'    => $request->phone,
                    'order_id' => $order->id,
                ]]);
            }

            $order->load('items');
            try {
                $adminEmail = Setting::get('admin_email', config('mail.from.address'));
                Mail::to($order->shipping_email)->send(new OrderConfirmationMail($order));
                Mail::to($adminEmail)->send(new NewOrderAdminMail($order));
            } catch (\Exception) {}

            if ($request->payment_method === 'mpesa') {
                return $this->initiateMpesa($request, $order, $total);
            }

            return redirect()->route('checkout.success');

        } finally {
            $lock->release();
        }
    }

    private function initiateMpesa(Request $request, Order $order, float $total)
    {
        $mpesa  = new MpesaService();
        $result = $mpesa->stkPush(
            $request->mpesa_phone,
            $total,
            $order->id,
            'Click Go Order'
        );

        MpesaTransaction::create([
            'order_id'            => $order->id,
            'phone'               => $request->mpesa_phone,
            'amount'              => $total,
            'checkout_request_id' => $result['checkout_request_id'] ?? null,
            'merchant_request_id' => $result['merchant_request_id'] ?? null,
            'status'              => $result['success'] ? 'pending' : 'failed',
        ]);

        if (!$result['success']) {
            // Order exists but payment didn't fire — let user know
            return redirect()->route('checkout.waiting', $order)
                ->with('stk_error', $result['message'] ?? 'M-Pesa prompt could not be sent.');
        }

        return redirect()->route('checkout.waiting', $order);
    }

    public function waiting(Order $order)
    {
        // Authenticated users can only see their own orders
        // Guests can only see orders that have no user_id (their own guest order)
        if (auth()->check()) {
            if ($order->user_id !== auth()->id()) {
                abort(403);
            }
        } else {
            if ($order->user_id !== null) {
                abort(403);
            }
        }

        $transaction = $order->mpesaTransactions()->latest()->first();

        return view('checkout.waiting', compact('order', 'transaction'));
    }

    public function success()
    {
        $orderId = session('last_order_id');
        $order   = $orderId ? Order::with('items.product')->find($orderId) : null;

        return view('checkout.success', compact('order'));
    }

    public function submitPaymentReference(Request $request, Order $order)
    {
        // Only the order owner (or the specific guest who placed it) may submit
        if (auth()->check()) {
            abort_unless($order->user_id === auth()->id(), 403);
        } else {
            // Match both: order is unowned AND this session placed it
            $guestOrderId = session('guest_order_prefill.order_id')
                ?? session('last_order_id');
            abort_unless(
                $order->user_id === null && (int) $guestOrderId === $order->id,
                403
            );
        }

        $request->validate([
            'payment_reference' => [
                'required',
                'string',
                'min:6',
                'max:30',
                'regex:/^[A-Za-z0-9]{6,30}$/',
            ],
        ], [
            'payment_reference.required' => 'Please enter your transaction confirmation code.',
            'payment_reference.min'      => 'The code looks too short — please check and try again.',
            'payment_reference.max'      => 'The code looks too long — please check and try again.',
            'payment_reference.regex'    => 'Transaction codes contain letters and numbers only — no spaces or special characters.',
        ]);

        // Guard: already submitted
        if ($order->payment_reference) {
            return back()->with('ref_error', 'A confirmation code has already been submitted for this order.');
        }

        $order->update([
            'payment_reference' => strtoupper(trim($request->payment_reference)),
        ]);

        try {
            $adminEmail = Setting::get('admin_email', config('mail.from.address'));
            Mail::to($adminEmail)
                ->send(new \App\Mail\BankPaymentConfirmationAdminMail($order->fresh()));
        } catch (\Exception) {}

        return back()->with('ref_success', 'Thank you! Your confirmation code has been received. We will verify and process your order shortly.');
    }

    private function buildBuyNowItems(): ?\Illuminate\Support\Collection
    {
        $data = session('buy_now');
        if (!$data || empty($data['product_id'])) {
            return null;
        }

        $product = \App\Models\Product::with('category')->find($data['product_id']);
        if (!$product) {
            session()->forget('buy_now');
            return null;
        }

        $quantity = max(1, (int) $data['quantity']);

        // Cap quantity to available stock (stock may have changed since session was set)
        if ($product->stock < $quantity) {
            $quantity = $product->stock;
        }

        if ($quantity < 1) {
            session()->forget('buy_now');
            return null;
        }

        $item              = new \stdClass();
        $item->product_id  = $product->id;
        $item->product     = $product;
        $item->quantity    = $quantity;
        $item->subtotal    = $product->current_price * $quantity;

        return collect([$item]);
    }

    private function getCartItems()
    {
        if (auth()->check()) {
            return CartItem::where('user_id', auth()->id())->with('product.category')->get();
        }
        return CartItem::where('session_id', session()->getId())->with('product.category')->get();
    }

    private function toValidationArray($items): array
    {
        return $items->map(fn($item) => [
            'product_id'   => $item->product_id,
            'quantity'     => $item->quantity,
            'subtotal'     => $item->subtotal,
            'brand'        => $item->product->brand ?? '',
            'product_type' => $item->product->product_type ?? 'lubricant',
            'category_id'  => $item->product->category_id ?? 0,
        ])->toArray();
    }

    private function clearCart()
    {
        if (auth()->check()) {
            CartItem::where('user_id', auth()->id())->delete();
        } else {
            CartItem::where('session_id', session()->getId())->delete();
        }
    }
}
