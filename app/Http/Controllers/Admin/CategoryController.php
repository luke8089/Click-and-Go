<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::withCount('products')->orderBy('sort_order')->paginate(20);
        return view('admin.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('admin.categories.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100|unique:categories,name',
            'description' => 'nullable|string|max:500',
            'sort_order'  => 'nullable|integer|min:0',
            'is_active'   => 'boolean',
            'image'       => 'nullable|image|max:2048',
        ]);

        $data['slug']      = Str::slug($data['name']);
        $data['is_active'] = $request->boolean('is_active', true);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('categories', 'public');
        }

        Category::create($data);
        return redirect()->route('admin.categories.index')->with('success', 'Category created.');
    }

    public function edit(Category $category)
    {
        return view('admin.categories.edit', compact('category'));
    }

    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100|unique:categories,name,' . $category->id,
            'description' => 'nullable|string|max:500',
            'sort_order'  => 'nullable|integer|min:0',
            'is_active'   => 'boolean',
            'image'       => 'nullable|image|max:2048',
        ]);

        $data['is_active'] = $request->boolean('is_active');

        if ($request->hasFile('image')) {
            if ($category->image) Storage::disk('public')->delete($category->image);
            $data['image'] = $request->file('image')->store('categories', 'public');
        }

        $category->update($data);
        return redirect()->route('admin.categories.index')->with('success', 'Category updated.');
    }

    public function destroy(Category $category)
    {
        if ($category->products()->count() > 0) {
            return back()->with('error', 'Cannot delete category with products.');
        }
        if ($category->image) Storage::disk('public')->delete($category->image);
        $category->delete();
        return back()->with('success', 'Category deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        if ($request->boolean('delete_all')) {
            $categories = Category::withCount('products')->get();
        } else {
            $ids = array_filter((array) $request->input('category_ids', []), 'is_numeric');
            if (empty($ids)) {
                return redirect()->route('admin.categories.index')->with('error', 'No categories selected.');
            }
            $categories = Category::withCount('products')->whereIn('id', $ids)->get();
        }

        $skipped = 0;
        $count   = 0;
        foreach ($categories as $c) {
            if ($c->products_count > 0) { $skipped++; continue; }
            if ($c->image) Storage::disk('public')->delete($c->image);
            $c->delete();
            $count++;
        }

        $msg = $count . ' categor' . ($count === 1 ? 'y' : 'ies') . ' deleted.';
        if ($skipped > 0) $msg .= ' ' . $skipped . ' skipped (has products).';
        $type = ($count === 0 && $skipped > 0) ? 'error' : 'success';

        return redirect()->route('admin.categories.index')->with($type, $msg);
    }
}
