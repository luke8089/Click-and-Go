<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class CouponController extends Controller
{
    public function apply(Request $request)
    {
        if (Setting::get('coupons_enabled', '1') !== '1') {
            return response()->json(['success' => false, 'message' => 'Coupons are currently disabled.'], 422);
        }

        $request->validate(['code' => 'required|string|max:50']);

        // Block IPs/sessions that have submitted 5 wrong codes — locked out for 1 hour
        $failKey = 'coupon-fail:' . $request->ip() . '|' . session()->getId();

        if (RateLimiter::tooManyAttempts($failKey, 5)) {
            $minutes = ceil(RateLimiter::availableIn($failKey) / 60);
            return response()->json([
                'success' => false,
                'message' => "Too many invalid attempts. Please try again in {$minutes} minute(s).",
            ], 429);
        }

        $coupon = Coupon::where('code', strtoupper(trim($request->code)))->first();

        if (!$coupon) {
            RateLimiter::hit($failKey, 3600);
            return response()->json(['success' => false, 'message' => 'Invalid coupon code.'], 422);
        }

        $items    = $this->getCartItems();
        $subtotal = $items->sum('subtotal');
        $result   = $coupon->validate($subtotal, $this->toValidationArray($items));

        if (!$result['valid']) {
            RateLimiter::hit($failKey, 3600);
            return response()->json(['success' => false, 'message' => $result['message']], 422);
        }

        RateLimiter::clear($failKey);
        session(['applied_coupon' => $coupon->code]);

        return response()->json([
            'success'      => true,
            'message'      => 'Coupon applied! You save Ksh ' . number_format($result['discount'], 0) . ($result['free_shipping'] ? ' + free shipping' : '') . '.',
            'code'         => $coupon->code,
            'discount'     => $result['discount'],
            'discount_fmt' => 'Ksh ' . number_format($result['discount'], 0),
            'type_label'   => $coupon->getTypeLabel(),
            'free_shipping'=> $result['free_shipping'],
        ]);
    }

    public function remove()
    {
        session()->forget('applied_coupon');

        return response()->json(['success' => true, 'message' => 'Coupon removed.']);
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
}
