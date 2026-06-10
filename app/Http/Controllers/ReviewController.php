<?php

namespace App\Http\Controllers;

use App\Mail\NewReviewAdminMail;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class ReviewController extends Controller
{
    public function store(Request $request, Product $product)
    {
        // Rate limit: 5 reviews per 10 minutes per IP
        $rateLimitKey = 'review:' . $request->ip();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return redirect()->route('shop.show', $product)
                ->with('review_error', 'Too many review submissions. Please wait ' . ceil($seconds / 60) . ' minute(s) before trying again.');
        }

        // Idempotency: one review per product per authenticated user
        if (auth()->check()) {
            $existing = Review::where('product_id', $product->id)
                ->where('user_id', auth()->id())
                ->first();
            if ($existing) {
                return redirect()->route('shop.show', $product)
                    ->with('review_error', 'You have already submitted a review for this product.');
            }
        }

        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title'  => 'nullable|string|max:150',
            'body'   => 'required|string|max:2000',
            'name'   => 'required|string|max:100',
            'email'  => 'required|email|max:150',
        ]);

        $data['product_id']  = $product->id;
        $data['user_id']     = auth()->id();
        $data['is_approved'] = false;

        $review = Review::create($data);
        RateLimiter::hit($rateLimitKey, 600);

        try {
            Mail::to(config('mail.from.address'))->send(new NewReviewAdminMail($review->load('product')));
        } catch (\Exception) {}

        return redirect()
            ->route('shop.show', $product)
            ->with('review_success', 'Thank you! Your review has been submitted and is pending approval.');
    }

    // ── Admin ──────────────────────────────────────────────────────────────
    public function adminApprove(Review $review)
    {
        $review->update(['is_approved' => !$review->is_approved]);
        return back()->with('success', 'Review status updated.');
    }

    public function adminDestroy(Review $review)
    {
        $review->delete();
        return back()->with('success', 'Review deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        if ($request->boolean('delete_all')) {
            $count = Review::count();
            Review::query()->delete();
        } else {
            $ids = array_filter((array) $request->input('review_ids', []), 'is_numeric');
            if (empty($ids)) {
                return back()->with('error', 'No reviews selected.');
            }
            $count = Review::whereIn('id', $ids)->delete();
        }

        return back()->with('success', $count . ' review' . ($count === 1 ? '' : 's') . ' deleted.');
    }
}
