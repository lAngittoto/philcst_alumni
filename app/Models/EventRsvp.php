<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRsvp extends Model
{
    protected $table = 'event_rsvps';

    protected $fillable = [
        'event_id',
        'alumni_id',
        'response',
        'message',
    ];

    // ── Relationships ───────────────────────────────────────

    public function event(): BelongsTo
    {
        return $this->belongsTo(OrganizerEvent::class, 'event_id');
    }

    public function alumni(): BelongsTo
    {
        return $this->belongsTo(Alumni::class, 'alumni_id');
    }

    // ── Constants ───────────────────────────────────────────

    const CONFIRMED = 'CONFIRMED';
    const DECLINED  = 'DECLINED';
    const TENTATIVE = 'TENTATIVE';

    // ── Helpers ─────────────────────────────────────────────

    public function getLabelAttribute(): string
    {
        return match($this->response) {
            self::CONFIRMED => 'Going',
            self::DECLINED  => 'Not Going',
            self::TENTATIVE => 'Maybe',
            default         => $this->response,
        };
    }
}