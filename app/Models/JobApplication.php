<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplication extends Model
{
    protected $fillable = [
        'job_listing_id', 'name', 'email', 'phone',
        'cover_letter', 'resume_path', 'status', 'admin_notes',
    ];

    public function jobListing(): BelongsTo
    {
        return $this->belongsTo(JobListing::class);
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            'pending'     => 'Pending',
            'reviewed'    => 'Reviewed',
            'shortlisted' => 'Shortlisted',
            'rejected'    => 'Rejected',
            default       => ucfirst($this->status),
        };
    }

    public function getStatusColor(): string
    {
        return match($this->status) {
            'pending'     => '#d97706',
            'reviewed'    => '#2563eb',
            'shortlisted' => '#16a34a',
            'rejected'    => '#dc2626',
            default       => '#6b7280',
        };
    }

    public function getStatusBg(): string
    {
        return match($this->status) {
            'pending'     => '#fffbeb',
            'reviewed'    => '#eff6ff',
            'shortlisted' => '#f0fdf4',
            'rejected'    => '#fef2f2',
            default       => '#f9fafb',
        };
    }
}
