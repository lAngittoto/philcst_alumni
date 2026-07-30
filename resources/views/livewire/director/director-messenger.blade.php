{{-- resources/views/livewire/director/director-messenger.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

new class extends Component {

    // ── Single global room ────────────────────────────────────────────────
    public ?array $room   = null;
    public int    $roomId = 0;

    // ── Messages ──────────────────────────────────────────────────────────
    public array $messages = [];

    // ── Compose ───────────────────────────────────────────────────────────
    public string $body    = '';
    public ?array $replyTo = null;

    // ── Edit ──────────────────────────────────────────────────────────────
    public ?int   $editingId = null;
    public string $editBody  = '';

    // ── Side panels ───────────────────────────────────────────────────────
    public bool  $showMembers    = false;
    public bool  $showPins       = false;
    public array $directors      = [];
    public array $coordinators   = [];
    public array $pinnedMessages = [];

    // ── Member search ─────────────────────────────────────────────────────
    public string $memberSearch = '';

    // ── Online presence ───────────────────────────────────────────────────
    public int $onlineCount = 0;
    public int $totalCount  = 0;

    // ── @mention autocomplete ─────────────────────────────────────────────
    public array $mentionSuggestions = [];
    public bool  $showMentions       = false;

    // ── Typing indicator ──────────────────────────────────────────────────
    public array $typingUsers = [];

    // ── Current director ──────────────────────────────────────────────────
    public int    $directorId        = 0;
    public string $directorName      = '';
    public string $directorFirstName = '';
    public string $directorPhoto     = '';

    // ── View Reactions popup ──────────────────────────────────────────────
    public ?int  $reactionsPopupMsgId = null;
    public array $reactionsPopupData  = [];

    // ── Unread / watermark tracking ───────────────────────────────────────
    public int $lastNotifiedMessageId = 0;

    // ── Tick counter — drives staggered work inside the single poll ───────
    // SMOOTHNESS FIX (mirrors organizer/chat-alumni.blade.php): previously
    // this component ran TWO separate wire:poll timers (refreshAll every
    // 8000ms doing presence+notif+full message reload+online-count, and a
    // second poll every 3000ms just for typing). Two independent Livewire
    // polling requests fighting for the same request queue is exactly what
    // made sending/clicking feel like it stalls for a beat. Now there is
    // ONE poll (wire:poll.2500ms.visible) driving a single unifiedPoll()
    // that staggers its heavier steps across ticks, same rhythm as the
    // organizer side, so nothing blocks a user-initiated action.
    public int $pollTick = 0;

    // ── Room display name — single source of truth for the header/title ──
    public string $roomLabel = 'Staff Chat';

    // ─────────────────────────────────────────────────────────────────────
    // Cache key — MUST match director-notif-poller.blade.php
    // ─────────────────────────────────────────────────────────────────────
    private function lastNotifiedCacheKey(): string
    {
        // Unified key shared with the poller so both advance the same watermark
        return "chat_notified.director.{$this->directorId}.room.{$this->roomId}";
    }

    // ─────────────────────────────────────────────────────────────────────
    // Photo URL resolver
    // ─────────────────────────────────────────────────────────────────────
    private function resolvePhotoUrl(?string $path): string
    {
        $default = asset('storage/alumni-photos/default.png');

        if (! $path) return $default;

        if (
            str_starts_with($path, 'organizers/')    ||
            str_starts_with($path, 'alumni-photos/') ||
            str_starts_with($path, 'directors/')
        ) {
            return asset('storage/' . $path);
        }

        return $default;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Job/event image resolver — mirrors alumni-side messenger.blade.php
    // ─────────────────────────────────────────────────────────────────────
    private function resolvePostImage(?string $path): ?string
    {
        if (! $path) return null;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) return $path;
        return asset('storage/' . $path);
    }

    /**
     * ── Builds the "View Job" deep-link URL ─────────────────────────────────
     */
    private function jobsUrl(int $id): string
    {
        try {
            $path = route('job.opportunities', [], false);
        } catch (\Throwable) {
            $path = '/job/opportunities';
        }
        return $path . '?job=' . $id;
    }

    /**
     * ── Builds the "View Event" deep-link URL ───────────────────────────────
     */
    private function eventsUrl(int $id, string $type = 'ADMIN'): string
    {
        try {
            $path = route('upcoming.events', [], false);
        } catch (\Throwable) {
            $path = '/upcoming/events';
        }
        return $path . '?event=' . $id . '&type=' . $type;
    }

    /**
     * ── Messenger-style link preview for shared Jobs / Events — mirrors
     *    alumni-side messenger.blade.php so shared posts render as a
     *    styled card here too, instead of raw marker/plain text. ─────────
     */
    private function resolvePostPreview(?string $body): ?array
    {
        if (! $body) return null;

        // ── Job posting share marker: [[JOB:123]] ───────────────────────
        if (preg_match('/\[\[JOB:(\d+)\]\]/i', $body, $m)) {
            $id = (int) $m[1];
            try {
                $job = DB::table('job_postings')->where('id', $id)->first();
                if ($job) {
                    return [
                        'type'      => 'job',
                        'id'        => $id,
                        'title'     => $job->job_title ?? 'Job Opportunity',
                        'subtitle'  => $job->company_name ?? $job->location ?? '',
                        'image'     => $this->resolvePostImage($job->job_image ?? null),
                        'url'       => $this->jobsUrl($id),
                        'available' => true,
                    ];
                }
            } catch (\Throwable) {
                // table/columns not found — fall through to the
                // "unavailable" fallback card below.
            }

            return [
                'type'      => 'job',
                'id'        => $id,
                'title'     => 'Job posting no longer available',
                'subtitle'  => 'This job may have been removed or expired',
                'image'     => null,
                'url'       => $this->jobsUrl($id),
                'available' => false,
            ];
        }

        // ── Event share marker: [[EVENT:TYPE:123]] ──────────────────────
        if (preg_match('/\[\[EVENT:(ADMIN|ORGANIZER):(\d+)\]\]/i', $body, $m)) {
            $type = strtoupper($m[1]);
            $id   = (int) $m[2];

            $event = null;
            try {
                $event = $type === 'ADMIN'
                    ? \App\Models\AdminEvent::withoutTrashed()->where('id', $id)->first()
                    : \App\Models\OrganizerEvent::where('id', $id)->first();
            } catch (\Throwable) {
                $event = null;
            }

            if ($event) {
                $when     = $event->event_date ?? null;
                $endWhen  = $event->event_end_date ?? null;
                $image    = $event->photo_url ?? null;

                // ── Real-world logic: an event card should say "Completed"
                //    once it has actually happened, not "Save the Date"
                //    forever. Prefer the explicit status column when the
                //    underlying model tracks one (OrganizerEvent does —
                //    PENDING/APPROVED/REJECTED/COMPLETED); otherwise fall
                //    back to comparing event_end_date (or event_date if no
                //    end date was set) against the current time, which
                //    works the same way for AdminEvent too. ──────────────
                $eventStatus  = strtoupper((string) ($event->status ?? ''));
                $isCompleted  = $eventStatus === 'COMPLETED'
                    || ($endWhen && Carbon::parse($endWhen)->isPast())
                    || (! $endWhen && $when && Carbon::parse($when)->isPast());

                return [
                    'type'         => 'event',
                    'id'           => $id,
                    'event_type'   => $type,
                    'title'        => $event->title ?? 'Event',
                    'subtitle'     => $when ? Carbon::parse($when)->format('M d, Y') : ($event->venue ?? ''),
                    'image'        => $image,
                    'url'          => $this->eventsUrl($id, $type),
                    'available'    => true,
                    'is_completed' => $isCompleted,
                ];
            }

            return [
                'type'       => 'event',
                'id'         => $id,
                'event_type' => $type,
                'title'      => 'Event no longer available',
                'subtitle'   => 'This event may have been removed or expired',
                'image'      => null,
                'url'        => $this->eventsUrl($id, $type),
                'available'  => false,
            ];
        }

        return null;
    }

    /**
     * ── Friendly one-line preview text for pinned list / notifications ──
     */
    private function resolvePreviewText(?string $body): string
    {
        if (! $body) return '';

        if (preg_match('/\[\[JOB:(\d+)\]\]/i', $body, $m)) {
            $id = (int) $m[1];
            $title = null;
            try { $title = DB::table('job_postings')->where('id', $id)->value('job_title'); } catch (\Throwable) {}
            return $title ? ('📌 Shared a job: ' . $title) : '📌 Shared a job opening';
        }

        if (preg_match('/\[\[EVENT:(ADMIN|ORGANIZER):(\d+)\]\]/i', $body, $m)) {
            $id = (int) $m[2];
            $title = null;
            try { $title = DB::table('events')->where('id', $id)->whereNull('deleted_at')->value('title'); } catch (\Throwable) {}
            return $title ? ('📅 Shared an event: ' . $title) : '📅 Shared an event';
        }

        return $body;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Boot
    // ─────────────────────────────────────────────────────────────────────
    public function mount(): void
    {
        $user = Auth::user();

        if (! $user || $user->role !== 'director') {
            $this->redirect(route('login'));
            return;
        }

        $director = DB::table('director')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->first();

        if (! $director) {
            $this->redirect(route('login'));
            return;
        }

        $this->directorId        = $director->id;
        $this->directorName      = trim(($director->first_name ?? '') . ' ' . ($director->last_name ?? ''));
        $this->directorFirstName = $director->first_name ?? '';
        $this->directorPhoto     = $this->resolvePhotoUrl($director->profile_photo ?? null) ?? '';

        $this->ensureRoomExists();
        $this->pingPresence();
        $this->loadRoom();

        // Seed the watermark AFTER loadRoom() so roomId is set
        $this->seedNotifiedPointer();

        $this->loadMessages();
        $this->loadMembers();
        $this->refreshOnlineCount();
        $this->loadTypingIndicators();

        $this->dispatch('chat-scroll-bottom');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Seed notification pointer on mount
    // Only sets if not already cached — preserves poller's watermark
    // ─────────────────────────────────────────────────────────────────────
    private function seedNotifiedPointer(): void
    {
        if (! $this->roomId) return;

        $cached = Cache::get($this->lastNotifiedCacheKey());

        if ($cached === null) {
            // First visit — set pointer to current max so we don't flood old msgs
            $maxId = (int) (DB::table('chat_messages')
                ->where('room_id', $this->roomId)
                ->whereNull('deleted_at')
                ->max('id') ?? 0);
            $this->lastNotifiedMessageId = $maxId;
            Cache::put($this->lastNotifiedCacheKey(), $maxId, now()->addDays(30));
        } else {
            $this->lastNotifiedMessageId = (int) $cached;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ensure the one global director room exists
    // NOTE: the DB 'name' column keeps its original stored value ("Internal
    // Staff Chat") intentionally untouched — renaming the display label is
    // purely presentational via $roomLabel above, so no migration/backfill
    // is needed and other places reading chat_rooms.name (e.g. any admin
    // tooling) keep working unchanged.
    // ─────────────────────────────────────────────────────────────────────
    protected function ensureRoomExists(): void
    {
        try {
            $exists = DB::table('chat_rooms')
                ->where('course_code', '__director__')
                ->exists();

            if (! $exists) {
                DB::table('chat_rooms')->insert([
                    'name'        => 'Internal Staff Chat',
                    'course_code' => '__director__',
                    'batch'       => 0,
                    'department'  => 'ALL',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        } catch (\Throwable) {}
    }

    protected function loadRoom(): void
    {
        $row = DB::table('chat_rooms')
            ->where('course_code', '__director__')
            ->first();

        if ($row) {
            $this->roomId = $row->id;
            $this->room   = (array) $row;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // SINGLE UNIFIED POLL — wire:poll.2500ms.visible
    //
    // Replaces the old refreshAll() (8000ms) + refreshTyping() (3000ms)
    // dual-poll setup. Staggering the heavier steps across ticks keeps any
    // single tick cheap, and .visible means it fully pauses while the tab
    // is unfocused — same pattern as organizer/chat-alumni.blade.php.
    // ─────────────────────────────────────────────────────────────────────
    public function unifiedPoll(): void
    {
        $this->pollTick++;

        // Presence + typing are the cheapest / most time-sensitive, so
        // these run every tick (every ~2.5s).
        $this->pingPresence();
        $this->loadTypingIndicators();

        // Notification watermark + message reload are a bit heavier and
        // don't need sub-3s freshness — every other tick (~5s) is plenty.
        if ($this->pollTick % 2 === 0) {
            $this->checkAndDispatchNewMessageNotifications();
            $this->loadMessages();
            $this->dispatch('chat-scroll-bottom');
        }

        // Online/offline counts change the least often — every 4th tick
        // (~10s) keeps this from hammering director+organizer tables.
        if ($this->pollTick % 4 === 0) {
            $this->refreshOnlineCount();
            if ($this->showMembers) {
                $this->loadMembers();
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Detect new messages → advance shared watermark
    // (Messenger does NOT insert notif rows — that's the poller's job.
    //  It only advances the cache pointer so the poller skips already-seen msgs.)
    // ─────────────────────────────────────────────────────────────────────
    private function checkAndDispatchNewMessageNotifications(): void
    {
        if (! $this->roomId) return;

        if ($this->lastNotifiedMessageId === 0) {
            $this->seedNotifiedPointer();
            return;
        }

        // Re-sync from cache — poller may have already advanced it
        $cached = Cache::get($this->lastNotifiedCacheKey());
        if ($cached !== null) {
            $this->lastNotifiedMessageId = max($this->lastNotifiedMessageId, (int) $cached);
        }

        $lastKnown = $this->lastNotifiedMessageId;

        // All new messages (any sender) — we advance the pointer for all
        $globalMax = (int) (DB::table('chat_messages')
            ->where('room_id', $this->roomId)
            ->whereNull('deleted_at')
            ->where('id', '>', $lastKnown)
            ->max('id') ?? 0);

        if ($globalMax > $lastKnown) {
            $this->lastNotifiedMessageId = $globalMax;
            Cache::put($this->lastNotifiedCacheKey(), $globalMax, now()->addDays(30));
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Presence
    // ─────────────────────────────────────────────────────────────────────
    public function pingPresence(): void
    {
        try {
            DB::table('director')
                ->where('id', $this->directorId)
                ->update(['last_seen_at' => now()]);
        } catch (\Throwable) {}
    }

    public function refreshOnlineCount(): void
    {
        try {
            $onlineDirs = DB::table('director')->whereNull('deleted_at')
                ->where('last_seen_at', '>=', now()->subMinutes(5))->count();

            $onlineCoords = DB::table('organizer')->where('status', 'ACTIVE')->whereNull('deleted_at')
                ->where('last_seen_at', '>=', now()->subMinutes(5))->count();

            $totalDirs = DB::table('director')->whereNull('deleted_at')->count();

            $totalCoords = DB::table('organizer')->where('status', 'ACTIVE')->whereNull('deleted_at')->count();

            $this->onlineCount = $onlineDirs + $onlineCoords;
            $this->totalCount  = $totalDirs  + $totalCoords;
        } catch (\Throwable) {
            $this->onlineCount = 0;
            $this->totalCount  = 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Typing indicator
    // ─────────────────────────────────────────────────────────────────────
    public function pingTyping(): void
    {
        if (trim($this->body) === '') {
            $this->stopTyping();
            return;
        }

        try {
            DB::table('chat_typing')->updateOrInsert(
                [
                    'room_id'     => $this->roomId,
                    'sender_type' => 'director',
                    'sender_id'   => $this->directorId,
                ],
                [
                    'typed_at'   => now(),
                    'updated_at' => now(),
                ]
            );
        } catch (\Throwable) {}
    }

    public function stopTyping(): void
    {
        try {
            DB::table('chat_typing')
                ->where('room_id', $this->roomId)
                ->where('sender_type', 'director')
                ->where('sender_id', $this->directorId)
                ->delete();
        } catch (\Throwable) {}
    }

    public function loadTypingIndicators(): void
    {
        if (! $this->roomId) return;

        try {
            $rows = DB::table('chat_typing')
                ->where('room_id', $this->roomId)
                ->where('typed_at', '>=', now()->subSeconds(6))
                ->where(function ($q) {
                    $q->where('sender_type', '!=', 'director')
                      ->orWhere('sender_id', '!=', $this->directorId);
                })
                ->get(['sender_type', 'sender_id']);

            $names = [];
            foreach ($rows as $row) {
                if (in_array($row->sender_type, ['coordinator', 'organizer'])) {
                    $name = DB::table('organizer')->where('id', $row->sender_id)->value('first_name');
                    if ($name) $names[] = $name . ' (Coordinator)';
                } elseif ($row->sender_type === 'director') {
                    $name = DB::table('director')->where('id', $row->sender_id)->value('first_name');
                    if ($name) $names[] = $name . ' (Director)';
                }
            }

            $this->typingUsers = $names;
        } catch (\Throwable) {
            $this->typingUsers = [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Messages – Load
    // ─────────────────────────────────────────────────────────────────────
    public function loadMessages(): void
    {
        if (! $this->roomId) return;

        $rows = DB::table('chat_messages as m')
            ->where('m.room_id', $this->roomId)
            ->orderBy('m.created_at')
            ->get([
                'm.id', 'm.sender_type', 'm.sender_id', 'm.body',
                'm.reply_to_id', 'm.edited_at', 'm.created_at', 'm.deleted_at',
            ])
            ->toArray();

        $dIds = collect($rows)->where('sender_type', 'director')->pluck('sender_id')->unique();

        $oIds = collect($rows)
            ->filter(fn($r) => in_array($r->sender_type, ['coordinator', 'organizer']))
            ->pluck('sender_id')
            ->unique();

        $dMap = DB::table('director')
            ->whereIn('id', $dIds)
            ->get(['id', 'first_name', 'last_name', 'profile_photo'])
            ->keyBy('id');

        $oMap = DB::table('organizer')
            ->whereIn('id', $oIds)
            ->get(['id', 'first_name', 'last_name', 'profile_photo'])
            ->keyBy('id');

        $msgIds = collect($rows)->pluck('id');

        $rxns = DB::table('chat_reactions')
            ->whereIn('message_id', $msgIds)
            ->get()
            ->groupBy('message_id');

        $pins = DB::table('chat_pins')
            ->whereIn('message_id', $msgIds)
            ->pluck('message_id')
            ->flip();

        $rplyIds = collect($rows)->whereNotNull('reply_to_id')->pluck('reply_to_id')->unique();

        $rplyMap = DB::table('chat_messages')
            ->whereIn('id', $rplyIds)
            ->get(['id', 'sender_type', 'sender_id', 'body', 'deleted_at'])
            ->keyBy('id');

        $self = $this;

        $this->messages = collect($rows)->map(function ($m) use ($dMap, $oMap, $rxns, $pins, $rplyMap, $self) {

            $isDir   = $m->sender_type === 'director';
            $isCoord = in_array($m->sender_type, ['coordinator', 'organizer']);
            $s       = $isDir ? $dMap->get($m->sender_id) : $oMap->get($m->sender_id);
            $sName   = $s ? trim(($s->first_name ?? '') . ' ' . ($s->last_name ?? '')) : 'Unknown';

            $photoUrl = $s ? $self->resolvePhotoUrl($s->profile_photo ?? null) : null;

            $msgRxns = $rxns->get($m->id, collect());
            $rxnGrps = $msgRxns->groupBy('reaction')->map(fn ($g) => $g->count())->toArray();

            $myRxn = $msgRxns->first(
                fn ($r) => $r->reactor_type === 'organizer' && (int) $r->reactor_id === $self->directorId
            );

            $reply = null;
            if ($m->reply_to_id && $rplyMap->has($m->reply_to_id)) {
                $r  = $rplyMap->get($m->reply_to_id);
                $rs = $r->sender_type === 'director'
                    ? $dMap->get($r->sender_id)
                    : $oMap->get($r->sender_id);

                $replyBody = !is_null($r->deleted_at)
                    ? '🚫 This message was unsent.'
                    : $self->resolvePreviewText($r->body);

                $reply = [
                    'id'      => $r->id,
                    'body'    => $replyBody,
                    'name'    => $rs ? trim(($rs->first_name ?? '') . ' ' . ($rs->last_name ?? '')) : 'Unknown',
                    'deleted' => !is_null($r->deleted_at),
                ];
            }

            $isMe      = $m->sender_type === 'director' && $m->sender_id === $self->directorId;
            $isDeleted = !is_null($m->deleted_at);

            return [
                'id'             => $m->id,
                'sender_type'    => $m->sender_type,
                'sender_id'      => $m->sender_id,
                'sender_name'    => $sName,
                'sender_photo'   => $photoUrl,
                'body'           => $m->body,
                'post_preview'   => $isDeleted ? null : $self->resolvePostPreview($m->body),
                'edited'         => ! is_null($m->edited_at),
                'deleted'        => $isDeleted,
                'is_mine'        => $isMe,
                'is_director'    => $isDir,
                'is_coordinator' => $isCoord,
                'is_pinned'      => isset($pins[$m->id]),
                'reactions'      => $isDeleted ? [] : $rxnGrps,
                'my_reaction'    => $isDeleted ? null : ($myRxn ? $myRxn->reaction : null),
                'reply_to'       => $reply,
                'time'           => Carbon::parse($m->created_at)->setTimezone('Asia/Manila')->format('h:i A'),
                'date'           => Carbon::parse($m->created_at)->setTimezone('Asia/Manila')->format('Y-m-d'),
                'date_label'     => Carbon::parse($m->created_at)->setTimezone('Asia/Manila')->format('M d, Y'),
            ];

        })->values()->toArray();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Messages – Send
    //
    // SMOOTHNESS FIX: sendMessage() itself was already lean (no full-room
    // rebuild), but it used to rely on the old 8s refreshAll() poll to
    // pick up typing/notif bookkeeping. Now that unifiedPoll() runs every
    // 2.5s, the sender still sees their own message instantly via
    // loadMessages() below, and everything else reconciles within a
    // couple seconds without a heavy synchronous call blocking send.
    // ─────────────────────────────────────────────────────────────────────
    public function sendMessage(): void
    {
        $body = trim($this->body);
        if ($body === '' || ! $this->roomId) return;

        $msgId = DB::table('chat_messages')->insertGetId([
            'room_id'     => $this->roomId,
            'sender_type' => 'director',
            'sender_id'   => $this->directorId,
            'body'        => $body,
            'reply_to_id' => $this->replyTo['id'] ?? null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        if (preg_match_all('/@(everyone|\w+(?:\s\w+)?)/iu', $body, $matches)) {
            foreach (array_unique($matches[1]) as $mention) {
                if (strtolower($mention) === 'everyone') {
                    DB::table('chat_mentions')->insert([
                        'message_id'   => $msgId,
                        'mention_type' => 'everyone',
                        'mentioned_id' => null,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                    continue;
                }

                $foundCoord = DB::table('organizer')
                    ->where('status', 'ACTIVE')
                    ->whereNull('deleted_at')
                    ->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$mention}%")
                    ->value('id');

                if ($foundCoord) {
                    DB::table('chat_mentions')->insert([
                        'message_id'   => $msgId,
                        'mention_type' => 'coordinator',
                        'mentioned_id' => $foundCoord,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }

                $foundDir = DB::table('director')
                    ->whereNull('deleted_at')
                    ->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$mention}%")
                    ->value('id');

                if ($foundDir) {
                    DB::table('chat_mentions')->insert([
                        'message_id'   => $msgId,
                        'mention_type' => 'director',
                        'mentioned_id' => $foundDir,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }
            }
        }

        // Advance shared watermark so poller skips our own outgoing message
        $this->lastNotifiedMessageId = (int) $msgId;
        Cache::put($this->lastNotifiedCacheKey(), (int) $msgId, now()->addDays(30));

        $this->body         = '';
        $this->replyTo      = null;
        $this->showMentions = false;

        $this->stopTyping();
        $this->loadMessages();
        $this->dispatch('chat-scroll-bottom');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Messages – Edit
    // ─────────────────────────────────────────────────────────────────────
    public function startEdit(int $id): void
    {
        $msg = collect($this->messages)->firstWhere('id', $id);
        if (! $msg || ! $msg['is_mine'] || $msg['deleted']) return;

        $this->editingId = $id;
        $this->editBody  = $msg['body'];
    }

    public function saveEdit(): void
    {
        if (! $this->editingId || trim($this->editBody) === '') return;

        DB::table('chat_messages')
            ->where('id', $this->editingId)
            ->where('sender_type', 'director')
            ->where('sender_id', $this->directorId)
            ->whereNull('deleted_at')
            ->update([
                'body'       => trim($this->editBody),
                'edited_at'  => now(),
                'updated_at' => now(),
            ]);

        $this->editingId = null;
        $this->editBody  = '';
        $this->loadMessages();
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editBody  = '';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Messages – Unsend (soft-delete → shows placeholder)
    // ─────────────────────────────────────────────────────────────────────
    public function unsend(int $id): void
    {
        DB::table('chat_messages')
            ->where('id', $id)
            ->where('sender_type', 'director')
            ->where('sender_id', $this->directorId)
            ->update(['deleted_at' => now()]);

        DB::table('chat_pins')->where('message_id', $id)->delete();
        DB::table('chat_reactions')->where('message_id', $id)->delete();

        $this->loadMessages();
        if ($this->showPins) $this->loadPins();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reactions
    // ─────────────────────────────────────────────────────────────────────
    public function react(int $msgId, string $reaction): void
    {
        if (! in_array($reaction, ['heart', 'purple', 'like', 'dislike'], true)) return;

        $msg = collect($this->messages)->firstWhere('id', $msgId);
        if (! $msg || $msg['deleted']) return;

        $existing = DB::table('chat_reactions')
            ->where('message_id', $msgId)
            ->where('reactor_type', 'organizer')
            ->where('reactor_id', $this->directorId)
            ->first();

        if ($existing) {
            $existing->reaction === $reaction
                ? DB::table('chat_reactions')->where('id', $existing->id)->delete()
                : DB::table('chat_reactions')->where('id', $existing->id)->update([
                    'reaction'   => $reaction,
                    'updated_at' => now(),
                  ]);
        } else {
            DB::table('chat_reactions')->insert([
                'message_id'   => $msgId,
                'reactor_type' => 'organizer',
                'reactor_id'   => $this->directorId,
                'reaction'     => $reaction,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        $this->loadMessages();

        if ($this->reactionsPopupMsgId === $msgId) {
            $this->openReactionsPopup($msgId);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // View Reactions Popup
    // ─────────────────────────────────────────────────────────────────────
    public function openReactionsPopup(int $msgId): void
    {
        if ($this->reactionsPopupMsgId === $msgId) {
            $this->reactionsPopupMsgId = null;
            $this->reactionsPopupData  = [];
            return;
        }

        $this->reactionsPopupMsgId = $msgId;

        $rows = DB::table('chat_reactions')
            ->where('message_id', $msgId)
            ->get(['reactor_type', 'reactor_id', 'reaction']);

        $data = [];
        foreach ($rows as $r) {
            if ($r->reactor_type === 'organizer') {
                $dir = DB::table('director')
                    ->where('id', $r->reactor_id)
                    ->whereNull('deleted_at')
                    ->first(['first_name', 'last_name', 'profile_photo']);

                if ($dir) {
                    $name  = trim(($dir->first_name ?? '') . ' ' . ($dir->last_name ?? ''));
                    $photo = $this->resolvePhotoUrl($dir->profile_photo ?? null);
                    $type  = 'director';
                } else {
                    $coord = DB::table('organizer')
                        ->where('id', $r->reactor_id)
                        ->first(['first_name', 'last_name', 'profile_photo']);
                    $name  = $coord
                        ? trim(($coord->first_name ?? '') . ' ' . ($coord->last_name ?? ''))
                        : 'Unknown';
                    $photo = $coord ? $this->resolvePhotoUrl($coord->profile_photo ?? null) : null;
                    $type  = 'coordinator';
                }
            } else {
                $al    = DB::table('alumni')
                    ->where('id', $r->reactor_id)
                    ->first(['first_name', 'last_name', 'profile_photo']);
                $name  = $al
                    ? trim(($al->first_name ?? '') . ' ' . ($al->last_name ?? ''))
                    : 'Unknown';
                $photo = $al ? $this->resolvePhotoUrl($al->profile_photo ?? null) : null;
                $type  = 'alumni';
            }

            $data[] = [
                'name'     => $name,
                'photo'    => $photo,
                'reaction' => $r->reaction,
                'type'     => $type,
                'is_me'    => $r->reactor_type === 'organizer' && (int) $r->reactor_id === $this->directorId,
            ];
        }

        $this->reactionsPopupData = collect($data)->groupBy('reaction')->toArray();
    }

    public function closeReactionsPopup(): void
    {
        $this->reactionsPopupMsgId = null;
        $this->reactionsPopupData  = [];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pin
    // ─────────────────────────────────────────────────────────────────────
    public function togglePin(int $msgId): void
    {
        $msg = collect($this->messages)->firstWhere('id', $msgId);
        if (! $msg || $msg['deleted']) return;

        if (DB::table('chat_pins')->where('message_id', $msgId)->exists()) {
            DB::table('chat_pins')->where('message_id', $msgId)->delete();
        } else {
            DB::table('chat_pins')->insert([
                'room_id'        => $this->roomId,
                'message_id'     => $msgId,
                'pinned_by_type' => 'organizer',
                'pinned_by_id'   => $this->directorId,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        $this->loadMessages();
        if ($this->showPins) $this->loadPins();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reply
    // ─────────────────────────────────────────────────────────────────────
    public function setReply(int $id): void
    {
        $msg = collect($this->messages)->firstWhere('id', $id);
        if (! $msg || $msg['deleted']) return;

        $this->replyTo = [
            'id'   => $msg['id'],
            'body' => $msg['body'],
            'name' => $msg['sender_name'],
        ];
    }

    public function clearReply(): void
    {
        $this->replyTo = null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Side panels
    // ─────────────────────────────────────────────────────────────────────
    public function toggleMembers(): void
    {
        $this->showMembers  = ! $this->showMembers;
        $this->showPins     = false;
        $this->memberSearch = '';
        if ($this->showMembers) $this->loadMembers();
    }

    public function togglePins(): void
    {
        $this->showPins    = ! $this->showPins;
        $this->showMembers = false;
        if ($this->showPins) $this->loadPins();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Members (Directors + Coordinators)
    // ─────────────────────────────────────────────────────────────────────
    public function loadMembers(): void
    {
        $q = trim($this->memberSearch);

        $dirQuery = DB::table('director')->whereNull('deleted_at');
        if ($q !== '') {
            $dirQuery->where(function ($sub) use ($q) {
                $sub->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhereRaw("CONCAT(first_name,' ',last_name) LIKE ?", ["%{$q}%"]);
            });
        }

        $self = $this;

        $this->directors = $dirQuery
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'profile_photo', 'last_seen_at'])
            ->map(fn ($d) => [
                'id'        => $d->id,
                'name'      => trim($d->first_name . ' ' . $d->last_name),
                'photo'     => $self->resolvePhotoUrl($d->profile_photo ?? null),
                'is_me'     => $d->id === $self->directorId,
                'is_online' => isset($d->last_seen_at)
                                && Carbon::parse($d->last_seen_at)->gte(now()->subMinutes(5)),
            ])->toArray();

        $coordQuery = DB::table('organizer')
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at');
        if ($q !== '') {
            $coordQuery->where(function ($sub) use ($q) {
                $sub->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhereRaw("CONCAT(first_name,' ',last_name) LIKE ?", ["%{$q}%"]);
            });
        }

        $this->coordinators = $coordQuery
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'profile_photo', 'last_seen_at', 'department'])
            ->map(fn ($o) => [
                'id'         => $o->id,
                'name'       => trim($o->first_name . ' ' . $o->last_name),
                'photo'      => $self->resolvePhotoUrl($o->profile_photo ?? null),
                'department' => $o->department ?? '',
                'is_online'  => isset($o->last_seen_at)
                                 && Carbon::parse($o->last_seen_at)->gte(now()->subMinutes(5)),
            ])->toArray();
    }

    public function updatedMemberSearch(): void
    {
        $this->loadMembers();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pinned messages
    // ─────────────────────────────────────────────────────────────────────
    public function loadPins(): void
    {
        $rows = DB::table('chat_pins as p')
            ->join('chat_messages as m', 'm.id', '=', 'p.message_id')
            ->where('p.room_id', $this->roomId)
            ->whereNull('m.deleted_at')
            ->orderByDesc('p.created_at')
            ->get(['m.id', 'm.sender_type', 'm.sender_id', 'm.body', 'p.created_at as pinned_at'])
            ->toArray();

        $dIds = collect($rows)->where('sender_type', 'director')->pluck('sender_id')->unique();
        $oIds = collect($rows)
            ->filter(fn($r) => in_array($r->sender_type, ['coordinator', 'organizer']))
            ->pluck('sender_id')
            ->unique();

        $dMap = DB::table('director')
            ->whereIn('id', $dIds)
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        $oMap = DB::table('organizer')
            ->whereIn('id', $oIds)
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        $self = $this;

        $this->pinnedMessages = collect($rows)->map(function ($p) use ($dMap, $oMap, $self) {
            $s = $p->sender_type === 'director'
                ? $dMap->get($p->sender_id)
                : $oMap->get($p->sender_id);

            return [
                'id'          => $p->id,
                'body'        => $self->resolvePreviewText($p->body),
                'from'        => $s ? trim($s->first_name . ' ' . $s->last_name) : 'Unknown',
                'sender_type' => $p->sender_type,
                'pinned_at'   => Carbon::parse($p->pinned_at)
                                    ->setTimezone('Asia/Manila')
                                    ->format('M d, Y h:i A'),
            ];
        })->toArray();
    }

    // ─────────────────────────────────────────────────────────────────────
    // @mention autocomplete
    // ─────────────────────────────────────────────────────────────────────
    public function updatedBody(string $value): void
    {
        if (preg_match('/@(\w*)$/', $value, $m)) {
            $q = $m[1];

            $dirs = DB::table('director')
                ->whereNull('deleted_at')
                ->where(fn ($sub) => $sub
                    ->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%"))
                ->limit(3)
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn ($d) => [
                    'id'   => $d->id,
                    'name' => trim($d->first_name . ' ' . $d->last_name),
                    'type' => 'director',
                ])->toArray();

            $coords = DB::table('organizer')
                ->where('status', 'ACTIVE')
                ->whereNull('deleted_at')
                ->where(fn ($sub) => $sub
                    ->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%"))
                ->limit(5)
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn ($o) => [
                    'id'   => $o->id,
                    'name' => trim($o->first_name . ' ' . $o->last_name),
                    'type' => 'coordinator',
                ])->toArray();

            $this->mentionSuggestions = array_merge(
                [['id' => 0, 'name' => 'everyone', 'type' => 'everyone']],
                $dirs,
                $coords
            );
            $this->showMentions = true;
        } else {
            $this->showMentions       = false;
            $this->mentionSuggestions = [];
        }
    }

    public function selectMention(string $name): void
    {
        $this->body               = preg_replace('/@\w*$/', '@' . $name . ' ', $this->body);
        $this->showMentions       = false;
        $this->mentionSuggestions = [];
        $this->dispatch('focus-input');
    }
}; ?>

{{-- ════════════════════════════════════════════════════════════════════════
     DIRECTOR MESSENGER UI  —  "Command Circle" (Directors + Coordinators)
     - FIX: header icon was oversized (a big circular bubble icon dominating
       the header). It's now a compact rounded-square badge sized to match
       the organizer chat header (w-10 h-10), so the title/status line reads
       clearly instead of being visually crowded.
     - FIX: room title renamed from generic "Internal Staff Chat" to
       "Command Circle" — the underlying chat_rooms.name DB value and
       course_code='__director__' marker are untouched, this is purely the
       label shown in the header ($roomLabel).
     - SMOOTHNESS FIX: merged the old dual wire:poll (8000ms refreshAll +
       3000ms refreshTyping) into a single wire:poll.2500ms.visible calling
       unifiedPoll(), which staggers presence/typing (every tick), message
       reload + notif watermark (every other tick), and online counts
       (every 4th tick) — same rhythm as organizer/chat-alumni.blade.php.
       This removes the double-polling contention that made sends/clicks
       feel like they paused.
     ════════════════════════════════════════════════════════════════════════ --}}

<style>
    /* ── Shared Job/Event post-preview card — mirrors alumni-side
       messenger.blade.php card design (msgr-post-*) so shared events/jobs
       render as a rich card here instead of raw marker/plain text. ── */
    .msgr-post-card {
        width: 100%;
        max-width: 260px;
        border-radius: 1rem;
        overflow: hidden;
        background: linear-gradient(160deg, #7a3f91 0%, #5c2d7a 100%);
        border: 1px solid rgba(122,63,145,.25);
        box-shadow: 0 4px 14px rgba(122,63,145,.22);
    }
    .msgr-post-card.is-mine { border-color: rgba(255,255,255,.28); }
    .msgr-post-card.is-unavailable { opacity: .82; }

    .msgr-post-thumb {
        position: relative;
        height: 165px;
        width: 100%;
        overflow: hidden;
        background: linear-gradient(135deg,#9b59b6,#5c2d7a);
    }
    .msgr-post-thumb img {
        width: 100%; height: 100%; object-fit: cover; display: block;
        filter: saturate(1.02);
    }
    .msgr-post-card.is-unavailable .msgr-post-thumb img { filter: grayscale(.55) saturate(.7); }

    .msgr-post-thumb-placeholder {
        width: 100%; height: 100%;
        display: flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg, #8a5aa0 0%, #5c2d7a 100%);
    }
    .msgr-post-thumb-placeholder i {
        font-size: 42px;
        color: rgba(255,255,255,.35);
    }

    .msgr-post-thumb-gradient {
        width: 100%; height: 100%;
        display: flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg, #7a3f91 0%, #4a1f6a 100%);
    }
    .msgr-post-thumb-gradient i {
        font-size: 42px;
        color: rgba(255,255,255,.20);
    }

    .msgr-post-tag {
        position: absolute; top: 9px; right: 9px; font-size: 9.5px; font-weight: 600;
        text-transform: uppercase; letter-spacing: .04em; padding: 3px 8px;
        border-radius: 999px; color: #fff; z-index: 2;
    }
    .msgr-post-tag.unavailable-tag { background: rgba(120,53,15,.85); }

    .msgr-post-thumb::after {
        content: '';
        position: absolute; inset: 0;
        background: linear-gradient(to top, rgba(58,27,77,.82) 0%, rgba(58,27,77,0) 55%);
        pointer-events: none;
        z-index: 1;
    }

    .msgr-post-overlay-strip {
        position: absolute; left: 8px; right: 8px; bottom: 8px; z-index: 2;
        background: #ffffff;
        border-radius: 8px;
        padding: 6px 9px;
    }
    .msgr-post-overlay-strip p {
        font-size: 12px; font-weight: 600; line-height: 1.25;
        color: #1a1a1a; text-align: center;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    .msgr-post-overlay-strip p .accent { color: #7a3f91; }

    .msgr-post-thumb-overlay {
        position: absolute; inset: 0; z-index: 3;
        display: flex; align-items: center; justify-content: center;
        background: rgba(58,27,77,0); transition: background .18s ease;
    }
    .msgr-post-card:not(.is-unavailable):hover .msgr-post-thumb-overlay { background: rgba(58,27,77,.32); }
    .msgr-post-view-btn {
        opacity: 0; transform: translateY(4px);
        transition: opacity .18s ease, transform .18s ease;
    }
    .msgr-post-card:not(.is-unavailable):hover .msgr-post-view-btn { opacity: 1; transform: translateY(0); }

    .msgr-post-caption { padding: 10px 12px 11px; background: transparent; }
    .msgr-post-caption .headline {
        font-size: 13px; font-weight: 500; line-height: 1.35; color: #ffffff;
        display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
    }
    .msgr-post-caption .subline {
        font-size: 11px; color: #EDE0F5; margin-top: 3px;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .msgr-post-source-row {
        display: flex; align-items: center; gap: 6px;
        margin-top: 8px; padding-top: 8px;
        border-top: 1px solid rgba(255,255,255,.18);
    }
    .msgr-post-source-row .src-icon {
        width: 16px; height: 16px; border-radius: 4px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0; background: rgba(255,255,255,.20);
    }
    .msgr-post-source-row .src-icon i { font-size: 9px; color: #fff; }
    .msgr-post-source-row span {
        font-size: 11px; font-weight: 500; color: #EDE0F5;
    }
</style>

<div class="flex rounded-2xl border border-[#E8E0F0] bg-white shadow-sm overflow-hidden mx-auto w-full"
     style="height: calc(100vh - 250px); max-width: 1400px;"
     wire:poll.2500ms.visible="unifiedPoll">

    @if($room)
    <div class="flex flex-1 min-w-0 flex-col">

        {{-- ── HEADER ──────────────────────────────────────────────────── --}}
        <div class="flex items-center gap-3 px-5 py-3.5 flex-shrink-0 border-b border-[#E8E0F0]"
             style="background:#7a3f91;">

            {{-- Room icon — FIX: compact rounded-square badge (w-10 h-10),
                 matching the organizer chat header scale instead of the
                 previous oversized circular bubble icon. --}}
            <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
                 style="background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.28);">
                <i class="fa-solid fa-shield-halved text-white text-sm"></i>
            </div>

            <div class="flex-1 min-w-0">
                <p class="text-white font-semibold text-sm leading-tight truncate uppercase tracking-wide">
                    {{ $roomLabel }}
                </p>
                <div class="flex items-center gap-2 flex-wrap mt-0.5">
                    @if($onlineCount > 0)
                    <div class="flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                        <span class="text-white/75 text-xs font-semibold">
                            {{ $onlineCount }}/{{ $totalCount }} online
                        </span>
                    </div>
                    <span class="text-white/30 text-xs hidden sm:inline">·</span>
                    @endif
                    <span class="hidden sm:flex items-center gap-1 text-white/60 text-xs font-semibold">
                        <i class="fa-solid fa-shield-halved text-[10px]"></i>Directors
                        <span class="text-white/30">+</span>
                        <i class="fa-solid fa-users text-[10px]"></i>Coordinators
                    </span>
                    <span class="text-white/30 text-xs hidden sm:inline">·</span>
                    <span class="text-white/60 text-xs font-semibold">
                        <i class="fa-solid fa-lock text-[10px] mr-0.5"></i>Internal Only
                    </span>
                </div>
            </div>

            {{-- My badge (Director) --}}
            <div class="hidden sm:flex items-center gap-2 mr-1">
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-xl"
                     style="background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.22);">
                    <div class="w-7 h-7 rounded-full flex-shrink-0 overflow-hidden"
                         style="background:rgba(255,255,255,.25);">
                        @if($directorPhoto)
                            <img src="{{ $directorPhoto }}"
                                 class="w-full h-full object-cover"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                 alt="{{ $directorFirstName }}">
                            <span style="display:none"
                                  class="w-full h-full flex items-center justify-center text-xs font-semibold text-white">
                                {{ strtoupper(substr($directorFirstName, 0, 1)) ?: 'D' }}
                            </span>
                        @else
                            <span class="w-full h-full flex items-center justify-center text-xs font-semibold text-white">
                                {{ strtoupper(substr($directorFirstName, 0, 1)) ?: 'D' }}
                            </span>
                        @endif
                    </div>
                    <div class="leading-none">
                        <p class="text-white text-xs font-semibold truncate max-w-[100px]">{{ $directorFirstName }}</p>
                        <p class="text-white/50 text-[10px] font-semibold flex items-center gap-1">
                            <i class="fa-solid fa-shield-halved text-[8px]"></i>Director
                        </p>
                    </div>
                    <span class="w-2 h-2 rounded-full bg-emerald-400 flex-shrink-0"></span>
                </div>
            </div>

            {{-- Action buttons --}}
            <div class="flex items-center gap-1.5 flex-shrink-0">
                <button wire:click="togglePins"
                        class="flex items-center gap-1 px-2 py-1.5 rounded-lg text-xs font-semibold border transition"
                        style="{{ $showPins
                            ? 'background:rgba(255,255,255,.25);color:#fff;border-color:rgba(255,255,255,.35);'
                            : 'background:rgba(255,255,255,.12);color:rgba(255,255,255,.75);border-color:rgba(255,255,255,.18);' }}">
                    <i class="fa-solid fa-thumbtack text-xs"></i>
                    <span class="hidden sm:inline ml-1">Pins</span>
                </button>
                <button wire:click="toggleMembers"
                        class="flex items-center gap-1 px-2 py-1.5 rounded-lg text-xs font-semibold border transition"
                        style="{{ $showMembers
                            ? 'background:rgba(255,255,255,.25);color:#fff;border-color:rgba(255,255,255,.35);'
                            : 'background:rgba(255,255,255,.12);color:rgba(255,255,255,.75);border-color:rgba(255,255,255,.18);' }}">
                    <i class="fa-solid fa-user-group text-xs"></i>
                    <span class="hidden sm:inline ml-1">Members</span>
                </button>
            </div>
        </div>

        {{-- ── BODY ─────────────────────────────────────────────────────── --}}
        <div class="flex flex-1 min-h-0">

            {{-- ── MESSAGE COLUMN ──────────────────────────────────────── --}}
            <div class="flex flex-col flex-1 min-w-0">

                {{-- Message list --}}
                <div id="msg-list"
                     class="flex-1 overflow-y-auto px-3 py-3 space-y-0 bg-[#fafafa]"
                     x-data="{ openMessageId: null }"
                     x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight; })"
                     @click.outside="openMessageId = null"
                     @chat-scroll-bottom.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight; })">

                    @php $prevDate = null; $prevSendKey = null; @endphp

                    @forelse($messages as $msg)
                        @php
                            $dateChanged = $msg['date'] !== $prevDate;
                            $senderKey   = $msg['sender_type'] . $msg['sender_id'];
                            $sameGroup   = ! $dateChanged && $senderKey === $prevSendKey;
                            $prevDate    = $msg['date'];
                            $prevSendKey = $senderKey;
                        @endphp

                        {{-- Date separator --}}
                        @if($dateChanged)
                        <div class="flex items-center gap-3 my-2.5">
                            <div class="flex-1 h-px bg-[#E8E0F0]"></div>
                            <span class="text-[10px] font-semibold text-[#999999] tracking-widest uppercase px-2 whitespace-nowrap">
                                {{ $msg['date_label'] }}
                            </span>
                            <div class="flex-1 h-px bg-[#E8E0F0]"></div>
                        </div>
                        @endif

                        {{-- Message row --}}
                        <div class="flex {{ $msg['is_mine'] ? 'justify-end' : 'justify-start' }} items-end gap-2 {{ $sameGroup ? 'mt-0.5' : 'mt-3' }}"
                             x-data="{ confirmUnsend: false }"
                             x-ref="row"
                             @click.outside="confirmUnsend = false">

                            {{-- Avatar – others --}}
                            @if(! $msg['is_mine'])
                            <div class="w-9 h-9 rounded-full flex-shrink-0 overflow-hidden
                                        flex items-center justify-center text-xs font-semibold text-white mb-0.5 self-end"
                                 style="background:#7a3f91;"
                                 title="{{ $msg['sender_name'] }}">
                                @if($msg['sender_photo'])
                                    <img src="{{ $msg['sender_photo'] }}"
                                         class="w-full h-full object-cover"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
                                         alt="{{ $msg['sender_name'] }}">
                                    <span style="display:none">
                                        {{ strtoupper(substr($msg['sender_name'], 0, 1)) }}
                                    </span>
                                @else
                                    {{ strtoupper(substr($msg['sender_name'], 0, 1)) }}
                                @endif
                            </div>
                            @endif

                            {{-- Bubble wrapper --}}
                            <div class="relative flex flex-col {{ $msg['is_mine'] ? 'items-end' : 'items-start' }} max-w-[78%] sm:max-w-[68%]">

                                {{-- Sender name --}}
                                @if(! $msg['is_mine'] && ! $sameGroup)
                                <p class="text-xs font-semibold px-1 mb-0.5 text-[#7a3f91]">
                                    {{ $msg['sender_name'] }}
                                    @if($msg['is_director'])
                                        <span class="ml-1 text-[10px] font-semibold bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded">
                                            <i class="fa-solid fa-shield-halved text-[9px] mr-0.5"></i>Director
                                        </span>
                                    @elseif($msg['is_coordinator'])
                                        <span class="ml-1 text-[10px] font-semibold bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded">
                                            <i class="fa-solid fa-users text-[9px] mr-0.5"></i>Coordinator
                                        </span>
                                    @endif
                                </p>
                                @endif

                                {{-- Pinned indicator --}}
                                @if($msg['is_pinned'] && !$msg['deleted'])
                                <div class="flex items-center gap-1 text-[10px] text-amber-600 font-semibold mb-0.5 px-1">
                                    <i class="fa-solid fa-thumbtack text-[10px]"></i> Pinned
                                </div>
                                @endif

                                {{-- Reply quote --}}
                                @if($msg['reply_to'])
                                <div class="text-[11px] rounded-lg px-2 py-1 mb-0.5 max-w-full border-l-[3px] leading-snug
                                    {{ $msg['is_mine']
                                        ? 'bg-purple-200/60 border-white/70 text-purple-900'
                                        : 'bg-white border-[#E8E0F0] text-[#666666]' }}">
                                    <span class="font-semibold block truncate">{{ $msg['reply_to']['name'] }}</span>
                                    <span class="truncate block {{ ($msg['reply_to']['deleted'] ?? false) ? 'italic opacity-60' : '' }}">
                                        {{ Str::limit($msg['reply_to']['body'], 70) }}
                                    </span>
                                </div>
                                @endif

                                {{-- ── Inline action bar ── --}}
                                @if(!$msg['deleted'])
                                <div class="relative w-full">
                                <div x-show="openMessageId === {{ $msg['id'] }}"
                                     x-transition:enter="transition ease-out duration-150"
                                     x-transition:enter-start="opacity-0 scale-95 translate-y-2"
                                     x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                     x-transition:leave="transition ease-in duration-100"
                                     x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                                     x-transition:leave-end="opacity-0 scale-95 translate-y-2"
                                     x-cloak
                                     @click.stop
                                     class="absolute bottom-full {{ $msg['is_mine'] ? 'right-0' : 'left-0' }} mb-2.5
                                            flex flex-wrap items-center gap-1.5 bg-white border border-[#E8E0F0]
                                            rounded-2xl px-3 py-2 shadow-xl z-30 w-max max-w-[90vw]"
                                     style="box-shadow:0 6px 16px rgba(0,0,0,.12), 0 2px 4px rgba(0,0,0,.06);">

                                    <span class="absolute -bottom-[7px] {{ $msg['is_mine'] ? 'right-5' : 'left-5' }}
                                                 w-3.5 h-3.5 bg-white border-r border-b border-[#E8E0F0] rotate-45"
                                          style="box-shadow:2px 2px 4px rgba(0,0,0,.04);"></span>

                                    @foreach(['heart' => '❤️', 'purple' => '💜', 'like' => '👍', 'dislike' => '👎'] as $rk => $re)
                                    <button wire:click="react({{ $msg['id'] }}, '{{ $rk }}')"
                                            @click.stop
                                            class="text-[1.3rem] leading-none transition-transform hover:scale-125 active:scale-110
                                                   {{ $msg['my_reaction'] === $rk ? 'opacity-100 scale-110' : 'opacity-50 hover:opacity-100' }}"
                                            title="{{ ucfirst($rk) }}">{{ $re }}</button>
                                    @endforeach

                                    <span class="w-px h-5 bg-[#E8E0F0] block"></span>

                                    <button wire:click="setReply({{ $msg['id'] }})"
                                            @click.stop="openMessageId = null"
                                            class="flex items-center gap-1 px-2 py-1 rounded-lg text-[#666666]
                                                   hover:text-[#7a3f91] hover:bg-[#f3eef8] transition text-xs font-semibold">
                                        <i class="fa-solid fa-reply text-xs"></i>
                                        <span class="hidden sm:inline">Reply</span>
                                    </button>

                                    <button wire:click="togglePin({{ $msg['id'] }})"
                                            @click.stop
                                            class="flex items-center gap-1 px-2 py-1 rounded-lg transition text-xs font-semibold
                                                   {{ $msg['is_pinned']
                                                        ? 'text-amber-600 bg-amber-50 hover:bg-amber-100'
                                                        : 'text-[#666666] hover:text-amber-600 hover:bg-amber-50' }}">
                                        <i class="fa-solid fa-thumbtack text-xs"></i>
                                        <span class="hidden sm:inline">{{ $msg['is_pinned'] ? 'Unpin' : 'Pin' }}</span>
                                    </button>

                                    @if(! empty($msg['reactions']))
                                    <button wire:click="openReactionsPopup({{ $msg['id'] }})"
                                            @click.stop
                                            class="flex items-center gap-1 px-2 py-1 rounded-lg transition text-xs font-semibold
                                                   {{ $reactionsPopupMsgId === $msg['id']
                                                        ? 'text-[#7a3f91] bg-[#f3eef8]'
                                                        : 'text-[#666666] hover:text-[#7a3f91] hover:bg-[#f3eef8]' }}">
                                        <i class="fa-solid fa-face-smile text-xs"></i>
                                        <span class="hidden sm:inline">Reactions</span>
                                    </button>
                                    @endif

                                    @if($msg['is_mine'])
                                    <span class="w-px h-5 bg-[#E8E0F0] block"></span>

                                    <button wire:click="startEdit({{ $msg['id'] }})"
                                            @click.stop="openMessageId = null"
                                            class="flex items-center gap-1 px-2 py-1 rounded-lg text-[#666666]
                                                   hover:text-blue-600 hover:bg-blue-50 transition text-xs font-semibold">
                                        <i class="fa-solid fa-pen text-xs"></i>
                                        <span class="hidden sm:inline">Edit</span>
                                    </button>

                                    <div x-show="!confirmUnsend">
                                        <button @click.stop="confirmUnsend = true"
                                                class="flex items-center gap-1 px-2 py-1 rounded-lg text-[#666666]
                                                       hover:text-red-600 hover:bg-red-50 transition text-xs font-semibold">
                                            <i class="fa-solid fa-trash-can text-xs"></i>
                                            <span class="hidden sm:inline">Unsend</span>
                                        </button>
                                    </div>
                                    <div x-show="confirmUnsend" class="flex items-center gap-1">
                                        <span class="text-xs text-red-600 font-semibold">Unsend?</span>
                                        <button wire:click="unsend({{ $msg['id'] }})"
                                                @click.stop
                                                class="text-xs px-2 py-1 rounded-lg bg-red-500 text-white font-semibold hover:bg-red-600 transition">
                                            Yes
                                        </button>
                                        <button @click.stop="confirmUnsend = false"
                                                class="text-xs px-2 py-1 rounded-lg bg-[#f5f5f5] text-[#666666] font-semibold hover:bg-[#E8E0F0] transition">
                                            No
                                        </button>
                                    </div>
                                    @endif
                                </div>
                                </div>
                                @endif

                                {{-- ══ DELETED / UNSENT PLACEHOLDER ══ --}}
                                @if($msg['deleted'])
                                <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[11px] italic
                                            {{ $msg['is_mine'] ? 'rounded-br-none' : 'rounded-bl-none' }}"
                                     style="background:rgba(0,0,0,0.05); border:1px dashed #d1d5db; color:#9ca3af;">
                                    <i class="fa-solid fa-ban text-[10px] opacity-60"></i>
                                    <span>
                                        @if($msg['is_mine'])
                                            You unsent a message.
                                        @else
                                            {{ $msg['sender_name'] }} unsent a message.
                                        @endif
                                    </span>
                                </div>

                                {{-- ══ EDIT MODE ══ --}}
                                @elseif($editingId === $msg['id'])
                                <div class="flex flex-col gap-1.5 min-w-[220px]">
                                    <textarea wire:model="editBody"
                                              rows="2"
                                              class="text-sm rounded-lg border border-[#7a3f91] px-3 py-2 resize-none
                                                     focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/30 w-full bg-white shadow-sm"
                                              wire:keydown.escape="cancelEdit"></textarea>
                                    <div class="flex gap-1.5 justify-end">
                                        <button wire:click="cancelEdit"
                                                class="text-xs px-3 py-1.5 rounded-lg border border-[#E8E0F0]
                                                       text-[#666666] hover:bg-[#f5f5f5] transition font-semibold">
                                            Cancel
                                        </button>
                                        <button wire:click="saveEdit"
                                                class="text-xs px-3 py-1.5 rounded-lg text-white font-semibold
                                                       hover:opacity-90 transition"
                                                style="background:#7a3f91;">
                                            Save
                                        </button>
                                    </div>
                                </div>

                                {{-- ══ SHARED JOB / EVENT PREVIEW CARD ══
                                     Rendered whenever body carries a
                                     [[JOB:id]] or [[EVENT:TYPE:id]] marker
                                     — mirrors alumni-side messenger.blade.php
                                     card design. Falls back to a dimmed
                                     "unavailable" state if the source
                                     job/event was removed. ── --}}
                                @elseif($msg['post_preview'])
                                @php
                                    $pp          = $msg['post_preview'];
                                    $ppAvailable = $pp['available'] ?? true;
                                    $ppIsEvent   = ($pp['type'] ?? 'job') === 'event';
                                    $ppCompleted = $ppIsEvent && ($pp['is_completed'] ?? false);
                                @endphp
                                <div @click.stop="openMessageId = (openMessageId === {{ $msg['id'] }} ? null : {{ $msg['id'] }}); confirmUnsend = false; $nextTick(() => { if (openMessageId === {{ $msg['id'] }}) $refs.row.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); })"
                                     class="msgr-post-card cursor-pointer {{ $msg['is_mine'] ? 'is-mine' : '' }} {{ ! $ppAvailable ? 'is-unavailable' : '' }}">
                                    <div class="msgr-post-thumb">
                                        @if($ppAvailable)
                                            @if(! empty($pp['image']))
                                            <img src="{{ $pp['image'] }}" alt="{{ $pp['title'] }}"
                                                 onerror="this.onerror=null;this.parentElement.querySelector('img').remove();">
                                            @elseif($ppIsEvent)
                                            <div class="msgr-post-thumb-gradient">
                                                <i class="fa-solid fa-calendar-days"></i>
                                            </div>
                                            @else
                                            <img src="{{ asset('storage/job/default-photo-job.jpg') }}" alt="{{ $pp['title'] }}">
                                            @endif
                                        @else
                                        <div class="msgr-post-thumb-placeholder">
                                            <i class="fa-solid {{ $pp['type'] === 'job' ? 'fa-briefcase' : 'fa-calendar-xmark' }}"></i>
                                        </div>
                                        @endif

                                        @if(! $ppAvailable)
                                        <span class="msgr-post-tag unavailable-tag">Unavailable</span>
                                        @elseif($ppCompleted)
                                        <span class="msgr-post-tag" style="background:rgba(5,150,105,.92);">Completed</span>
                                        @endif

                                        <div class="msgr-post-overlay-strip">
                                            <p>
                                                @if($ppAvailable)
                                                    @if($ppIsEvent)
                                                        <span class="accent">{{ $ppCompleted ? 'Completed' : 'Save the Date' }}:</span> {{ $pp['title'] }}
                                                    @else
                                                        <span class="accent">Now Hiring:</span> {{ $pp['title'] }}
                                                    @endif
                                                @else
                                                    <span class="accent">{{ $pp['type'] === 'job' ? 'Job Posting' : 'Event' }}:</span> No longer available
                                                @endif
                                            </p>
                                        </div>

                                        @if($ppAvailable)
                                        <div class="msgr-post-thumb-overlay">
                                            <a href="{{ $pp['url'] }}" wire:navigate @click.stop
                                               class="msgr-post-view-btn px-3 py-1.5 rounded-full bg-white text-[#5c2d7a] text-xs font-bold shadow-md inline-flex items-center gap-1.5">
                                                <i class="fa-solid fa-eye"></i>View {{ $pp['type'] === 'job' ? 'Job' : 'Event' }}
                                            </a>
                                        </div>
                                        @endif
                                    </div>

                                    <div class="msgr-post-caption">
                                        <p class="headline">{{ $pp['title'] }}</p>
                                        @if($pp['subtitle'])
                                        <p class="subline">{{ $pp['subtitle'] }}</p>
                                        @endif
                                        <div class="msgr-post-source-row">
                                            <span class="src-icon">
                                                <i class="fa-solid fa-graduation-cap"></i>
                                            </span>
                                            <span>PHILCST</span>
                                        </div>
                                    </div>
                                </div>

                                {{-- ══ NORMAL BUBBLE ══ --}}
                                @else
                                @php
                                    $safe         = htmlspecialchars($msg['body'], ENT_QUOTES, 'UTF-8');
                                    $mentionClass = $msg['is_mine']
                                        ? 'font-semibold text-yellow-200 bg-yellow-400/20 px-0.5 rounded'
                                        : 'font-semibold text-[#7a3f91] bg-[#f3eef8] px-0.5 rounded';
                                    $formatted    = preg_replace(
                                        '/@(everyone|\w+(?:\s\w+)?)/u',
                                        '<span class="' . $mentionClass . '">@$1</span>',
                                        $safe
                                    );
                                @endphp
                                <div @click.stop="openMessageId = (openMessageId === {{ $msg['id'] }} ? null : {{ $msg['id'] }}); confirmUnsend = false; $nextTick(() => { if (openMessageId === {{ $msg['id'] }}) $refs.row.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); })"
                                     class="px-3.5 py-2.5 rounded-2xl text-sm leading-relaxed break-words
                                            cursor-pointer select-none transition-opacity active:opacity-80
                                            {{ $msg['is_mine']
                                                ? 'text-white rounded-br-none shadow-sm'
                                                : 'text-[#1a1a1a] rounded-bl-none border border-[#ECECEC]' }}"
                                     style="{{ $msg['is_mine']
                                            ? 'background:#7a3f91;'
                                            : 'background:#ffffff; box-shadow:0 1px 2px rgba(0,0,0,.06), 0 2px 8px rgba(0,0,0,.05), inset 0 1px 0 rgba(255,255,255,.9);' }}">
                                    {!! $formatted !!}
                                    @if($msg['edited'])
                                        <span class="text-xs ml-1 italic {{ $msg['is_mine'] ? 'opacity-50' : 'text-[#999999]' }}">(edited)</span>
                                    @endif
                                </div>
                                @endif

                                {{-- ── View Reactions Popup ── --}}
                                @if($reactionsPopupMsgId === $msg['id'] && ! empty($reactionsPopupData))
                                <div class="mt-2 bg-white border border-[#E8E0F0] rounded-2xl shadow-xl z-20 w-64 overflow-hidden"
                                     @click.stop>
                                    <div class="flex items-center justify-between px-3.5 py-2.5 border-b border-[#E8E0F0] bg-[#fafafa]">
                                        <p class="text-xs font-semibold text-[#333333] uppercase tracking-widest">
                                            <i class="fa-solid fa-face-smile text-[#7a3f91] mr-1.5"></i>Reactions
                                        </p>
                                        <button wire:click="closeReactionsPopup"
                                                class="w-6 h-6 flex items-center justify-center rounded-full text-[#999999]
                                                       hover:text-[#333333] hover:bg-[#f5f5f5] transition">
                                            <i class="fa-solid fa-xmark text-xs"></i>
                                        </button>
                                    </div>
                                    <div class="max-h-52 overflow-y-auto">
                                        @php $emojiMap = ['heart'=>'❤️','purple'=>'💜','like'=>'👍','dislike'=>'👎']; @endphp
                                        @foreach($reactionsPopupData as $rKey => $rGroup)
                                        <div class="px-3.5 py-2 border-b border-[#E8E0F0] last:border-0">
                                            <div class="flex items-center gap-1.5 mb-1.5">
                                                <span class="text-base">{{ $emojiMap[$rKey] ?? '👍' }}</span>
                                                <span class="text-xs font-semibold text-[#666666]">
                                                    {{ count($rGroup) }} {{ count($rGroup) === 1 ? 'person' : 'people' }}
                                                </span>
                                            </div>
                                            @foreach($rGroup as $reactor)
                                            <div class="flex items-center gap-2 py-1">
                                                <div class="w-6 h-6 rounded-full flex-shrink-0 overflow-hidden
                                                            flex items-center justify-center text-xs font-semibold text-white"
                                                     style="background:#7a3f91;">
                                                    @if($reactor['photo'] ?? null)
                                                        <img src="{{ $reactor['photo'] }}"
                                                             class="w-full h-full object-cover"
                                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
                                                             alt="">
                                                        <span style="display:none">{{ strtoupper(substr($reactor['name'], 0, 1)) }}</span>
                                                    @else
                                                        {{ strtoupper(substr($reactor['name'], 0, 1)) }}
                                                    @endif
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <p class="text-xs font-semibold text-[#333333] truncate">
                                                        {{ $reactor['name'] }}
                                                        @if($reactor['is_me'])
                                                            <span class="text-[#7a3f91] font-semibold">(You)</span>
                                                        @endif
                                                    </p>
                                                    <p class="text-[10px] font-medium text-purple-600">
                                                        {{ ucfirst($reactor['type']) }}
                                                    </p>
                                                </div>
                                            </div>
                                            @endforeach
                                        </div>
                                        @endforeach
                                    </div>
                                </div>
                                @endif

                                {{-- Reaction pills --}}
                                @if(! empty($msg['reactions']) && !$msg['deleted'])
                                <div class="flex gap-1 mt-0.5 flex-wrap {{ $msg['is_mine'] ? 'justify-end' : 'justify-start' }}">
                                    @foreach($msg['reactions'] as $rk => $cnt)
                                    @php $emoji = match($rk) { 'heart'=>'❤️','purple'=>'💜','like'=>'👍','dislike'=>'👎',default=>'👍' }; @endphp
                                    <button wire:click="react({{ $msg['id'] }}, '{{ $rk }}')"
                                            class="inline-flex items-center gap-0.5 text-[10px] px-1.5 py-0.5 rounded-full border transition-all
                                                   {{ $msg['my_reaction'] === $rk
                                                        ? 'bg-[#f3eef8] border-[#d9c9e8] text-[#7a3f91] font-semibold'
                                                        : 'bg-white border-[#E8E0F0] text-[#666666] hover:border-[#d9c9e8]' }}">
                                        {{ $emoji }}<span class="font-semibold ml-0.5">{{ $cnt }}</span>
                                    </button>
                                    @endforeach
                                </div>
                                @endif

                                {{-- Timestamp --}}
                                <p class="text-xs text-[#999999] mt-0.5 px-1">{{ $msg['time'] }}</p>
                            </div>

                            {{-- Avatar – mine (director) --}}
                            @if($msg['is_mine'])
                            <div class="w-9 h-9 rounded-full flex-shrink-0 overflow-hidden
                                        flex items-center justify-center text-xs font-semibold text-white mb-0.5 self-end"
                                 style="background:#7a3f91;">
                                @if($directorPhoto)
                                    <img src="{{ $directorPhoto }}"
                                         class="w-full h-full object-cover"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
                                         alt="">
                                    <span style="display:none">
                                        {{ strtoupper(substr($directorFirstName, 0, 1)) ?: '?' }}
                                    </span>
                                @else
                                    {{ strtoupper(substr($directorFirstName, 0, 1)) ?: '?' }}
                                @endif
                            </div>
                            @endif
                        </div>

                    @empty
                        <div class="flex flex-col items-center justify-center h-full py-16 text-[#999999] select-none">
                            <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-3"
                                 style="background:#f3eef8;">
                                <i class="fa-solid fa-comments text-xl" style="color:#7a3f91;"></i>
                            </div>
                            <p class="text-sm font-semibold text-[#666666]">No messages yet</p>
                            <p class="text-xs text-[#999999] mt-1">Start the {{ $roomLabel }} conversation! 👋</p>
                        </div>
                    @endforelse
                </div>

                {{-- Typing indicator --}}
                <div class="flex-shrink-0">
                    @if(! empty($typingUsers))
                    <div class="flex items-center gap-2.5 px-4 py-2 bg-[#fafafa] border-t border-[#E8E0F0]">
                        <div class="flex items-end gap-0.5 h-4">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#7a3f91] animate-bounce"
                                  style="animation-delay:0ms; animation-duration:900ms;"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-[#7a3f91] animate-bounce"
                                  style="animation-delay:180ms; animation-duration:900ms;"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-[#7a3f91] animate-bounce"
                                  style="animation-delay:360ms; animation-duration:900ms;"></span>
                        </div>
                        <p class="text-xs text-[#666666] font-medium">
                            @php
                                $visible = array_slice($typingUsers, 0, 3);
                                $extra   = count($typingUsers) - count($visible);
                            @endphp
                            <span class="font-semibold text-[#7a3f91]">
                                {{ implode(', ', $visible) }}{{ $extra > 0 ? " +{$extra}" : '' }}
                            </span>
                            {{ count($typingUsers) === 1 ? 'is' : 'are' }} typing…
                        </p>
                    </div>
                    @endif
                </div>

                {{-- Reply preview bar --}}
                @if($replyTo)
                <div class="flex items-center gap-3 px-4 py-2.5 border-t border-[#E8E0F0] bg-[#f3eef8] flex-shrink-0">
                    <div class="w-1 h-10 rounded-full flex-shrink-0" style="background:#7a3f91;"></div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-[#7a3f91] truncate uppercase tracking-widest">
                            Replying to {{ $replyTo['name'] }}
                        </p>
                        <p class="text-xs text-[#666666] truncate">{{ Str::limit($replyTo['body'], 90) }}</p>
                    </div>
                    <button wire:click="clearReply"
                            class="w-7 h-7 flex items-center justify-center rounded-full text-[#999999]
                                   hover:text-red-600 hover:bg-red-50 transition flex-shrink-0">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>
                @endif

                {{-- Input area --}}
                <div class="px-4 py-3 border-t border-[#E8E0F0] bg-white flex-shrink-0" x-data>

                    @if($showMentions && ! empty($mentionSuggestions))
                    <div class="mb-2 bg-white border border-[#E8E0F0] rounded-2xl shadow-md overflow-hidden">
                        @foreach($mentionSuggestions as $sug)
                        <button wire:click="selectMention('{{ addslashes($sug['name']) }}')"
                                class="flex items-center gap-2.5 w-full px-3 py-2.5 hover:bg-[#f3eef8] transition-colors text-left">
                            <div class="w-9 h-9 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-semibold text-white"
                                 style="background:#7a3f91;">
                                @if($sug['name'] === 'everyone')
                                    <i class="fa-solid fa-users text-xs"></i>
                                @else
                                    {{ strtoupper(substr($sug['name'], 0, 1)) }}
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-[#333333] truncate">&#64;{{ $sug['name'] }}</p>
                                @if($sug['name'] === 'everyone')
                                    <p class="text-xs text-[#7a3f91] font-medium">Notify all staff</p>
                                @elseif($sug['type'] === 'director')
                                    <p class="text-xs text-purple-600 font-medium">
                                        <i class="fa-solid fa-shield-halved text-[10px] mr-0.5"></i>Director
                                    </p>
                                @elseif($sug['type'] === 'coordinator')
                                    <p class="text-xs text-purple-600 font-medium">
                                        <i class="fa-solid fa-users text-[10px] mr-0.5"></i>Alumni Coordinator
                                    </p>
                                @endif
                            </div>
                        </button>
                        @endforeach
                    </div>
                    @endif

                    <div class="flex items-end gap-2">
                        <div class="flex-1 relative">
                            <textarea
                                id="chat-input"
                                wire:model.live.debounce.200ms="body"
                                wire:keyup.debounce.800ms="pingTyping"
                                placeholder="Message {{ $roomLabel }}… (@ to mention)"
                                rows="1"
                                @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.sendMessage(); }"
                                @focus-input.window="$el.focus()"
                                x-init="
                                    $el.addEventListener('input', function () {
                                        this.style.height = 'auto';
                                        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
                                    });
                                "
                                class="w-full resize-none rounded-lg border-2 border-[#7a3f91] bg-white
                                       px-4 py-2.5 text-sm leading-relaxed text-[#333333]
                                       focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/30
                                       transition placeholder-[#999999]"
                                style="max-height:120px; overflow-y:auto;"></textarea>
                        </div>
                        <button wire:click="sendMessage" wire:loading.attr="disabled" wire:target="sendMessage"
                                class="w-10 h-10 rounded-full flex items-center justify-center text-white flex-shrink-0
                                       transition hover:opacity-90 active:scale-95 shadow-sm disabled:opacity-60"
                                style="background:#7a3f91;">
                            <i class="fa-solid fa-paper-plane text-base" wire:loading.remove wire:target="sendMessage"></i>
                            <span class="hidden items-center gap-1" wire:loading.flex wire:target="sendMessage">
                                <span class="w-1.5 h-1.5 rounded-full bg-white animate-bounce" style="animation-delay:0ms;animation-duration:800ms;"></span>
                                <span class="w-1.5 h-1.5 rounded-full bg-white animate-bounce" style="animation-delay:150ms;animation-duration:800ms;"></span>
                                <span class="w-1.5 h-1.5 rounded-full bg-white animate-bounce" style="animation-delay:300ms;animation-duration:800ms;"></span>
                            </span>
                        </button>
                    </div>

                    <p class="text-xs text-[#999999] text-center mt-1.5">
                        <kbd class="bg-[#f5f5f5] border border-[#E8E0F0] rounded px-1 py-0.5 text-xs">Enter</kbd> send &nbsp;·&nbsp;
                        <kbd class="bg-[#f5f5f5] border border-[#E8E0F0] rounded px-1 py-0.5 text-xs">Shift+Enter</kbd> new line &nbsp;·&nbsp;
                        <kbd class="bg-[#f5f5f5] border border-[#E8E0F0] rounded px-1 py-0.5 text-xs">@</kbd> mention &nbsp;·&nbsp;
                        <span class="text-[#E8E0F0]">tap message for actions</span>
                    </p>
                </div>
            </div>

            {{-- ── SIDE PANEL ── --}}
            @if($showMembers || $showPins)
            <div class="w-72 border-l border-[#E8E0F0] flex flex-col flex-shrink-0 bg-white">

                <div class="flex items-center gap-2.5 px-4 py-3 border-b border-[#E8E0F0] flex-shrink-0"
                     style="background:#7a3f91;">
                    @if($showPins)
                        <i class="fa-solid fa-thumbtack text-white"></i>
                        <p class="text-sm font-semibold text-white flex-1 uppercase tracking-wide">Pinned Messages</p>
                    @else
                        <i class="fa-solid fa-user-group text-white"></i>
                        <p class="text-sm font-semibold text-white flex-1 uppercase tracking-wide">
                            Staff Members
                            <span class="text-xs font-semibold text-white/70 ml-1">
                                ({{ count($directors) + count($coordinators) }})
                            </span>
                            @if($onlineCount > 0)
                            <span class="ml-1 text-xs font-semibold text-emerald-300">· {{ $onlineCount }} online</span>
                            @endif
                        </p>
                    @endif
                    <button wire:click="{{ $showPins ? 'togglePins' : 'toggleMembers' }}"
                            class="w-7 h-7 flex items-center justify-center rounded-lg text-white/70
                                   hover:text-white hover:bg-white/15 transition">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto flex flex-col">

                    @if($showMembers)

                        @if(! empty($directors))
                        <div class="px-3 pt-3 pb-1 flex-shrink-0">
                            <p class="text-xs font-semibold text-[#7a3f91] uppercase tracking-widest mb-2 px-1">
                                <i class="fa-solid fa-shield-halved text-xs mr-1"></i>Director{{ count($directors) > 1 ? 's' : '' }} — {{ count($directors) }}
                            </p>
                            @foreach($directors as $dir)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2 mb-1
                                        {{ $dir['is_me'] ? 'bg-[#f3eef8] border border-[#d9c9e8]' : 'border border-transparent hover:border-[#E8E0F0] hover:bg-[#fafafa]' }}
                                        transition-all">
                                <div class="relative flex-shrink-0">
                                    <div class="w-9 h-9 rounded-full overflow-hidden flex items-center justify-center
                                                text-xs font-semibold text-white"
                                         style="background:#7a3f91;">
                                        @if($dir['photo'])
                                            <img src="{{ $dir['photo'] }}"
                                                 class="w-full h-full object-cover"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
                                                 alt="{{ $dir['name'] }}">
                                            <span style="display:none">{{ strtoupper(substr($dir['name'], 0, 1)) }}</span>
                                        @else
                                            {{ strtoupper(substr($dir['name'], 0, 1)) }}
                                        @endif
                                    </div>
                                    @if($dir['is_online'] || $dir['is_me'])
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-400 border-2 border-white"></span>
                                    @else
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-gray-400 border-2 border-white"></span>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#333333] truncate">
                                        {{ $dir['name'] }}
                                        @if($dir['is_me'])
                                            <span class="text-xs text-[#7a3f91] font-semibold">(You)</span>
                                        @endif
                                    </p>
                                    <p class="text-xs font-medium flex items-center gap-1
                                              {{ ($dir['is_online'] || $dir['is_me']) ? 'text-emerald-600' : 'text-[#999999]' }}">
                                        <span class="w-1.5 h-1.5 rounded-full flex-shrink-0
                                                     {{ ($dir['is_online'] || $dir['is_me']) ? 'bg-emerald-400' : 'bg-gray-400' }}"></span>
                                        {{ ($dir['is_online'] || $dir['is_me']) ? 'Online' : 'Offline' }}
                                    </p>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        @endif

                        <div class="px-3 pt-2 pb-1 flex-shrink-0 border-t border-[#E8E0F0] mt-1">
                            <div class="flex items-center justify-between mb-2 px-1">
                                <p class="text-xs font-semibold text-[#7a3f91] uppercase tracking-widest">
                                    <i class="fa-solid fa-users text-xs mr-1"></i>Coordinators — {{ count($coordinators) }}
                                </p>
                                @php $onlineCoordCount = collect($coordinators)->where('is_online', true)->count(); @endphp
                                @if($onlineCoordCount > 0)
                                <span class="text-xs font-semibold text-emerald-600 flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
                                    {{ $onlineCoordCount }} online
                                </span>
                                @endif
                            </div>
                        </div>

                        <div class="px-3 pb-2.5 flex-shrink-0">
                            <div class="relative">
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2
                                          text-[#999999] text-xs pointer-events-none"></i>
                                <input wire:model.live.debounce.300ms="memberSearch"
                                       type="text"
                                       placeholder="Search staff…"
                                       class="w-full pl-8 pr-3 py-2 text-sm rounded-lg border border-[#E8E0F0]
                                              bg-[#fafafa] focus:outline-none focus:border-[#7a3f91]
                                              focus:ring-1 focus:ring-[#7a3f91]/20 transition placeholder-[#999999]"/>
                            </div>
                        </div>

                        <div class="flex-1 overflow-y-auto px-3 pb-3 space-y-1">
                            @php
                                $onlineCoords  = collect($coordinators)->where('is_online', true)->values();
                                $offlineCoords = collect($coordinators)->where('is_online', false)->values();
                            @endphp

                            @if(count($onlineCoords) > 0)
                            <p class="text-xs font-semibold text-emerald-600 uppercase tracking-widest px-1 pb-1 pt-0.5">
                                <span class="inline-block w-1.5 h-1.5 rounded-full bg-emerald-400 mr-1 align-middle"></span>Online — {{ count($onlineCoords) }}
                            </p>
                            @foreach($onlineCoords as $coord)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 border border-[#E8E0F0]
                                        hover:border-[#d9c9e8] hover:bg-[#f3eef8] transition-all">
                                <div class="relative flex-shrink-0">
                                    <div class="w-9 h-9 rounded-full overflow-hidden flex items-center justify-center
                                                text-xs font-semibold text-white"
                                         style="background:#7a3f91;">
                                        @if($coord['photo'])
                                            <img src="{{ $coord['photo'] }}"
                                                 class="w-full h-full object-cover"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
                                                 alt="{{ $coord['name'] }}">
                                            <span style="display:none">{{ strtoupper(substr($coord['name'], 0, 1)) }}</span>
                                        @else
                                            {{ strtoupper(substr($coord['name'], 0, 1)) }}
                                        @endif
                                    </div>
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-400 border-2 border-white"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#333333] truncate">{{ $coord['name'] }}</p>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <span class="text-xs text-emerald-600 font-medium flex items-center gap-1">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block flex-shrink-0"></span>
                                            Online
                                        </span>
                                        @if($coord['department'])
                                        <span class="text-[#999999] text-xs">·</span>
                                        <span class="text-xs text-[#7a3f91] font-medium truncate">{{ $coord['department'] }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @endforeach
                            @endif

                            @if(count($offlineCoords) > 0)
                            <div class="pt-{{ count($onlineCoords) > 0 ? '2' : '0.5' }} pb-1 px-1">
                                <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest">
                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-gray-400 mr-1 align-middle opacity-70"></span>
                                    Offline — {{ count($offlineCoords) }}
                                </p>
                            </div>
                            @foreach($offlineCoords as $coord)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 border border-[#E8E0F0]
                                        hover:bg-[#fafafa] transition-all opacity-70">
                                <div class="relative flex-shrink-0">
                                    <div class="w-9 h-9 rounded-full overflow-hidden flex items-center justify-center
                                                text-xs font-semibold text-white"
                                         style="background:#c4a8d4;">
                                        @if($coord['photo'])
                                            <img src="{{ $coord['photo'] }}"
                                                 class="w-full h-full object-cover"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
                                                 alt="{{ $coord['name'] }}">
                                            <span style="display:none">{{ strtoupper(substr($coord['name'], 0, 1)) }}</span>
                                        @else
                                            {{ strtoupper(substr($coord['name'], 0, 1)) }}
                                        @endif
                                    </div>
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-gray-400 border-2 border-white"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#666666] truncate">{{ $coord['name'] }}</p>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <span class="text-xs text-[#999999] font-medium flex items-center gap-1">
                                            <span class="w-1.5 h-1.5 rounded-full bg-gray-400 inline-block flex-shrink-0"></span>
                                            Offline
                                        </span>
                                        @if($coord['department'])
                                        <span class="text-[#E8E0F0] text-xs">·</span>
                                        <span class="text-xs text-[#999999] font-medium truncate">{{ $coord['department'] }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            @endforeach
                            @endif

                            @if(empty($directors) && empty($coordinators))
                            <div class="flex flex-col items-center justify-center py-10 text-[#999999]">
                                <i class="fa-solid fa-user-slash text-3xl text-[#E8E0F0] mb-3"></i>
                                <p class="text-sm font-semibold">No results</p>
                                <p class="text-xs mt-1">Try a different name</p>
                            </div>
                            @endif
                        </div>

                    @elseif($showPins)
                    <div class="flex-1 overflow-y-auto p-3 space-y-2">
                        @forelse($pinnedMessages as $pin)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                            <div class="flex items-start justify-between gap-2 mb-1.5">
                                <div class="flex items-center gap-1.5 min-w-0">
                                    <i class="fa-solid fa-thumbtack text-amber-600 text-xs flex-shrink-0"></i>
                                    <p class="text-xs font-semibold text-amber-800 truncate">{{ $pin['from'] }}</p>
                                    @if(isset($pin['sender_type']))
                                    <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded flex-shrink-0 bg-purple-100 text-purple-700">
                                        {{ $pin['sender_type'] === 'director' ? 'Director' : 'Coordinator' }}
                                    </span>
                                    @endif
                                </div>
                                <button wire:click="togglePin({{ $pin['id'] }})"
                                        class="w-5 h-5 flex items-center justify-center rounded-full text-[#999999]
                                               hover:text-red-600 hover:bg-red-50 transition flex-shrink-0">
                                    <i class="fa-solid fa-xmark text-xs"></i>
                                </button>
                            </div>
                            <p class="text-sm text-[#333333] leading-snug break-words">
                                {{ Str::limit($pin['body'], 140) }}
                            </p>
                            <p class="text-xs text-[#999999] mt-1.5">{{ $pin['pinned_at'] }}</p>
                        </div>
                        @empty
                        <div class="flex flex-col items-center justify-center py-14 text-[#999999]">
                            <i class="fa-solid fa-thumbtack text-4xl text-[#E8E0F0] mb-3"></i>
                            <p class="text-sm font-semibold">No pinned messages</p>
                            <p class="text-xs mt-1 text-center">Tap a message then 📌 to pin it.</p>
                        </div>
                        @endforelse
                    </div>
                    @endif

                </div>
            </div>
            @endif

        </div>
    </div>

    @else
    <div class="flex flex-1 items-center justify-center bg-[#fafafa]">
        <div class="flex flex-col items-center text-center px-8">
            <div class="w-14 h-14 rounded-xl flex items-center justify-center mb-4"
                 style="background:#f3eef8;">
                <i class="fa-solid fa-comments text-2xl" style="color:#7a3f91;"></i>
            </div>
            <p class="text-base font-semibold text-[#333333]">Setting up the channel…</p>
            <p class="text-xs text-[#999999] mt-2 max-w-xs leading-relaxed">
                The staff channel is being initialized. Please refresh the page.
            </p>
        </div>
    </div>
    @endif

</div>