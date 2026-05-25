<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlumniNotification extends Model
{
    protected $fillable = [
        'alumni_id',
        'icon',
        'title',
        'dedup_key',
        'message',
        'link_route',
        'link_label',
        'link_params',
        'read',
        'count',
    ];

    protected $casts = [
        'link_params' => 'array',
        'read'        => 'boolean',
        'count'       => 'integer',
        'alumni_id'   => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function alumni(): BelongsTo
    {
        return $this->belongsTo(Alumni::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeUnread($query)
    {
        return $query->where('read', false);
    }

    public function scopeForAlumni($query, int $alumniId)
    {
        return $query->where('alumni_id', $alumniId);
    }

    public function scopeJobs($query)
    {
        return $query->where('icon', 'briefcase');
    }

    // NOTE: Do NOT name this scopeLatest — it shadows Eloquent's built-in latest().
    public function scopeNewest($query)
    {
        return $query->orderByDesc('created_at');
    }
}