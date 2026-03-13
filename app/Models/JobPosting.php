<?php

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
        // ✅ FIX: these were missing — Eloquent was silently discarding them
        'updated_by',
        'updated_by_role',
    ];

    protected $casts = [
        'deadline' => 'date',
    ];

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class, 'organizer_id');
    }
}