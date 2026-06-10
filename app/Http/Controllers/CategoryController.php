<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        $categories  = Category::where('is_active', true)
            ->withCount(['products' => fn($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->get();
        $featured    = Product::where('is_featured', true)->where('is_active', true)->with('category')->take(6)->get();
        $bestSellers = Product::where('is_active', true)->with('category')->orderByDesc('id')->take(6)->get();
        $testimonials = \App\Models\Testimonial::where('is_approved', true)->latest()->get();

        return view('categories.index', compact('categories', 'featured', 'bestSellers', 'testimonials'));
    }

    public function show(Category $category, Request $request)
    {
        $query = $category->activeProducts()->with('category');

        if ($request->filled('product_type')) {
            $query->whereIn('product_type', (array) $request->product_type);
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

        $sort = $request->get('sort', 'featured');
        match($sort) {
            'price_asc'  => $query->orderBy('price', 'asc'),
            'price_desc' => $query->orderBy('price', 'desc'),
            'newest'     => $query->orderByDesc('created_at'),
            'name_asc'   => $query->orderBy('name', 'asc'),
            default      => $query->orderBy('sort_order', 'asc'),
        };

        $products    = $query->paginate(12)->withQueryString();
        $categories  = Category::where('is_active', true)->withCount(['products' => fn($q) => $q->where('is_active', true)])->get();

        $activeTypes  = array_filter((array) $request->get('product_type', []));
        $showViscosity = empty($activeTypes) || in_array('lubricant', $activeTypes);
        $viscosities = $showViscosity
            ? $category->activeProducts()->whereNotNull('viscosity_grade')->distinct()->pluck('viscosity_grade')
            : collect();
        $packSizes   = $category->activeProducts()->whereNotNull('pack_size')->distinct()->pluck('pack_size');
        $productTypes = $category->activeProducts()->distinct()->pluck('product_type');

        if ($request->header('X-Shop-Fetch')) {
            $data = [
                'html'  => view('categories._products', compact('products'))->render(),
                'total' => $products->total(),
                'from'  => $products->firstItem() ?? 0,
                'to'    => $products->lastItem() ?? 0,
            ];

            if ($request->header('X-Cat-Switch')) {
                $data['category'] = [
                    'name'        => $category->name,
                    'description' => $category->description ?? '',
                    'image'       => $category->image
                        ? asset('storage/' . $category->image)
                        : asset('images/image202.png'),
                ];
                $data['viscosities']   = $viscosities->values()->all();
                $data['pack_sizes']    = $packSizes->values()->all();
                $data['product_types'] = $productTypes->values()->all();
            }

            return response()->json($data);
        }

        $featured    = Product::where('is_featured', true)->where('is_active', true)->with('category')->take(6)->get();
        $bestSellers = Product::where('is_active', true)->with('category')->orderByDesc('id')->take(6)->get();
        $testimonials = \App\Models\Testimonial::where('is_approved', true)->latest()->get();

        return view('categories.show', compact('category', 'products', 'categories', 'viscosities', 'packSizes', 'productTypes', 'featured', 'bestSellers', 'testimonials'));
    }
}
