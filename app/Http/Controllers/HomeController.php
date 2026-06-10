<?php

namespace App\Http\Controllers;

use App\Mail\ContactAdminAlertMail;
use App\Mail\ContactAutoReplyMail;
use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\Product;
use App\Models\Testimonial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class HomeController extends Controller
{
    public function index()
    {
        $categories   = Category::where('is_active', true)->orderBy('sort_order')->get();
        $featured     = Product::where('is_featured', true)->where('is_active', true)->with('category')->take(6)->get();
        $bestSellers  = Product::where('is_active', true)->with('category')->orderByDesc('id')->take(6)->get();
        $testimonials = Testimonial::where('is_approved', true)->latest()->get();
        return view('home', compact('categories', 'featured', 'bestSellers', 'testimonials'));
    }

    public function about()
    {
        return view('about');
    }

    public function contact()
    {
        return view('contact');
    }

    public function faqs()
    {
        return view('faqs');
    }

    public function sendContact(Request $request)
    {
        // Honeypot — bots fill hidden fields, humans don't
        if ($request->filled('website')) {
            return back()->with('success', 'Message sent successfully! We will get back to you within 24 hours.');
        }

        // Rate limit: 3 submissions per IP per 5 minutes
        $key = 'contact:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            $minutes = ceil($seconds / 60);
            return back()
                ->withInput()
                ->withErrors(['rate_limit' => "Too many messages sent. Please wait {$minutes} minute(s) before trying again."]);
        }

        $allowed_subjects = ['General Inquiry', 'Product Question', 'Bulk Order', 'Order Support', 'Technical Support', 'Other'];

        $validated = $request->validate([
            'name'    => 'required|string|min:2|max:100',
            'email'   => 'required|email:rfc|max:150',
            'phone'   => ['nullable', 'string', 'max:20', 'regex:/^[+\d][\d\s\-\(\)]{5,18}\d$/'],
            'subject' => 'required|string|in:' . implode(',', $allowed_subjects),
            'message' => 'required|string|min:10|max:2000',
        ]);

        // Strip HTML tags from free-text fields
        $validated['name']    = strip_tags($validated['name']);
        $validated['message'] = strip_tags($validated['message']);

        // Reject messages with more than 2 URLs (likely spam)
        if (substr_count(strtolower($validated['message']), 'http') > 2) {
            return back()
                ->withInput()
                ->withErrors(['message' => 'Your message contains too many links. Please remove some and try again.']);
        }

        // Record the attempt after all checks pass
        RateLimiter::hit($key, 300);

        ContactMessage::create([
            'name'    => $validated['name'],
            'email'   => $validated['email'],
            'phone'   => $validated['phone'] ?? null,
            'subject' => $validated['subject'],
            'message' => $validated['message'],
        ]);

        try {
            Mail::to($validated['email'])->send(new ContactAutoReplyMail(
                name:           $validated['name'],
                contactSubject: $validated['subject'],
            ));
            Mail::to(config('mail.from.address'))->send(new ContactAdminAlertMail(
                name:           $validated['name'],
                email:          $validated['email'],
                contactSubject: $validated['subject'],
                message:        $validated['message'],
                phone:          $validated['phone'] ?? null,
            ));
        } catch (\Exception) {}

        return back()->with('success', 'Message sent successfully! We will get back to you within 24 hours.');
    }

    public function privacyPolicy()
    {
        return view('privacy-policy');
    }

    public function termsConditions()
    {
        return view('terms-conditions');
    }
}
