<?php

namespace App\Http\Controllers;

use App\Mail\JobApplicationAdminMail;
use App\Mail\JobApplicationConfirmationMail;
use App\Models\JobListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class CareersController extends Controller
{
    public function index(Request $request)
    {
        $query = JobListing::where('is_active', true)
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('sort_order')
            ->orderByDesc('created_at');

        if ($request->filled('department')) {
            $query->where('department', $request->department);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $jobs        = $query->withCount('applications')->get();
        $departments = JobListing::where('is_active', true)
            ->whereNotNull('department')
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->distinct()
            ->orderBy('department')
            ->pluck('department');

        return view('careers.index', compact('jobs', 'departments'));
    }

    public function show(JobListing $career)
    {
        abort_if(!$career->isOpen(), 404);

        return view('careers.show', ['job' => $career]);
    }

    public function loginToApply(JobListing $career)
    {
        abort_if(!$career->isOpen(), 404);

        session(['url.intended' => route('careers.show', $career)]);

        return redirect()->route('login');
    }

    public function apply(Request $request, JobListing $career)
    {
        abort_if(!$career->isOpen(), 404);

        $validated = $request->validate([
            'name'         => 'required|string|max:100',
            'email'        => 'required|email|max:150',
            'phone'        => 'nullable|string|max:30',
            'cover_letter' => 'required|string|min:50|max:3000',
            'resume'       => 'nullable|file|mimes:pdf,doc,docx|max:3072',
        ]);

        $resumePath = null;
        if ($request->hasFile('resume')) {
            $resumePath = $request->file('resume')->store('resumes', 'local');
        }

        $application = $career->applications()->create([
            'name'         => strip_tags($validated['name']),
            'email'        => $validated['email'],
            'phone'        => isset($validated['phone']) ? strip_tags($validated['phone']) : null,
            'cover_letter' => strip_tags($validated['cover_letter']),
            'resume_path'  => $resumePath,
            'status'       => 'pending',
        ]);

        try {
            $adminEmail = \App\Models\Setting::get('admin_email', config('mail.from.address'));
            Mail::to($adminEmail)->send(new JobApplicationAdminMail($application, $career));
        } catch (\Throwable) {}

        try {
            Mail::to($application->email)->send(new JobApplicationConfirmationMail($application, $career));
        } catch (\Throwable) {}

        return back()->with('applied', 'Your application has been submitted successfully. We will review it and get back to you soon.');
    }
}
