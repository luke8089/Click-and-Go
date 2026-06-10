<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CouponController extends Controller
{
    public function index(Request $request)
    {
        $query = Coupon::latest();

        if ($request->filled('search')) {
            $query->where('code', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        $coupons = $query->paginate(20)->withQueryString();
        $total   = Coupon::count();

        return view('admin.coupons.index', compact('coupons', 'total'));
    }

    public function create()
    {
        [$products, $categories, $brands] = $this->formData();

        return view('admin.coupons.form', [
            'coupon'     => null,
            'products'   => $products,
            'categories' => $categories,
            'brands'     => $brands,
        ]);
    }

    public function store(Request $request)
    {
        $data   = $this->validateCoupon($request);
        $coupon = Coupon::create($data);
        $this->syncRelations($coupon, $request);

        return redirect()->route('admin.coupons.index')->with('success', 'Coupon created.');
    }

    public function edit(Coupon $coupon)
    {
        [$products, $categories, $brands] = $this->formData();
        $coupon->load('products', 'categories');

        return view('admin.coupons.form', compact('coupon', 'products', 'categories', 'brands'));
    }

    public function update(Request $request, Coupon $coupon)
    {
        $data = $this->validateCoupon($request, $coupon);
        $coupon->update($data);
        $this->syncRelations($coupon, $request);

        return redirect()->route('admin.coupons.index')->with('success', 'Coupon updated.');
    }

    public function destroy(Coupon $coupon)
    {
        $coupon->delete();
        return redirect()->route('admin.coupons.index')->with('success', 'Coupon deleted.');
    }

    public function toggle(Coupon $coupon)
    {
        $coupon->update(['is_active' => !$coupon->is_active]);
        return back()->with('success', 'Coupon status updated.');
    }

    public function bulkGenerateForm()
    {
        return view('admin.coupons.bulk-generate');
    }

    public function bulkGenerate(Request $request)
    {
        $request->validate([
            'prefix'      => 'nullable|string|max:10|alpha',
            'count'       => 'required|integer|min:1|max:500',
            'type'        => 'required|in:percent,fixed',
            'value'       => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'min_order'   => 'nullable|numeric|min:0',
            'max_uses'    => 'nullable|integer|min:1',
            'expires_at'  => 'nullable|date|after:today',
        ]);

        $prefix  = strtoupper(trim($request->input('prefix', '')));
        $count   = (int) $request->count;
        $created = 0;

        for ($i = 0; $i < $count; $i++) {
            do {
                $code = $prefix . strtoupper(Str::random(8));
            } while (Coupon::where('code', $code)->exists());

            Coupon::create([
                'code'        => $code,
                'description' => $request->description,
                'type'        => $request->type,
                'value'       => $request->value,
                'min_order'   => $request->input('min_order', 0) ?: 0,
                'max_uses'    => $request->max_uses,
                'expires_at'  => $request->expires_at,
                'is_active'   => true,
            ]);
            $created++;
        }

        return redirect()->route('admin.coupons.index')
            ->with('success', $created . ' coupon code' . ($created === 1 ? '' : 's') . ' generated successfully.');
    }

    public function usageLog(Request $request, Coupon $coupon)
    {
        $usages = $coupon->usages()
            ->with('user', 'order')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.coupons.usage', compact('coupon', 'usages'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formData(): array
    {
        $products   = Product::select('id', 'name', 'sku', 'brand')->where('is_active', true)->orderBy('name')->get();
        $categories = Category::select('id', 'name')->orderBy('name')->get();
        $brands     = Product::select('brand')
            ->whereNotNull('brand')->where('brand', '!=', '')
            ->distinct()->orderBy('brand')->pluck('brand');

        return [$products, $categories, $brands];
    }

    private function syncRelations(Coupon $coupon, Request $request): void
    {
        if ($request->input('applies_to') === 'specific_products') {
            $ids = array_filter((array) $request->input('product_ids', []), 'is_numeric');
            $coupon->products()->sync($ids);
        } else {
            $coupon->products()->detach();
        }

        if ($request->input('applies_to') === 'specific_categories') {
            $ids = array_filter((array) $request->input('category_ids', []), 'is_numeric');
            $coupon->categories()->sync($ids);
        } else {
            $coupon->categories()->detach();
        }
    }

    private function validateCoupon(Request $request, ?Coupon $coupon = null): array
    {
        $codeRule = 'required|string|max:50|unique:coupons,code' . ($coupon ? ',' . $coupon->id : '');

        $validated = $request->validate([
            'code'              => $codeRule,
            'description'       => 'nullable|string|max:255',
            'type'              => 'required|in:percent,fixed',
            'value'             => 'required|numeric|min:0.01',
            'min_order'         => 'nullable|numeric|min:0',
            'max_uses'          => 'nullable|integer|min:1',
            'is_active'         => 'boolean',
            'expires_at'        => 'nullable|date|after:today',
            'applies_to'        => 'required|in:all,product_type,specific_brands,specific_products,specific_categories',
            'max_discount'      => 'nullable|numeric|min:0',
            'max_uses_per_user' => 'nullable|integer|min:1',
            'first_order_only'  => 'boolean',
            'min_quantity'      => 'nullable|integer|min:1',
            'free_shipping'     => 'boolean',
            'is_auto_apply'     => 'boolean',
        ]);

        $validated['is_active']        = $request->boolean('is_active');
        $validated['first_order_only'] = $request->boolean('first_order_only');
        $validated['free_shipping']    = $request->boolean('free_shipping');
        $validated['is_auto_apply']    = $request->boolean('is_auto_apply');
        $validated['min_order']        = $request->input('min_order', 0) ?: 0;

        if ($validated['type'] === 'percent' && $validated['value'] > 100) {
            $validated['value'] = 100;
        }

        $appliesTo = $validated['applies_to'];

        $validated['applicable_product_types'] = ($appliesTo === 'product_type')
            ? array_values(array_filter((array) $request->input('applicable_product_types', [])))
            : null;

        $validated['applicable_brands'] = ($appliesTo === 'specific_brands')
            ? array_values(array_filter(array_map('trim', (array) $request->input('applicable_brands', []))))
            : null;

        return $validated;
    }
}
