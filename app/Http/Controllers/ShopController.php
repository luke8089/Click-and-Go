<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with('category')->where('is_active', true);

        if ($request->filled('category')) {
            $query->whereHas('category', fn($q) => $q->whereIn('slug', (array) $request->category));
        }

        if ($request->filled('product_type')) {
            $query->whereIn('product_type', (array) $request->product_type);
        }

        if ($request->filled('brand')) {
            $query->whereIn('brand', (array) $request->brand);
        }

        if ($request->filled('viscosity')) {
            $query->whereIn('viscosity_grade', (array) $request->viscosity);
        }

        if ($request->filled('pack_size')) {
            $query->whereIn('pack_size', (array) $request->pack_size);
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('part_number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }

        $sortOptions = [
            'featured'   => 'sort_order',
            'price_asc'  => 'price',
            'price_desc' => 'price',
            'newest'     => 'created_at',
            'name_asc'   => 'name',
        ];

        $sort = $request->get('sort', 'featured');
        $col  = $sortOptions[$sort] ?? 'sort_order';
        $dir  = in_array($sort, ['price_desc']) ? 'desc' : 'asc';
        if ($sort === 'newest') $dir = 'desc';

        $products   = $query->orderBy($col, $dir)->paginate(12)->withQueryString();
        $categories = Category::where('is_active', true)->withCount(['products' => fn($q) => $q->where('is_active', true)])->get();
        $brands     = Product::where('is_active', true)->distinct()->pluck('brand');

        // Only show viscosity filter when lubricant type is included (or no type filter applied)
        $activeTypes = array_filter((array) $request->get('product_type', []));
        $showViscosity = empty($activeTypes) || in_array('lubricant', $activeTypes);
        $viscosities = $showViscosity
            ? Product::where('is_active', true)->whereNotNull('viscosity_grade')->distinct()->pluck('viscosity_grade')
            : collect();

        $packSizes    = Product::where('is_active', true)->whereNotNull('pack_size')->distinct()->pluck('pack_size');
        $priceRange   = ['min' => Product::where('is_active', true)->min('price'), 'max' => Product::where('is_active', true)->max('price')];
        $featured     = Product::where('is_featured', true)->where('is_active', true)->with('category')->take(6)->get();
        $bestSellers  = Product::where('is_active', true)->with('category')->orderByDesc('id')->take(6)->get();
        $testimonials = \App\Models\Testimonial::where('is_approved', true)->latest()->get();

        if ($request->header('X-Shop-Fetch')) {
            return response()->json([
                'html'  => view('shop._products', compact('products'))->render(),
                'total' => $products->total(),
                'from'  => $products->firstItem() ?? 0,
                'to'    => $products->lastItem() ?? 0,
            ]);
        }

        return view('shop.index', compact('products', 'categories', 'brands', 'viscosities', 'packSizes', 'priceRange', 'featured', 'bestSellers', 'testimonials'));
    }

    public function suggest(Request $request)
    {
        $q = trim($request->get('q', ''));
        if (strlen($q) < 2) return response()->json([]);

        $results = Product::where('is_active', true)
            ->where(fn($query) => $query->where('name', 'like', "%{$q}%")
                ->orWhere('brand', 'like', "%{$q}%")
                ->orWhere('part_number', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'brand', 'part_number']);

        return response()->json($results->map(fn($p) => [
            'label' => $p->name,
            'sub'   => $p->part_number ? $p->brand . ' · ' . $p->part_number : $p->brand,
        ]));
    }

    public function show(Product $product)
    {
        $product->load([
            'category',
            'reviews' => fn($q) => $q->where('is_approved', true)->latest(),
            'crossSells.category',
        ]);
        $related      = Product::where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->where('is_active', true)
            ->take(4)->get();
        $featured     = Product::where('is_featured', true)->where('is_active', true)->with('category')->take(6)->get();
        $bestSellers  = Product::where('is_active', true)->with('category')->orderByDesc('id')->take(6)->get();
        $testimonials = \App\Models\Testimonial::where('is_approved', true)->latest()->get();
        $categories   = Category::where('is_active', true)->withCount(['products' => fn($q) => $q->where('is_active', true)])->get();

        return view('shop.show', compact('product', 'related', 'featured', 'bestSellers', 'testimonials', 'categories'));
    }
}
