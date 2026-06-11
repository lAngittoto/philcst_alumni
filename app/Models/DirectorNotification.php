<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectorNotification extends Model
{
    use HasFactory;

    protected $table = 'director_notifications';

    protected $fillable = [
        'director_id',
        'icon',
        'title',
        'message',
        'link_route',
        'link_label',
        'dedup_key',
        'count',
        'read',
    ];

    protected $casts = [
        'read'  => 'boolean',
        'count' => 'integer',
    ];

    // ──────────────────────────────────────────────────────────────────────────
    //  Relationships
    // ──────────────────────────────────────────────────────────────────────────

    public function director(): BelongsTo
    {
        return $this->belongsTo(Director::class);
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Scopes
    // ──────────────────────────────────────────────────────────────────────────

    /** Only unread notifications. */
    public function scopeUnread($query)
    {
        return $query->where('read', false);
    }

    /** Only read notifications. */
    public function scopeRead($query)
    {
        return $query->where('read', true);
    }

    /** Belonging to a specific director. */
    public function scopeForDirector($query, int $directorId)
    {
        return $query->where('director_id', $directorId);
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Create or increment a notification using its dedup_key.
     *
     * When a dedup_key already exists for today, the existing record's count
     * is incremented and it is marked unread again.  Otherwise a fresh row
     * is inserted.
     *
     * Usage:
     *   DirectorNotification::createOrIncrement($directorId, [
     *       'icon'       => 'users-gear',
     *       'title'      => 'Coordinator Update',
     *       'message'    => 'John Doe account has been updated.',
     *       'link_route' => 'director.coordinator/management',
     *       'link_label' => 'View Coordinators',
     *       'dedup_key'  => 'coordinator::42',
     *   ]);
     */
    public static function createOrIncrement(int $directorId, array $data): self
    {
        $dedupKey = $data['dedup_key'] ?? null;

        if ($dedupKey) {
            $existing = static::where('director_id', $directorId)
                ->where('dedup_key', $dedupKey)
                ->whereDate('created_at', today())
                ->first();

            if ($existing) {
                $existing->increment('count');
                $existing->update([
                    'read'    => false,
                    'message' => $data['message'] ?? $existing->message,
                    'title'   => $data['title']   ?? $existing->title,
                ]);
                return $existing->fresh();
            }
        }

        return static::create(array_merge($data, [
            'director_id' => $directorId,
            'count'       => 1,
            'read'        => false,
        ]));
    }
}