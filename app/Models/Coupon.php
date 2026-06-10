<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Models\Order;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'description', 'type', 'value',
        'min_order', 'max_uses', 'used_count', 'is_active', 'expires_at',
        // New fields
        'applies_to', 'applicable_product_types', 'applicable_brands',
        'max_discount', 'max_uses_per_user', 'first_order_only',
        'min_quantity', 'free_shipping', 'is_auto_apply',
    ];

    protected $casts = [
        'is_active'                => 'boolean',
        'expires_at'               => 'datetime',
        'value'                    => 'float',
        'min_order'                => 'float',
        'max_discount'             => 'float',
        'applicable_product_types' => 'array',
        'applicable_brands'        => 'array',
        'first_order_only'         => 'boolean',
        'free_shipping'            => 'boolean',
        'is_auto_apply'            => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function products()
    {
        return $this->belongsToMany(Product::class, 'coupon_products');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'coupon_categories');
    }

    public function usages()
    {
        return $this->hasMany(CouponUsage::class);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /**
     * Validate the coupon against the current cart.
     *
     * $cartItems: array of [product_id, quantity, subtotal, brand, product_type, category_id]
     * Backward-compatible — all new params default to safe values so existing
     * calls to validate($subtotal) with no extra args continue to work.
     */
    public function validate(float $subtotal, array $cartItems = [], ?int $userId = null): array
    {
        // Resolve userId from auth if not passed
        $userId = $userId ?? auth()->id();

        // ── Basic checks (unchanged) ─────────────────────────────────────────
        if (!$this->is_active) {
            return ['valid' => false, 'message' => 'This coupon is inactive.'];
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return ['valid' => false, 'message' => 'This coupon has expired.'];
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return ['valid' => false, 'message' => 'This coupon has reached its usage limit.'];
        }

        if ($subtotal < $this->min_order) {
            return [
                'valid'   => false,
                'message' => 'Minimum order of Ksh ' . number_format($this->min_order, 0) . ' required to use this coupon.',
            ];
        }

        // ── Per-user usage limit ─────────────────────────────────────────────
        if ($userId && $this->max_uses_per_user !== null) {
            $used = $this->usages()->where('user_id', $userId)->count();
            if ($used >= $this->max_uses_per_user) {
                return ['valid' => false, 'message' => 'You have already used this coupon the maximum allowed times.'];
            }
        }

        // ── First order only ─────────────────────────────────────────────────
        if ($this->first_order_only && $userId) {
            $hasPaid = Order::where('user_id', $userId)
                ->where('payment_status', 'paid')
                ->exists();
            if ($hasPaid) {
                return ['valid' => false, 'message' => 'This coupon is only valid on your first order.'];
            }
        }

        // ── Minimum quantity ─────────────────────────────────────────────────
        if ($this->min_quantity && !empty($cartItems)) {
            $totalQty = array_sum(array_column($cartItems, 'quantity'));
            if ($totalQty < $this->min_quantity) {
                return [
                    'valid'   => false,
                    'message' => 'You need at least ' . $this->min_quantity . ' item(s) in your cart to use this coupon.',
                ];
            }
        }

        // ── Calculate eligible subtotal (for targeted coupons) ───────────────
        $eligibleSubtotal = $this->getEligibleSubtotal($subtotal, $cartItems);

        if ($eligibleSubtotal <= 0 && $this->applies_to !== 'all' && !empty($cartItems)) {
            return ['valid' => false, 'message' => 'This coupon does not apply to any items in your cart.'];
        }

        $discount = $this->calculateDiscount($eligibleSubtotal > 0 ? $eligibleSubtotal : $subtotal);

        return [
            'valid'        => true,
            'message'      => 'Coupon applied!',
            'discount'     => $discount,
            'free_shipping' => (bool) $this->free_shipping,
        ];
    }

    public function calculateDiscount(float $subtotal): float
    {
        if ($this->type === 'percent') {
            $discount = round(($subtotal * $this->value) / 100, 2);
            // Apply max_discount cap if set
            if ($this->max_discount !== null && $discount > $this->max_discount) {
                $discount = $this->max_discount;
            }
            return $discount;
        }

        return min($this->value, $subtotal);
    }

    public function getTypeLabel(): string
    {
        if ($this->free_shipping && $this->type === 'free_shipping') {
            return 'Free Shipping';
        }
        return $this->type === 'percent' ? $this->value . '%' : 'Ksh ' . number_format($this->value, 0);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getEligibleSubtotal(float $subtotal, array $cartItems): float
    {
        if ($this->applies_to === 'all' || empty($cartItems)) {
            return $subtotal;
        }

        // Pre-load IDs for relationship-based targeting (avoids N+1)
        $productIds  = $this->applies_to === 'specific_products'
            ? $this->products()->pluck('products.id')->toArray()
            : [];
        $categoryIds = $this->applies_to === 'specific_categories'
            ? $this->categories()->pluck('categories.id')->toArray()
            : [];

        $eligible = 0;
        foreach ($cartItems as $item) {
            if ($this->itemIsEligible($item, $productIds, $categoryIds)) {
                $eligible += ($item['subtotal'] ?? 0);
            }
        }

        return (float) $eligible;
    }

    private function itemIsEligible(array $item, array $productIds, array $categoryIds): bool
    {
        return match ($this->applies_to) {
            'product_type'       => in_array($item['product_type'] ?? 'lubricant', $this->applicable_product_types ?? []),
            'specific_brands'    => in_array($item['brand'] ?? '', $this->applicable_brands ?? []),
            'specific_products'  => in_array($item['product_id'] ?? 0, $productIds),
            'specific_categories'=> in_array($item['category_id'] ?? 0, $categoryIds),
            default              => true,
        };
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    protected static function boot(): void
    {
        parent::boot();
        static::saving(function ($coupon) {
            $coupon->code = Str::upper($coupon->code);
        });
    }
}
