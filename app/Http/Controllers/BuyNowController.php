<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class BuyNowController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity'   => 'required|integer|min:1|max:99',
        ]);

        $product = Product::findOrFail($request->product_id);

        if ($product->stock < 1) {
            return back()->with('error', 'This product is out of stock.');
        }

        $quantity = min((int) $request->quantity, $product->stock);

        // Store in dedicated session key — regular cart is untouched
        session([
            'buy_now' => [
                'product_id' => $product->id,
                'quantity'   => $quantity,
            ],
        ]);

        // Already logged in → go straight to checkout
        if (auth()->check()) {
            return redirect()->route('checkout.index');
        }

        // Guest clicked "Guest Checkout" in the popover → set flag and go to checkout
        if ($request->boolean('checkout_as_guest')) {
            session(['guest_checkout' => true]);
            return redirect()->route('checkout.index');
        }

        // Fallback: redirect to gate (e.g. direct POST without the popover)
        return redirect()->route('checkout.gate');
    }
}
