<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\JobListing;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class JobListingController extends Controller
{
    public function index(Request $request)
    {
        $query = JobListing::withCount('applications')->orderBy('sort_order')->orderByDesc('created_at');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q->where('title', 'like', "%$s%")->orWhere('department', 'like', "%$s%")->orWhere('location', 'like', "%$s%"));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        $jobs  = $query->paginate(20)->withQueryString();
        $total = JobListing::count();

        return view('admin.careers.index', compact('jobs', 'total'));
    }

    public function create()
    {
        return view('admin.careers.form', ['job' => null]);
    }

    public function store(Request $request)
    {
        $data = $this->validateJob($request);
        JobListing::create($data);

        return redirect()->route('admin.careers.index')->with('success', 'Job listing created.');
    }

    public function edit(JobListing $career)
    {
        return view('admin.careers.form', ['job' => $career]);
    }

    public function update(Request $request, JobListing $career)
    {
        $data = $this->validateJob($request, $career);
        $career->update($data);

        return redirect()->route('admin.careers.index')->with('success', 'Job listing updated.');
    }

    public function destroy(JobListing $career)
    {
        $career->delete();
        return redirect()->route('admin.careers.index')->with('success', 'Job listing deleted.');
    }

    public function toggle(JobListing $career)
    {
        $career->update(['is_active' => !$career->is_active]);
        return back()->with('success', 'Job status updated.');
    }

    public function bulkDestroy(Request $request)
    {
        if ($request->boolean('delete_all')) {
            $count = JobListing::count();
            JobListing::query()->delete();
        } else {
            $ids = array_filter((array) $request->input('job_ids', []), 'is_numeric');
            if (empty($ids)) {
                return redirect()->route('admin.careers.index')->with('error', 'No jobs selected.');
            }
            $count = JobListing::whereIn('id', $ids)->delete();
        }

        return redirect()->route('admin.careers.index')
            ->with('success', $count . ' job listing' . ($count === 1 ? '' : 's') . ' deleted.');
    }

    public function applications(Request $request, JobListing $career)
    {
        $query = $career->applications()->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $applications  = $query->paginate(25)->withQueryString();
        $statusCounts  = $career->applications()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('admin.careers.applications', compact('career', 'applications', 'statusCounts'));
    }

    public function updateApplicationStatus(Request $request, JobApplication $application)
    {
        $request->validate([
            'status'      => 'required|in:pending,reviewed,shortlisted,rejected',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        $application->update([
            'status'      => $request->status,
            'admin_notes' => $request->admin_notes,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $request->status]);
        }

        return back()->with('success', 'Application status updated.');
    }

    public function destroyApplication(JobApplication $application)
    {
        $careerId = $application->job_listing_id;
        $application->delete();
        return redirect()->route('admin.careers.applications', $careerId)->with('success', 'Application deleted.');
    }

    public function downloadResume(JobApplication $application)
    {
        abort_if(!$application->resume_path, 404);

        $path = storage_path('app/' . $application->resume_path);

        if (!file_exists($path)) {
            return back()->with('error', 'Resume file not found. It may have been deleted from the server.');
        }

        return response()->download($path);
    }

    private function validateJob(Request $request, ?JobListing $job = null): array
    {
        $uniqueSlug = 'required|string|max:200|unique:job_listings,slug' . ($job ? ',' . $job->id : '');

        $validated = $request->validate([
            'title'            => 'required|string|max:200',
            'slug'             => $uniqueSlug,
            'department'       => 'nullable|string|max:100',
            'location'         => 'required|string|max:150',
            'type'             => 'required|in:full-time,part-time,contract,internship',
            'description'      => 'required|string|min:20',
            'requirements'     => 'nullable|string',
            'responsibilities' => 'nullable|string',
            'salary_range'     => 'nullable|string|max:100',
            'is_active'        => 'boolean',
            'expires_at'       => 'nullable|date|after:today',
            'sort_order'       => 'integer|min:0',
        ]);

        $validated['is_active'] = $request->boolean('is_active');

        return $validated;
    }
}
