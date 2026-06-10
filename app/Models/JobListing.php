<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class JobListing extends Model
{
    protected $fillable = [
        'title', 'slug', 'department', 'location', 'type',
        'description', 'requirements', 'responsibilities',
        'salary_range', 'is_active', 'expires_at', 'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    public function isOpen(): bool
    {
        return $this->is_active && (!$this->expires_at || $this->expires_at->isFuture());
    }

    public function getTypeLabel(): string
    {
        return match($this->type) {
            'full-time'   => 'Full-Time',
            'part-time'   => 'Part-Time',
            'contract'    => 'Contract',
            'internship'  => 'Internship',
            default       => ucfirst($this->type),
        };
    }

    public function getTypeBadgeColor(): string
    {
        return match($this->type) {
            'full-time'  => '#1d4ed8',
            'part-time'  => '#7c3aed',
            'contract'   => '#b45309',
            'internship' => '#065f46',
            default      => '#374151',
        };
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($job) {
            if (empty($job->slug)) {
                $job->slug = Str::slug($job->title);
            }
        });
    }
}
