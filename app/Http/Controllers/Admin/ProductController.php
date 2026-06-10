<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with('category');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%$s%")->orWhere('sku', 'like', "%$s%"));
        }

        if ($request->filled('category')) {
            $query->where('category_id', $request->category);
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        $products   = $query->latest()->paginate(20)->withQueryString();
        $categories = Category::all();

        return view('admin.products.index', compact('products', 'categories'));
    }

    public function create()
    {
        $categories  = Category::where('is_active', true)->get();
        $allProducts = Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']);
        return view('admin.products.create', compact('categories', 'allProducts'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:200',
            'category_id'       => 'required|exists:categories,id',
            'product_type'      => 'required|in:lubricant,filter,part',
            'sku'               => 'required|string|unique:products,sku',
            'price'             => 'required|numeric|min:0',
            'sale_price'        => 'nullable|numeric|min:0',
            'wholesale_price'   => 'nullable|numeric|min:0',
            'stock'             => 'required|integer|min:0',
            'short_description' => 'nullable|string|max:500',
            'description'       => 'nullable|string',
            'brand'             => 'required|string|max:100',
            'viscosity_grade'   => 'nullable|string|max:50',
            'pack_size'         => 'nullable|string|max:50',
            'part_number'       => 'nullable|string|max:100',
            'compatibility'     => 'nullable|string',
            'applications'      => 'nullable|string',
            'approvals'         => 'nullable|string',
            'key_benefits'      => 'nullable|string',
            'is_active'         => 'boolean',
            'is_featured'       => 'boolean',
            'image'             => 'nullable|image|max:4096',
            'gallery_img_1'     => 'nullable|image|max:4096',
            'gallery_img_2'     => 'nullable|image|max:4096',
            'video_url'         => 'nullable|url|max:500',
        ]);

        $data['slug']        = Str::slug($data['name']) . '-' . Str::random(4);
        $data['is_active']   = $request->boolean('is_active');
        $data['is_featured'] = $request->boolean('is_featured');
        $data['key_benefits'] = $request->filled('key_benefits')
            ? array_values(array_filter(array_map('trim', explode("\n", $request->key_benefits))))
            : null;

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $gallery = [];
        if ($request->hasFile('gallery_img_1')) {
            $gallery[] = $request->file('gallery_img_1')->store('products', 'public');
        }
        if ($request->hasFile('gallery_img_2')) {
            $gallery[] = $request->file('gallery_img_2')->store('products', 'public');
        }
        $data['gallery'] = $gallery ?: null;

        unset($data['gallery_img_1'], $data['gallery_img_2']);

        $product = Product::create($data);
        $product->crossSells()->sync($request->input('cross_sells', []));
        return redirect()->route('admin.products.index')->with('success', 'Product created successfully.');
    }

    public function edit(Product $product)
    {
        $categories  = Category::where('is_active', true)->get();
        $allProducts = Product::where('is_active', true)->where('id', '!=', $product->id)->orderBy('name')->get(['id', 'name', 'sku']);
        $crossSellIds = $product->crossSells()->pluck('products.id')->toArray();
        return view('admin.products.edit', compact('product', 'categories', 'allProducts', 'crossSellIds'));
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:200',
            'category_id'       => 'required|exists:categories,id',
            'product_type'      => 'required|in:lubricant,filter,part',
            'sku'               => 'required|string|unique:products,sku,' . $product->id,
            'price'             => 'required|numeric|min:0',
            'sale_price'        => 'nullable|numeric|min:0',
            'wholesale_price'   => 'nullable|numeric|min:0',
            'stock'             => 'required|integer|min:0',
            'short_description' => 'nullable|string|max:500',
            'description'       => 'nullable|string',
            'brand'             => 'required|string|max:100',
            'viscosity_grade'   => 'nullable|string|max:50',
            'pack_size'         => 'nullable|string|max:50',
            'part_number'       => 'nullable|string|max:100',
            'compatibility'     => 'nullable|string',
            'applications'      => 'nullable|string',
            'approvals'         => 'nullable|string',
            'key_benefits'      => 'nullable|string',
            'is_active'         => 'boolean',
            'is_featured'       => 'boolean',
            'image'             => 'nullable|image|max:4096',
            'gallery_img_1'     => 'nullable|image|max:4096',
            'gallery_img_2'     => 'nullable|image|max:4096',
            'video_url'         => 'nullable|url|max:500',
        ]);

        $data['is_active']   = $request->boolean('is_active');
        $data['is_featured'] = $request->boolean('is_featured');
        $data['key_benefits'] = $request->filled('key_benefits')
            ? array_values(array_filter(array_map('trim', explode("\n", $request->key_benefits))))
            : null;

        if ($request->hasFile('image')) {
            if ($product->image) Storage::disk('public')->delete($product->image);
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $gallery = $product->gallery ?? [];
        if ($request->hasFile('gallery_img_1')) {
            if (isset($gallery[0])) Storage::disk('public')->delete($gallery[0]);
            $gallery[0] = $request->file('gallery_img_1')->store('products', 'public');
        }
        if ($request->hasFile('gallery_img_2')) {
            if (isset($gallery[1])) Storage::disk('public')->delete($gallery[1]);
            $gallery[1] = $request->file('gallery_img_2')->store('products', 'public');
        }
        $data['gallery'] = array_values(array_filter($gallery)) ?: null;

        unset($data['gallery_img_1'], $data['gallery_img_2']);

        $product->update($data);
        $product->crossSells()->sync($request->input('cross_sells', []));
        return redirect()->route('admin.products.index')->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product)
    {
        if ($product->image) Storage::disk('public')->delete($product->image);
        $product->delete();
        return back()->with('success', 'Product deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        if ($request->boolean('delete_all')) {
            $products = Product::all();
            foreach ($products as $p) {
                if ($p->image) Storage::disk('public')->delete($p->image);
            }
            $count = $products->count();
            Product::query()->delete();
        } else {
            $ids = array_filter((array) $request->input('product_ids', []), 'is_numeric');
            if (empty($ids)) {
                return redirect()->route('admin.products.index')
                    ->with('error', 'No products selected.');
            }
            $products = Product::whereIn('id', $ids)->get();
            foreach ($products as $p) {
                if ($p->image) Storage::disk('public')->delete($p->image);
            }
            $count = $products->count();
            Product::whereIn('id', $ids)->delete();
        }

        return redirect()->route('admin.products.index')
            ->with('success', $count . ' product' . ($count === 1 ? '' : 's') . ' deleted.');
    }

    public function toggleStatus(Product $product)
    {
        $product->update(['is_active' => !$product->is_active]);
        return back()->with('success', 'Product status updated.');
    }
}
