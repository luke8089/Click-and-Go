<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id', 'product_type', 'name', 'slug', 'sku', 'description', 'short_description',
        'price', 'sale_price', 'wholesale_price', 'stock', 'image', 'gallery', 'video_url', 'brand',
        'viscosity_grade', 'pack_size', 'part_number', 'compatibility',
        'specifications', 'applications', 'approvals',
        'key_benefits', 'is_active', 'is_featured', 'sort_order'
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_featured'=> 'boolean',
        'gallery'      => 'array',
        'specifications' => 'array',
        'key_benefits'   => 'array',
        'price'           => 'decimal:2',
        'sale_price'      => 'decimal:2',
        'wholesale_price' => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    public function wishlistItems()
    {
        return $this->hasMany(Wishlist::class);
    }

    public function crossSells()
    {
        return $this->belongsToMany(Product::class, 'product_cross_sells', 'product_id', 'cross_sell_id');
    }

    public function getCurrentPriceAttribute()
    {
        return $this->sale_price && $this->sale_price < $this->price
            ? $this->sale_price
            : $this->price;
    }

    public function getAverageRatingAttribute()
    {
        return $this->reviews()->where('is_approved', true)->avg('rating') ?? 0;
    }

    public function getReviewCountAttribute()
    {
        return $this->reviews()->where('is_approved', true)->count();
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($product) {
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }
}
