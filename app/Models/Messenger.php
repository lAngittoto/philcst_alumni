<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// ════════════════════════════════════════════════════════════════════════════
//  ChatRoom
// ════════════════════════════════════════════════════════════════════════════

class ChatRoom extends Model
{
    protected $table = 'chat_rooms';

    protected $fillable = ['name', 'course_code', 'batch', 'department'];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'room_id');
    }

    public function pins(): HasMany
    {
        return $this->hasMany(ChatPin::class, 'room_id');
    }

    public function onlineCount(): int
    {
        try {
            return \DB::table('alumni')
                ->where('course_code', $this->course_code)
                ->where('batch', $this->batch)
                ->whereNull('deleted_at')
                ->where('last_seen_at', '>=', now()->subMinutes(5))
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function memberCount(): int
    {
        return \DB::table('alumni')
            ->where('course_code', $this->course_code)
            ->where('batch', $this->batch)
            ->whereNull('deleted_at')
            ->count();
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  ChatMessage
// ════════════════════════════════════════════════════════════════════════════

class ChatMessage extends Model
{
    use SoftDeletes;

    protected $table = 'chat_messages';

    protected $fillable = [
        'room_id',
        'sender_type',
        'sender_id',
        'body',
        'reply_to_id',
        'edited_at',
    ];

    protected $casts = [
        'edited_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function room(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class, 'room_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'reply_to_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ChatReaction::class, 'message_id');
    }

    public function pin(): HasOne
    {
        return $this->hasOne(ChatPin::class, 'message_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(ChatMention::class, 'message_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeForRoom($query, int $roomId)
    {
        return $query->where('room_id', $roomId)->orderBy('created_at');
    }

    public function scopeByAlumni($query, int $alumniId)
    {
        return $query->where('sender_type', 'alumni')->where('sender_id', $alumniId);
    }

    public function scopeByOrganizer($query, int $organizerId)
    {
        return $query->where('sender_type', 'organizer')->where('sender_id', $organizerId);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function isEdited(): bool
    {
        return ! is_null($this->edited_at);
    }

    public function isPinned(): bool
    {
        return $this->pin()->exists();
    }

    public function reactionCounts(): array
    {
        return $this->reactions()
            ->selectRaw('reaction, COUNT(*) as cnt')
            ->groupBy('reaction')
            ->pluck('cnt', 'reaction')
            ->toArray();
    }

    public function myReaction(string $reactorType, int $reactorId): ?string
    {
        return $this->reactions()
            ->where('reactor_type', $reactorType)
            ->where('reactor_id', $reactorId)
            ->value('reaction');
    }

    public function toggleReaction(int $alumniId, string $reaction): ?string
    {
        $allowed = ['heart', 'purple', 'like', 'dislike'];
        if (! in_array($reaction, $allowed, true)) return null;

        $existing = $this->reactions()
            ->where('reactor_type', 'alumni')
            ->where('reactor_id', $alumniId)
            ->first();

        if ($existing) {
            if ($existing->reaction === $reaction) {
                $existing->delete();
                return null;
            }
            $existing->update(['reaction' => $reaction, 'updated_at' => now()]);
            return $reaction;
        }

        $this->reactions()->create([
            'reactor_type' => 'alumni',
            'reactor_id'   => $alumniId,
            'reaction'     => $reaction,
        ]);

        return $reaction;
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  ChatReaction
// ════════════════════════════════════════════════════════════════════════════

class ChatReaction extends Model
{
    protected $table = 'chat_reactions';

    protected $fillable = ['message_id', 'reactor_type', 'reactor_id', 'reaction'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  ChatPin
// ════════════════════════════════════════════════════════════════════════════

class ChatPin extends Model
{
    protected $table = 'chat_pins';

    protected $fillable = ['room_id', 'message_id', 'pinned_by_type', 'pinned_by_id'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  ChatMention
// ════════════════════════════════════════════════════════════════════════════

class ChatMention extends Model
{
    protected $table = 'chat_mentions';

    protected $fillable = ['message_id', 'mention_type', 'mentioned_id'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }
}