<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizerJob extends Model
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

    // ── Relationships ──────────────────────────────────────────

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class, 'organizer_id');
    }

    // ── Scopes ────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'ACTIVE');
    }

    public function scopeForOrganizer($query, int $organizerId)
    {
        return $query->where('organizer_id', $organizerId);
    }

    // ── Accessors ─────────────────────────────────────────────

    public function getIsExpiredAttribute(): bool
    {
        return now()->gt($this->deadline);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            'ACTIVE'   => 'bg-emerald-100 text-emerald-700',
            'INACTIVE' => 'bg-amber-100 text-amber-700',
            'EXPIRED'  => 'bg-red-100 text-red-600',
            default    => 'bg-slate-100 text-slate-600',
        };
    }
}