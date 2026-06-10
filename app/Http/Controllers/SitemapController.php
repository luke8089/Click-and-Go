<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\JobListing;

class SitemapController extends Controller
{
    public function index()
    {
        $products   = Product::where('is_active', true)->select('slug', 'image', 'name', 'updated_at')->get();
        $categories = Category::where('is_active', true)->select('slug', 'updated_at')->get();
        $jobs       = class_exists(JobListing::class)
                        ? JobListing::where('is_active', true)->select('slug', 'updated_at')->get()
                        : collect();

        return response()
            ->view('sitemap', compact('products', 'categories', 'jobs'))
            ->header('Content-Type', 'application/xml');
    }
}
