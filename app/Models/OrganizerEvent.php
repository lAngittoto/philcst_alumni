<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class OrganizerEvent extends Model
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
        // Audit trail
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

    // ── Default photo path ──────────────────────────────────
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

    public function scopeForOrganizer($query, int $organizerId)
    {
        return $query->where('organizer_id', $organizerId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'PENDING');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'APPROVED');
    }

    // ── Accessors ───────────────────────────────────────────

    /**
     * Returns the public URL of the event photo.
     * Falls back to the default photo if none is stored.
     */
    public function getPhotoUrlAttribute(): string
    {
        if ($this->photo && Storage::disk('public')->exists($this->photo)) {
            return asset('storage/' . $this->photo);
        }

        return asset('storage/' . self::DEFAULT_PHOTO);
    }

    /**
     * Badge CSS classes based on status.
     */
    public function getStatusBadgeClassAttribute(): string
    {
        return match($this->status) {
            'PENDING'  => 'bg-yellow-100 text-yellow-800',
            'APPROVED' => 'bg-green-100 text-green-700',
            'REJECTED' => 'bg-red-100 text-red-700',
            default    => 'bg-slate-100 text-slate-600',
        };
    }

    // ── Audit helpers ────────────────────────────────────────

    /**
     * True if the event was edited after creation.
     */
    public function getWasEditedAttribute(): bool
    {
        return $this->updated_by !== null;
    }

    /**
     * Human-readable label for who last updated and in what role.
     */
    public function getUpdatedByLabelAttribute(): ?string
    {
        if (!$this->updated_by) return null;

        $role = match($this->updated_by_role) {
            'admin'     => 'Admin',
            'organizer' => 'Organizer',
            default     => ucfirst($this->updated_by_role ?? 'Unknown'),
        };

        return "{$this->updated_by} ({$role})";
    }

    /**
     * Human-readable label for who deleted the event.
     */
    public function getDeletedByLabelAttribute(): ?string
    {
        if (!$this->deleted_by) return null;

        $role = match($this->deleted_by_role) {
            'admin'     => 'Admin',
            'organizer' => 'Organizer',
            default     => ucfirst($this->deleted_by_role ?? 'Unknown'),
        };

        return "{$this->deleted_by} ({$role})";
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

    public function getRsvpSummaryAttribute(): array
    {
        return $this->rsvps()
            ->selectRaw('response, COUNT(*) as total')
            ->groupBy('response')
            ->pluck('total', 'response')
            ->toArray();
    }
}