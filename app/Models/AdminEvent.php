<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * AdminEvent — same underlying `events` table, admin-facing model.
 * Admin can see ALL events (any organizer), approve/reject, create own.
 */
class AdminEvent extends Model
{
    use SoftDeletes;

    protected $table = 'events';

    protected $fillable = [
        'organizer_id',
        'title',
        'description',
        'photo',
        'event_date',
        'event_end_date',
        'venue',
        'venue_address',
        'target_participants',
        'contact_person',
        'contact_email',
        'contact_phone',
        'notes',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_remarks',
        'updated_by',
        'updated_by_role',
        'deleted_by',
        'deleted_by_role',
    ];

    protected $casts = [
        'event_date'     => 'datetime',
        'event_end_date' => 'datetime',
        'reviewed_at'    => 'datetime',
    ];

    const DEFAULT_PHOTO = 'event/default-photo-event.jpg';

    // ── Relationships ───────────────────────────────────────

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class, 'organizer_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(EventRsvp::class, 'event_id');
    }

    // ── Scopes ──────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'APPROVED');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'REJECTED');
    }

    // ── Accessors ───────────────────────────────────────────

    public function getPhotoUrlAttribute(): string
    {
        if ($this->photo && Storage::disk('public')->exists($this->photo)) {
            return asset('storage/' . $this->photo);
        }
        return asset('storage/' . self::DEFAULT_PHOTO);
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match($this->status) {
            'PENDING'  => 'bg-yellow-100 text-yellow-800',
            'APPROVED' => 'bg-green-100 text-green-700',
            'REJECTED' => 'bg-red-100 text-red-700',
            default    => 'bg-slate-100 text-slate-600',
        };
    }

    public function getWasEditedAttribute(): bool
    {
        return $this->updated_by !== null;
    }

    // ── RSVP helpers ────────────────────────────────────────

    public function getConfirmedCountAttribute(): int
    {
        return $this->rsvps()->where('response', EventRsvp::CONFIRMED)->count();
    }

    public function getDeclinedCountAttribute(): int
    {
        return $this->rsvps()->where('response', EventRsvp::DECLINED)->count();
    }

    public function getTentativeCountAttribute(): int
    {
        return $this->rsvps()->where('response', EventRsvp::TENTATIVE)->count();
    }
}