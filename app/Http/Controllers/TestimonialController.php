<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Testimonial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class TestimonialController extends Controller
{
    public function store(Request $request)
    {
        // Rate limit: 3 testimonials per hour per user
        $rateLimitKey = 'testimonial:' . ($request->user()?->id ?? $request->ip());
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return redirect()->to(route('home') . '#testimonials')
                ->with('error', 'Too many submissions. Please wait ' . ceil($seconds / 60) . ' minute(s) before trying again.');
        }

        // Idempotency: one testimonial per authenticated user
        if (auth()->check()) {
            $existing = Testimonial::where('user_id', auth()->id())->first();
            if ($existing) {
                return redirect()->to(route('home') . '#testimonials')
                    ->with('error', 'You have already submitted a testimonial.');
            }
        }

        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'occupation' => 'nullable|string|max:100',
            'message'    => 'required|string|max:1000',
            'rating'     => 'required|integer|min:1|max:5',
        ]);

        $data['user_id']     = auth()->id();
        $data['is_approved'] = false;

        Testimonial::create($data);
        RateLimiter::hit($rateLimitKey, 3600);

        return redirect()->to(route('home') . '#testimonials')->with('success', 'Thank you! Your testimonial has been submitted and is awaiting approval.');
    }

    // ── Admin ──────────────────────────────────────────────────────────────────
    public function adminIndex()
    {
        $testimonials = Testimonial::latest()->get();
        $reviews      = Review::with('product')->latest()->get();
        return view('admin.testimonials.index', compact('testimonials', 'reviews'));
    }

    public function approve(Testimonial $testimonial)
    {
        $testimonial->update(['is_approved' => !$testimonial->is_approved]);
        return back()->with('success', 'Testimonial status updated.');
    }

    public function destroy(Testimonial $testimonial)
    {
        $testimonial->delete();
        return back()->with('success', 'Testimonial deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        if ($request->boolean('delete_all')) {
            $count = Testimonial::count();
            Testimonial::query()->delete();
        } else {
            $ids = array_filter((array) $request->input('testimonial_ids', []), 'is_numeric');
            if (empty($ids)) {
                return back()->with('error', 'No testimonials selected.');
            }
            $count = Testimonial::whereIn('id', $ids)->delete();
        }

        return back()->with('success', $count . ' testimonial' . ($count === 1 ? '' : 's') . ' deleted.');
    }
}
