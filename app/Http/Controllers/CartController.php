<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\Request;

class CartController extends Controller
{
    private function cartQuery()
    {
        if (auth()->check()) {
            return CartItem::where('user_id', auth()->id());
        }
        return CartItem::where('session_id', session()->getId());
    }

    public function index()
    {
        $items           = $this->cartQuery()->with('product.category')->get();
        $subtotal        = $items->sum('subtotal');
        $freeShippingBar = Setting::get('free_shipping_bar_enabled', '1') === '1';
        $threshold       = (float) Setting::get('free_shipping_threshold', 10000);
        $couponsEnabled  = Setting::get('coupons_enabled', '1') === '1';

        $cartArray = $this->toValidationArray($items);

        // Auto-apply coupon if none is in session
        if ($couponsEnabled && !session('applied_coupon')) {
            $auto = Coupon::where('is_active', true)->where('is_auto_apply', true)->latest()->first();
            if ($auto) {
                $r = $auto->validate($subtotal, $cartArray);
                if ($r['valid']) {
                    session(['applied_coupon' => $auto->code]);
                }
            }
        }

        // Resolve applied coupon
        $appliedCoupon  = null;
        $couponDiscount = 0;
        $freeShipping   = false;
        if ($couponsEnabled && $code = session('applied_coupon')) {
            $coupon = Coupon::where('code', $code)->first();
            if ($coupon) {
                $result = $coupon->validate($subtotal, $cartArray);
                if ($result['valid']) {
                    $appliedCoupon  = $coupon;
                    $couponDiscount = $result['discount'];
                    $freeShipping   = $result['free_shipping'];
                } else {
                    session()->forget('applied_coupon');
                }
            } else {
                session()->forget('applied_coupon');
            }
        }

        $discountedSubtotal = $subtotal - $couponDiscount;
        $shipping           = ($freeShipping || ($freeShippingBar && $discountedSubtotal >= $threshold)) ? 0 : 300;
        $total              = $discountedSubtotal;
        $related            = Product::where('is_active', true)->where('is_featured', true)->take(4)->get();
        $couponFreeShipping = $freeShipping;

        return view('cart.index', compact(
            'items', 'subtotal', 'shipping', 'total', 'related',
            'freeShippingBar', 'threshold',
            'couponsEnabled', 'appliedCoupon', 'couponDiscount', 'couponFreeShipping'
        ));
    }

    public function add(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1|max:99',
        ]);

        $product = Product::findOrFail($request->product_id);

        if ($product->stock < $request->quantity) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Insufficient stock.'], 422);
            }
            return back()->with('error', 'Insufficient stock.');
        }

        $identifier = auth()->check()
            ? ['user_id' => auth()->id()]
            : ['session_id' => session()->getId()];

        $item = CartItem::firstOrNew(array_merge($identifier, ['product_id' => $product->id]));
        $item->quantity = ($item->quantity ?? 0) + $request->quantity;
        $item->save();

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Product added to cart!', 'cart' => $this->cartSummary()]);
        }
        return back()->with('success', 'Product added to cart!');
    }

    public function update(Request $request, CartItem $cartItem)
    {
        $request->validate(['quantity' => 'required|integer|min:1|max:99']);
        $this->authorizeCartItem($cartItem);

        if ($cartItem->product->stock < $request->quantity) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Insufficient stock.'], 422);
            }
            return back()->with('error', 'Insufficient stock.');
        }

        $cartItem->update(['quantity' => $request->quantity]);

        if ($request->expectsJson()) {
            $cartItem->refresh();
            return response()->json([
                'success' => true,
                'message' => 'Cart updated.',
                'item'    => [
                    'id'       => $cartItem->id,
                    'quantity' => $cartItem->quantity,
                    'subtotal' => number_format($cartItem->subtotal, 2),
                ],
                'cart' => $this->cartSummary(),
            ]);
        }
        return back()->with('success', 'Cart updated.');
    }

    public function remove(CartItem $cartItem)
    {
        $this->authorizeCartItem($cartItem);
        $cartItem->delete();

        if (request()->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Item removed from cart.', 'cart' => $this->cartSummary()]);
        }
        return back()->with('success', 'Item removed from cart.');
    }

    public function clear()
    {
        $this->cartQuery()->delete();
        return back()->with('success', 'Cart cleared.');
    }

    public function count()
    {
        $count = $this->cartQuery()->sum('quantity');
        return response()->json(['count' => $count]);
    }

    private function cartSummary(): array
    {
        $items     = $this->cartQuery()->with('product.category')->get();
        $subtotal  = $items->sum('subtotal');
        $threshold = (float) Setting::get('free_shipping_threshold', 10000);
        $freeBar   = Setting::get('free_shipping_bar_enabled', '1') === '1';
        $count     = (int) $items->sum('quantity');
        $cartArray = $this->toValidationArray($items);

        $couponDiscount = 0;
        $couponFreeShip = false;
        if ($code = session('applied_coupon')) {
            $coupon = Coupon::where('code', $code)->first();
            if ($coupon) {
                $result = $coupon->validate($subtotal, $cartArray);
                if ($result['valid']) {
                    $couponDiscount = $result['discount'];
                    $couponFreeShip = $result['free_shipping'];
                }
            }
        }

        $discountedSubtotal = $subtotal - $couponDiscount;
        $remaining = max(0, $threshold - $discountedSubtotal);
        $pct       = $threshold > 0 ? min(100, ($discountedSubtotal / $threshold) * 100) : 100;

        return [
            'count'                   => $count,
            'items_count'             => $items->count(),
            'subtotal_fmt'            => number_format($subtotal, 2),
            'discount_fmt'            => number_format($couponDiscount, 2),
            'total_fmt'               => number_format($discountedSubtotal, 2),
            'free_shipping_bar'       => $freeBar,
            'free_shipping_remaining' => number_format($remaining, 2),
            'free_shipping_pct'       => round($pct, 1),
            'threshold'               => $threshold,
            'empty'                   => $items->isEmpty(),
        ];
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

    private function authorizeCartItem(CartItem $item)
    {
        if (auth()->check()) {
            abort_unless($item->user_id === auth()->id(), 403);
        } else {
            abort_unless($item->session_id === session()->getId(), 403);
        }
    }
}
