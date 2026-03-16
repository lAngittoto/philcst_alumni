<?php
// FILE: app/Models/JobPosting.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobPosting extends Model
{
    use SoftDeletes;

    protected $table = 'job_postings';

    protected $fillable = [
        'organizer_id',
        'job_title',
        'company_name',
        'company_type',
        'location',
        'employment_type',
        'experience_level',
        'salary',
        'deadline',
        'description',
        'target_college',
        'status',
        'updated_by',
        'updated_by_role',
        'deleted_by',
        'deleted_by_role',
    ];

    protected $casts = [
        'deadline' => 'date',
    ];

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class, 'organizer_id');
    }

    /**
     * Scope: only organizer-visible statuses (excludes ORGANIZER_DELETED).
     */
    public function scopeVisibleToOrganizer($query): mixed
    {
        return $query->whereIn('status', ['ACTIVE', 'INACTIVE']);
    }

    /**
     * Scope: admin sees everything including ORGANIZER_DELETED (non-hard-deleted).
     */
    public function scopeForAdmin($query): mixed
    {
        return $query; // no extra filter — admin sees all
    }
}