{{-- resources/views/livewire/organizer/chat-alumni.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

new class extends Component {

    // ── Rooms list ────────────────────────────────────────────────────────
    public array  $rooms  = [];
    public ?array $room   = null;
    public int    $roomId = 0;

    // ── Room search ───────────────────────────────────────────────────────
    public string $roomSearch = '';

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
    public array $alumni         = [];
    public array $coordinators   = [];
    public array $pinnedMessages = [];

    // ── Staff room members ────────────────────────────────────────────────
    public bool  $isStaffRoom    = false;
    public array $staffDirectors = [];
    public array $staffCoords    = [];

    // ── Course GC flag ────────────────────────────────────────────────────
    public bool $isCourseRoom = false;

    // ── College-wide room flag ────────────────────────────────────────────
    public bool $isCollegeRoom = false;

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

    // ── Current coordinator ───────────────────────────────────────────────
    public int    $coordinatorId        = 0;
    public string $coordinatorName      = '';
    public string $coordinatorFirstName = '';
    public string $coordinatorPhoto     = '';
    public string $department           = '';

    // ── Course codes belonging to this department ─────────────────────────
    public array $deptCourseCodes = [];

    // ── View Reactions popup ──────────────────────────────────────────────
    public ?int  $reactionsPopupMsgId = null;
    public array $reactionsPopupData  = [];

    // ── Unread / watermark tracking ───────────────────────────────────────
    public array $lastNotifiedMessageIds = [];

    // ── Pinned rooms ──────────────────────────────────────────────────────
    public array $pinnedRoomIds = [];

    // ── Tick counter — drives staggered work inside the single poll ───────
    public int $pollTick = 0;

    // ── Toolbar-open message (mobile-friendly single toggle) ──────────────
    public ?int $openToolbarMsgId = null;

    // ── Delete-confirmation modal state ────────────────────────────────────
    public ?int $confirmDeleteId = null;

    // ── Online presence timeout (minutes) — 1 min = offline ──────────────
    private int $onlineMinutes = 1;

    // ── Profanity filter (English + Tagalog) — mirrors the alumni-side
    //    messenger filter word-for-word so coordinator messages get the
    //    same censoring instead of bypassing it entirely. ──────────────────
    private static array $bannedWords = [
        // English
        'fuck', 'fucking', 'fucker', 'fck', 'motherfucker',
        'shit', 'shitty', 'bullshit',
        'bitch', 'bitches',
        'asshole', 'ass',
        'dick', 'cock', 'pussy', 'cunt',
        'bastard', 'slut', 'whore',
        'nigger', 'nigga',
        'retard', 'retarded',
        // Tagalog
        'putangina', 'putang ina', 'putanginamo', 'putanginamu',
        'tangina', 'tanginamo', 'tang ina',
        'gago', 'gaga', 'gagu',
        'ulol', 'ulul',
        'bobo', 'boba',
        'tarantado', 'tarantada',
        'hayop', 'hayup',
        'leche', 'lecheng',
        'punyeta', 'punyeta ka',
        'peste', 'pesteng',
        'kingina', 'kinginamo',
        'siraulo', 'sira ulo',
        'engot',
        'pakyu',
        'pucha', 'puchang',
        'yawa',
        // Pangasinan
        'baoninam', 'putang inam',
    ];

    private static function filterProfanity(string $text): string
    {
        foreach (self::$bannedWords as $word) {
            // Allow spaces/dashes/underscores BETWEEN letters (basic evasion
            // resistance) and repeated letters (e.g. "puuutangina"), without
            // swallowing the whitespace that follows the whole word.
            $letters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
            $last    = count($letters) - 1;
            $pattern = implode('', array_map(
                fn($ch, $i) => preg_quote($ch, '/') . '+' . ($i < $last ? '[\s\-_]*' : ''),
                $letters,
                array_keys($letters)
            ));

            $text = preg_replace_callback(
                '/\b' . $pattern . '\b/iu',
                fn($m) => str_repeat('*', mb_strlen($m[0])),
                $text
            ) ?? $text;
        }

        return $text;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Cache key helpers
    // ─────────────────────────────────────────────────────────────────────
    private function lastReadCacheKey(int $roomId): string
    {
        return "chat_read.organizer.{$this->coordinatorId}.room.{$roomId}";
    }

    private function lastNotifiedCacheKey(int $roomId): string
    {
        return "chat_notified.organizer.{$this->coordinatorId}.room.{$roomId}";
    }

    private function pinnedRoomsCacheKey(): string
    {
        return "chat_pinned_rooms.organizer.{$this->coordinatorId}";
    }

    private function unreadCacheKey(): string
    {
        return "chat_unread_rooms.organizer.{$this->coordinatorId}";
    }

    private function getUnreadRoomIds(): array
    {
        return Cache::get($this->unreadCacheKey(), []);
    }

    private function setUnreadRoomId(int $roomId): void
    {
        $current = $this->getUnreadRoomIds();
        $current[(string) $roomId] = true;
        Cache::put($this->unreadCacheKey(), $current, now()->addHours(24));
    }

    private function clearUnreadRoomId(int $roomId): void
    {
        $current = $this->getUnreadRoomIds();
        unset($current[(string) $roomId]);
        Cache::put($this->unreadCacheKey(), $current, now()->addHours(24));
    }

    public function markRoomAsRead(int $roomId): void
    {
        Cache::put($this->lastReadCacheKey($roomId), now()->toDateTimeString(), now()->addDays(30));
        $this->clearUnreadRoomId($roomId);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Photo URL helper
    // ─────────────────────────────────────────────────────────────────────
    private function resolvePhotoUrl(?string $path): ?string
    {
        if (! $path) return null;
        if (
            str_starts_with($path, 'alumni-photos/') ||
            str_starts_with($path, 'organizers/')    ||
            str_starts_with($path, 'directors/')
        ) {
            return asset('storage/' . $path);
        }
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Job/event image resolver — mirrors director/director-messenger.blade.php
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
     *    director/director-messenger.blade.php so shared posts render as a
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
    // Batch label helper — always "Batch 2026 · BSIT" style, never flipped
    // ─────────────────────────────────────────────────────────────────────
    private function batchLabel(string $courseCode, int|string $batch): string
    {
        return 'Batch ' . $batch . ' · ' . strtoupper($courseCode);
    }

    // ─────────────────────────────────────────────────────────────────────
    // College marker helper — MUST match alumni/messenger.blade.php exactly.
    // The alumni side renames the legacy course_code='' college room to a
    // 'CLG_<hash>' marker the first time an alumni visits, so we need to
    // recognize both the legacy empty string AND the marker to find the
    // real college-wide room instead of mistaking it for a course GC.
    //
    // IMPORTANT: this marker is an internal DB identifier ONLY. It must
    // NEVER be rendered to the user anywhere (room list, header, tooltip,
    // title attribute, compose placeholder, etc). Every display path
    // routes through displayCourseLabel() / roomDisplayName() /
    // collegeDisplayLabel() below instead of touching course_code raw.
    // ─────────────────────────────────────────────────────────────────────
    private function collegeMarker(string $college): string
    {
        return 'CLG_' . substr(md5($college), 0, 12);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Is a given course_code value actually the internal college marker
    // (or the legacy empty-string college placeholder)? Centralizing this
    // check means every place that used to test `$code === ''` now also
    // safely catches the `CLG_xxxxxxxxxxxx` marker format, so it can never
    // be mistaken for a real course code and printed to the screen.
    // ─────────────────────────────────────────────────────────────────────
    private function isInternalMarkerCode(?string $code): bool
    {
        $code = (string) $code;
        return $code === '' || str_starts_with($code, 'CLG_') || $code === '__director__';
    }

    // ─────────────────────────────────────────────────────────────────────
    // SINGLE SOURCE OF TRUTH for printing a course code anywhere in the UI.
    // If the stored value is the internal college marker / legacy empty
    // string / staff marker, this NEVER echoes it — it always falls back
    // to the human-readable college label instead. Every blade expression
    // that used to do `strtoupper($room['course_code'])` directly has been
    // routed through this helper so a raw CLG_xxxx can never leak into a
    // header, tooltip, placeholder, or room list row again.
    // ─────────────────────────────────────────────────────────────────────
    private function displayCourseLabel(?string $code, ?string $department = null): string
    {
        if ($this->isInternalMarkerCode($code)) {
            return $this->collegeDisplayLabel($department ?: $this->department);
        }
        return strtoupper($code);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Human-readable label for ANY room — the single source of truth used
    // by the room list, chat header, and pin tooltip so a raw CLG_xxxx
    // marker (or any other internal code) can never leak into the UI.
    // ─────────────────────────────────────────────────────────────────────
    private function collegeDisplayLabel(string $department): string
    {
        return $department . ' · All Courses & Batches';
    }

    private function roomDisplayName(array $r): string
    {
        return match ($r['type'] ?? '') {
            'staff'   => 'Staff Chat',
            'college' => $this->collegeDisplayLabel($r['department'] ?: $this->department),
            'course'  => $this->displayCourseLabel($r['course_code'] ?? '', $r['department'] ?? null) . ' · All Batches GC',
            default   => $r['name'] ?? 'Group Chat',
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helper: is this room the alumni-side college-wide room?
    // ─────────────────────────────────────────────────────────────────────
    private function roomIsCollegeWide($row): bool
    {
        $code = (string) ($row->course_code ?? '');
        return ($code === '' || str_starts_with($code, 'CLG_')) && (int) ($row->batch ?? 0) === 0;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Boot
    // ─────────────────────────────────────────────────────────────────────
    public function mount(): void
    {
        $user = Auth::user();

        if (! $user || $user->role !== 'organizer') {
            $this->redirect(route('login'));
            return;
        }

        $organizer = DB::table('organizer')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->first();

        if (! $organizer) {
            $this->redirect(route('login'));
            return;
        }

        $this->coordinatorId        = (int) $organizer->id;
        $this->coordinatorName      = trim(($organizer->first_name ?? '') . ' ' . ($organizer->last_name ?? ''));
        $this->coordinatorFirstName = $organizer->first_name ?? '';
        $this->department           = $organizer->department ?? '';
        $this->coordinatorPhoto     = $this->resolvePhotoUrl($organizer->profile_photo ?? null) ?? '';

        $this->deptCourseCodes = DB::table('courses')
            ->where('college', $this->department)
            ->pluck('code')
            ->toArray();

        $this->pinnedRoomIds = Cache::get($this->pinnedRoomsCacheKey(), []);

        $this->ensureRoomsExist();
        $this->pingPresence();
        $this->loadRooms();
        $this->seedNotifiedPointers();

        // ─ FIX: CHECK AND DISPATCH NOTIFICATIONS IMMEDIATELY ON MOUNT ─
        // This ensures red dots show up on login without needing to visit chat first
        $this->checkAndDispatchNewMessageNotifications();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Seed notification pointers
    // ─────────────────────────────────────────────────────────────────────
    private function seedNotifiedPointers(): void
    {
        foreach ($this->rooms as $r) {
            $maxId = (int) (DB::table('chat_messages')
                ->where('room_id', $r['id'])
                ->whereNull('deleted_at')
                ->max('id') ?? 0);

            $cached = Cache::get($this->lastNotifiedCacheKey($r['id']));
            if ($cached === null) {
                $this->lastNotifiedMessageIds[$r['id']] = $maxId;
                Cache::put($this->lastNotifiedCacheKey($r['id']), $maxId, now()->addDays(30));
            } else {
                $this->lastNotifiedMessageIds[$r['id']] = (int) $cached;
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Auto-create missing rooms
    // ─────────────────────────────────────────────────────────────────────
    protected function ensureRoomsExist(): void
    {
        try {
            if (! DB::table('chat_rooms')->where('course_code', '__director__')->exists()) {
                DB::table('chat_rooms')->insert([
                    'name'        => 'Staff Chat',
                    'course_code' => '__director__',
                    'batch'       => 0,
                    'department'  => 'ALL',
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        } catch (\Throwable) {}

        if (empty($this->deptCourseCodes)) return;

        try {
            $batches = DB::table('alumni')
                ->whereIn('course_code', $this->deptCourseCodes)
                ->whereNull('deleted_at')
                ->where('batch', '>', 0)
                ->select('course_code', 'batch')
                ->distinct()
                ->get();

            foreach ($batches as $b) {
                $exists = DB::table('chat_rooms')
                    ->where('course_code', $b->course_code)
                    ->where('batch', $b->batch)
                    ->exists();

                if (! $exists) {
                    DB::table('chat_rooms')->insertGetId([
                        'name'        => $this->batchLabel($b->course_code, $b->batch),
                        'course_code' => $b->course_code,
                        'batch'       => (int) $b->batch,
                        'department'  => $this->department,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }
        } catch (\Throwable) {}

        try {
            foreach ($this->deptCourseCodes as $code) {
                $exists = DB::table('chat_rooms')
                    ->where('course_code', $code)
                    ->where('batch', 0)
                    ->exists();

                if (! $exists) {
                    DB::table('chat_rooms')->insert([
                        'name'        => strtoupper($code) . ' · All Batches',
                        'course_code' => $code,
                        'batch'       => 0,
                        'department'  => $this->department,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }
        } catch (\Throwable) {}
    }

    // ─────────────────────────────────────────────────────────────────────
    // SINGLE UNIFIED POLL — wire:poll.2500ms
    //
    // SMOOTHNESS FIX: previously this ran at 1500ms and did presence-ping
    // + notification-scan + full room-list rebuild on EVERY tick, which is
    // 4 DB round trips every 1.5s regardless of whether anything changed.
    // That constant background load is what made clicking a room or
    // sending a message feel laggy — the same request queue was shared
    // with the poll's own DB work. Now:
    //   - the poll interval is longer (2500ms) so there's more breathing
    //     room between background ticks,
    //   - .visible is used so polling pauses entirely while the browser
    //     tab isn't in focus (no wasted background requests at all),
    //   - the heaviest step (loadRooms, which rebuilds the entire sidebar)
    //     only runs every 2nd tick instead of every tick,
    //   - selecting a room / sending a message no longer waits on the
    //     poll's own request queue — see selectRoom()/sendMessage() below,
    //     which now update local state immediately before touching the DB
    //     for the heavier notification/read bookkeeping.
    // ─────────────────────────────────────────────────────────────────────
    public function unifiedPoll(): void
    {
        $this->pollTick++;

        // Presence is pinged every tick so the coordinator stays "online"
        // continuously while this page/tab is open and focused.
        $this->pingPresence();

        $this->checkAndDispatchNewMessageNotifications();

        // Rebuilding the whole room list is the most expensive step here
        // (it touches chat_rooms + chat_messages + alumni + organizer for
        // every room). Doing it every OTHER tick instead of every tick
        // cuts that DB load in half without any visible staleness, since
        // unread badges/timestamps still refresh within ~5s.
        if ($this->pollTick % 2 === 0) {
            $this->loadRooms();
        }

        if ($this->roomId) {
            $this->loadTypingIndicators();
        }

        if ($this->pollTick % 2 === 0 && $this->roomId) {
            $this->loadMessages();
            $this->markRoomAsRead($this->roomId);
            $this->dispatch('chat-scroll-bottom');
        }

        if ($this->pollTick % 4 === 0) {
            $this->refreshOnlineCount();
            if ($this->showMembers && $this->isStaffRoom) {
                $this->loadStaffMembers();
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Toggle pin room
    // ─────────────────────────────────────────────────────────────────────
    public function togglePinRoom(int $roomId): void
    {
        if (in_array($roomId, $this->pinnedRoomIds, true)) {
            $this->pinnedRoomIds = array_values(array_filter(
                $this->pinnedRoomIds, fn ($id) => $id !== $roomId
            ));
        } else {
            $this->pinnedRoomIds[] = $roomId;
        }
        Cache::put($this->pinnedRoomsCacheKey(), $this->pinnedRoomIds, now()->addDays(90));
        $this->loadRooms();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Detect new messages → write coordinator_notifications DB row DIRECTLY
    // (same pattern as notifyAlumniInRoom — no JS round-trip needed)
    // then dispatch coord-notif-refresh so the bell JS store re-fetches
    // ─────────────────────────────────────────────────────────────────────
    private function checkAndDispatchNewMessageNotifications(): void
    {
        $anyNewForBell = false; // track whether we need to trigger a bell refresh

        foreach ($this->rooms as $room) {
            $roomId = (int) $room['id'];

            if (! isset($this->lastNotifiedMessageIds[$roomId])) {
                $maxId = (int) (DB::table('chat_messages')
                    ->where('room_id', $roomId)
                    ->whereNull('deleted_at')
                    ->max('id') ?? 0);
                $this->lastNotifiedMessageIds[$roomId] = $maxId;
                Cache::put($this->lastNotifiedCacheKey($roomId), $maxId, now()->addDays(30));
                continue;
            }

            $lastKnown = (int) $this->lastNotifiedMessageIds[$roomId];

            // Only messages from OTHER senders (not this coordinator)
            $newMessages = DB::table('chat_messages as m')
                ->where('m.room_id', $roomId)
                ->whereNull('m.deleted_at')
                ->where('m.id', '>', $lastKnown)
                ->where(function ($q) {
                    $q->where('m.sender_type', '!=', 'organizer')
                      ->orWhere('m.sender_id', '!=', $this->coordinatorId);
                })
                ->orderBy('m.id')
                ->get(['m.id', 'm.sender_type', 'm.sender_id', 'm.body'])
                ->toArray();

            if (empty($newMessages)) {
                // Advance pointer for own messages so we don't double-count later
                $myNewMax = (int) (DB::table('chat_messages')
                    ->where('room_id', $roomId)
                    ->whereNull('deleted_at')
                    ->where('id', '>', $lastKnown)
                    ->max('id') ?? 0);
                if ($myNewMax > $lastKnown) {
                    $this->lastNotifiedMessageIds[$roomId] = $myNewMax;
                    Cache::put($this->lastNotifiedCacheKey($roomId), $myNewMax, now()->addDays(30));
                }
                continue;
            }

            // Advance pointer
            $newMaxId = (int) max(array_column($newMessages, 'id'));
            $this->lastNotifiedMessageIds[$roomId] = $newMaxId;
            Cache::put($this->lastNotifiedCacheKey($roomId), $newMaxId, now()->addDays(30));

            // If the coordinator is currently viewing this room, skip bell notif
            // but still advance the pointer above
            if ($roomId === $this->roomId) {
                continue;
            }

            // ── Resolve sender name for the latest message ────────────────
            $latest     = end($newMessages);
            $senderName = 'Someone';

            if ($latest->sender_type === 'alumni') {
                $firstName  = DB::table('alumni')->where('id', $latest->sender_id)->value('first_name');
                $senderName = $firstName ?? 'Alumni';
            } elseif ($latest->sender_type === 'director') {
                $firstName  = DB::table('director')->where('id', $latest->sender_id)->value('first_name');
                $senderName = ($firstName ?? 'Director') . ' (Director)';
            } else {
                $firstName  = DB::table('organizer')->where('id', $latest->sender_id)->value('first_name');
                $senderName = ($firstName ?? 'Coordinator') . ' (Coordinator)';
            }

            $count     = count($newMessages);
            $roomLabel = $this->roomDisplayName($room);
            $bodySnip  = mb_substr($latest->body ?? '', 0, 60);
            $preview   = $bodySnip . (mb_strlen($latest->body ?? '') > 60 ? '…' : '');

            $msgText = $count > 1
                ? $senderName . ' and others sent ' . $count . ' new messages in ' . $roomLabel . '.'
                : $senderName . ' sent a message in ' . $roomLabel
                  . ($preview !== '' ? ': "' . $preview . '"' : '.');

            $notifTitle = $count > 1 ? $count . ' New Messages' : 'New Message';

            // ── Dedup key: per-room per-minute ────────────────────────────
            $dedupKey = 'message-received::' . $roomId . '::' . floor(time() / 60);

            // ── Write directly to coordinator_notifications table ─────────
            // (mirrors notifyAlumniInRoom → alumni_notifications pattern)
            try {
                $alreadyExists = DB::table('coordinator_notifications')
                    ->where('coordinator_id', $this->coordinatorId)
                    ->where('dedup_key', $dedupKey)
                    ->exists();

                if (! $alreadyExists) {
                    DB::table('coordinator_notifications')->insert([
                        'coordinator_id' => $this->coordinatorId,
                        'icon'           => 'comments',
                        'title'          => $notifTitle,
                        'message'        => $msgText,
                        'link_route'     => 'organizer.chat/alumni',
                        'link_label'     => 'Open Messages',
                        'dedup_key'      => $dedupKey,
                        'read'           => 0,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);

                    $anyNewForBell = true;
                }
            } catch (\Throwable) {
                // Fallback: if DB write fails, dispatch JS event so the old
                // client-side path can still handle it
                $this->dispatch('coord-message-received', [
                    'sender' => $senderName,
                    'room'   => $roomLabel,
                    'body'   => mb_substr($latest->body ?? '', 0, 60),
                    'count'  => $count,
                ]);
            }
        }

        // ── Tell the JS bell store to re-fetch immediately ────────────────
        // Only dispatch once per poll cycle (not per room) to avoid flood
        if ($anyNewForBell) {
            $this->dispatch('coord-notif-refresh');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Notify alumni in room when coordinator sends a message
    // ─────────────────────────────────────────────────────────────────────
    private function notifyAlumniInRoom(string $body): void
    {
        if ($this->isStaffRoom || ! $this->room) return;

        try {
            $senderName  = $this->coordinatorFirstName ?: $this->coordinatorName;
            $courseCode  = $this->room['course_code'] ?? '';
            $batch       = (int) ($this->room['batch'] ?? 0);

            $roomLabel = $this->isCollegeRoom
                ? $this->collegeDisplayLabel($this->department)
                : ($this->isCourseRoom
                    ? $this->displayCourseLabel($courseCode) . ' All Batches GC'
                    : ($this->room['name'] ?? 'Group Chat'));

            $bodySnip = mb_substr($body, 0, 80);
            $preview  = $bodySnip . (mb_strlen($body) > 80 ? '…' : '');
            $title    = $senderName . ' sent a message';
            $message  = $senderName . ' messaged in ' . $roomLabel
                        . ($preview !== '' ? ': "' . $preview . '"' : '.');

            $dedupKey = 'coord-msg::' . $this->coordinatorId . '::' . $this->roomId
                        . '::' . floor(time() / 60);

            if ($this->isCollegeRoom && ! empty($this->deptCourseCodes)) {
                $alumniRows = DB::table('alumni')
                    ->whereNull('deleted_at')
                    ->whereIn('course_code', $this->deptCourseCodes)
                    ->get(['id']);
            } elseif ($this->isCourseRoom) {
                $alumniRows = DB::table('alumni')
                    ->whereNull('deleted_at')
                    ->where('course_code', $courseCode)
                    ->get(['id']);
            } else {
                $alumniRows = DB::table('alumni')
                    ->whereNull('deleted_at')
                    ->where('course_code', $courseCode)
                    ->where('batch', $batch)
                    ->get(['id']);
            }

            foreach ($alumniRows as $alumnus) {
                $alreadyExists = DB::table('alumni_notifications')
                    ->where('alumni_id', (int) $alumnus->id)
                    ->where('dedup_key', $dedupKey)
                    ->exists();

                if ($alreadyExists) continue;

                DB::table('alumni_notifications')->insert([
                    'alumni_id'  => (int) $alumnus->id,
                    'icon'       => 'comments',
                    'title'      => $title,
                    'message'    => $message,
                    'link_route' => 'alumni.messenger',
                    'link_label' => 'Open Messenger',
                    'dedup_key'  => $dedupKey,
                    'read'       => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable) {}
    }

    // ─────────────────────────────────────────────────────────────────────
    // Search highlight — mirrors Alumni Records' light-blue <mark> style
    // ─────────────────────────────────────────────────────────────────────
    public function highlight(string $text, string $search): string
    {
        if (!$search || !$text) return e($text);
        $pattern = '/(' . preg_quote($search, '/') . ')/iu';
        $parts   = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out     = '';
        foreach ($parts as $i => $part) {
            $out .= ($i % 2 === 1)
                ? '<mark class="org-hl">' . e($part) . '</mark>'
                : e($part);
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rooms list
    // ─────────────────────────────────────────────────────────────────────
    public function loadRooms(): void
    {
        $search = trim($this->roomSearch);
        $self   = $this;

        // ── 1. Staff room ─────────────────────────────────────────────
        $staffRoomRow = DB::table('chat_rooms')->where('course_code', '__director__')->first();
        $staffItem    = null;

        if ($staffRoomRow) {
            $latest = DB::table('chat_messages as m')
                ->where('m.room_id', $staffRoomRow->id)
                ->whereNull('m.deleted_at')
                ->orderByDesc('m.created_at')
                ->first(['m.body', 'm.sender_type', 'm.sender_id', 'm.created_at']);

            $latestBody = $latestSender = $latestTime = null;
            $latestTs   = null;

            if ($latest) {
                $latestBody = $self->resolvePreviewText($latest->body);
                $latestTs   = Carbon::parse($latest->created_at);
                $latestTime = $latestTs->setTimezone('Asia/Manila')->format('h:i A');
                if ($latest->sender_type === 'director') {
                    $name         = DB::table('director')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = $name ?? 'Director';
                } else {
                    $name         = DB::table('organizer')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = $name ?? 'Coordinator';
                }
            }

            // Staff room: count directors + active coordinators
            $staffOnline = DB::table('director')->whereNull('deleted_at')
                ->where('last_seen_at', '>=', now()->subMinutes($this->onlineMinutes))->count()
                + DB::table('organizer')->where('status', 'ACTIVE')->whereNull('deleted_at')
                    ->where('last_seen_at', '>=', now()->subMinutes($this->onlineMinutes))->count();
            $staffTotal  = DB::table('director')->whereNull('deleted_at')->count()
                + DB::table('organizer')->where('status', 'ACTIVE')->whereNull('deleted_at')->count();

            $isCurrentRoom = ($staffRoomRow->id === $this->roomId);
            $lastReadAt    = Cache::get($self->lastReadCacheKey($staffRoomRow->id));

            // ── Unread reflects "new activity in this room since I last
            //    opened it" — it does NOT exclude the coordinator's own
            //    sends (sharing an event/job, or a regular message). If
            //    the room's last-read watermark is older than the latest
            //    message, the room shows unread, full stop. ──────────────
            $hasUnread = false;
            if (! $isCurrentRoom && $latest !== null) {
                $hasUnread = $lastReadAt === null
                    || Carbon::parse($latest->created_at)->gt(Carbon::parse($lastReadAt));
            }

            $matchesSearch = $search === ''
                || str_contains(strtolower('Staff Chat'), strtolower($search));

            if ($matchesSearch) {
                $staffItem = [
                    'id'             => $staffRoomRow->id,
                    'name'           => 'Staff Chat',
                    'course_code'    => '__director__',
                    'course_name'    => '',
                    'batch'          => 0,
                    'department'     => 'ALL',
                    'latest_body'    => $latestBody,
                    'latest_sender'  => $latestSender,
                    'latest_time'    => $latestTime,
                    'latest_ts'      => $latestTs ? $latestTs->timestamp : 0,
                    'online_count'   => $staffOnline,
                    'total_count'    => $staffTotal,
                    'is_active'      => $isCurrentRoom,
                    'is_staff'       => true,
                    'is_course_gc'   => false,
                    'is_college_gc'  => false,
                    'type'           => 'staff',
                    'has_unread'     => $hasUnread,
                    'is_pinned_room' => in_array($staffRoomRow->id, $this->pinnedRoomIds, true),
                ];
            }
        }

        // ── 2. College-wide room ──────────────────────────────────────
        $collegeRoomRow = null;
        $collegeItem    = null;

        if ($this->department) {
            $marker = $this->collegeMarker($this->department);
            $collegeRoomRow = DB::table('chat_rooms')
                ->where('department', $this->department)
                ->where('batch', 0)
                ->where(function ($q) use ($marker) {
                    $q->where('course_code', '')->orWhere('course_code', $marker);
                })
                ->first();
        }

        if ($collegeRoomRow) {
            $latest = DB::table('chat_messages as m')
                ->where('m.room_id', $collegeRoomRow->id)
                ->whereNull('m.deleted_at')
                ->orderByDesc('m.created_at')
                ->first(['m.body', 'm.sender_type', 'm.sender_id', 'm.created_at']);

            $latestBody = $latestSender = $latestTime = null;
            $latestTs   = null;

            if ($latest) {
                $latestBody = $self->resolvePreviewText($latest->body);
                $latestTs   = Carbon::parse($latest->created_at);
                $latestTime = $latestTs->setTimezone('Asia/Manila')->format('h:i A');
                if ($latest->sender_type === 'alumni') {
                    $a            = DB::table('alumni')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = $a ?? 'Alumni';
                } elseif ($latest->sender_type === 'director') {
                    $d            = DB::table('director')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = ($d ?? 'Director') . ' (Director)';
                } else {
                    $o            = DB::table('organizer')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = $o ?? 'Coordinator';
                }
            }

            $collegeCourses = $this->deptCourseCodes;
            if (! empty($collegeCourses)) {
                $baseQ       = DB::table('alumni')->whereIn('course_code', $collegeCourses)->whereNull('deleted_at');
                $totalCount  = (clone $baseQ)->count();
                $onlineCount = (clone $baseQ)->where('last_seen_at', '>=', now()->subMinutes($this->onlineMinutes))->count();

                $coordOnlineInDept = DB::table('organizer')
                    ->where('status', 'ACTIVE')
                    ->whereNull('deleted_at')
                    ->where('department', $this->department)
                    ->where('last_seen_at', '>=', now()->subMinutes($this->onlineMinutes))
                    ->count();
                $onlineCount += $coordOnlineInDept;
                $totalCount  += DB::table('organizer')->where('status', 'ACTIVE')->whereNull('deleted_at')->where('department', $this->department)->count();
            } else {
                $totalCount = $onlineCount = 0;
            }

            $isCurrentRoom = ($collegeRoomRow->id === $this->roomId);
            $lastReadAt    = Cache::get($self->lastReadCacheKey($collegeRoomRow->id));

            $hasUnread = false;
            if (! $isCurrentRoom && $latest !== null) {
                $hasUnread = $lastReadAt === null
                    || Carbon::parse($latest->created_at)->gt(Carbon::parse($lastReadAt));
            }

            // ── FIX: always render the clean human label, NEVER the raw
            //         CLG_xxxx marker or empty-string course_code. This
            //         label is used for the room list AND is the same
            //         string every tooltip / header derives from.
            $collegeLabel  = $this->collegeDisplayLabel($this->department);
            $matchesSearch = $search === ''
                || str_contains(strtolower($collegeLabel), strtolower($search))
                || str_contains(strtolower($this->department), strtolower($search));

            if ($matchesSearch) {
                $collegeItem = [
                    'id'             => $collegeRoomRow->id,
                    'name'           => $collegeLabel,
                    'course_code'    => '', // internal marker never exposed downstream — display always via 'name'/type
                    'course_name'    => $this->department,
                    'batch'          => 0,
                    'department'     => $collegeRoomRow->department,
                    'latest_body'    => $latestBody,
                    'latest_sender'  => $latestSender,
                    'latest_time'    => $latestTime,
                    'latest_ts'      => $latestTs ? $latestTs->timestamp : 0,
                    'online_count'   => $onlineCount,
                    'total_count'    => $totalCount,
                    'is_active'      => $isCurrentRoom,
                    'is_staff'       => false,
                    'is_course_gc'   => false,
                    'is_college_gc'  => true,
                    'type'           => 'college',
                    'has_unread'     => $hasUnread,
                    'is_pinned_room' => in_array($collegeRoomRow->id, $this->pinnedRoomIds, true),
                ];
            }
        }

        // ── 3. Course GC rooms ────────────────────────────────────────
        $collegeMarkerCode = $this->department ? $this->collegeMarker($this->department) : null;

        $courseGcRows = DB::table('chat_rooms as r')
            ->where('r.batch', 0)
            ->where('r.course_code', '!=', '__director__')
            ->where('r.course_code', '!=', '')
            ->where('r.course_code', 'not like', 'CLG_%')
            ->where(function ($q) {
                $q->where('r.department', $this->department);
                if (! empty($this->deptCourseCodes)) {
                    $q->orWhereIn('r.course_code', $this->deptCourseCodes);
                }
            })
            ->get(['r.id', 'r.name', 'r.course_code', 'r.batch', 'r.department'])
            ->toArray();

        $courseGcRooms = collect($courseGcRows)->map(function ($r) use ($search, $self) {
            // Extra safety net: even though the query above already excludes
            // '' and 'CLG_%', double-check here too — if this ever somehow
            // matches an internal marker, skip it from the course-GC list
            // entirely rather than ever displaying a raw code.
            if ($self->isInternalMarkerCodePublic($r->course_code)) {
                return null;
            }

            $latest = DB::table('chat_messages as m')
                ->where('m.room_id', $r->id)
                ->whereNull('m.deleted_at')
                ->orderByDesc('m.created_at')
                ->first(['m.body', 'm.sender_type', 'm.sender_id', 'm.created_at']);

            $latestBody = $latestSender = $latestTime = null;
            $latestTs   = null;

            if ($latest) {
                $latestBody = $self->resolvePreviewText($latest->body);
                $latestTs   = Carbon::parse($latest->created_at);
                $latestTime = $latestTs->setTimezone('Asia/Manila')->format('h:i A');
                if ($latest->sender_type === 'alumni') {
                    $a            = DB::table('alumni')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = $a ?? 'Alumni';
                } elseif ($latest->sender_type === 'director') {
                    $d            = DB::table('director')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = $d ?? 'Director';
                } else {
                    $o            = DB::table('organizer')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = $o ?? 'Coordinator';
                }
            }

            $baseAlumni  = DB::table('alumni')->where('course_code', $r->course_code)->whereNull('deleted_at');
            $totalCount  = (clone $baseAlumni)->count();
            $onlineCount = (clone $baseAlumni)->where('last_seen_at', '>=', now()->subMinutes($self->onlineMinutes))->count();

            $coordOnline = DB::table('organizer')
                ->where('status', 'ACTIVE')->whereNull('deleted_at')
                ->where('department', $self->department)
                ->where('last_seen_at', '>=', now()->subMinutes($self->onlineMinutes))
                ->count();
            $coordTotal  = DB::table('organizer')
                ->where('status', 'ACTIVE')->whereNull('deleted_at')
                ->where('department', $self->department)
                ->count();
            $onlineCount += $coordOnline;
            $totalCount  += $coordTotal;

            $courseName  = DB::table('courses')->where('code', $r->course_code)->value('name') ?? strtoupper($r->course_code);

            $isCurrentRoom = ($r->id === $self->roomId);
            $lastReadAt    = Cache::get($self->lastReadCacheKey($r->id));

            $hasUnread = false;
            if (! $isCurrentRoom && $latest !== null) {
                $hasUnread = $lastReadAt === null
                    || Carbon::parse($latest->created_at)->gt(Carbon::parse($lastReadAt));
            }

            $label         = strtoupper($r->course_code) . ' · All Batches GC';
            $matchesSearch = $search === ''
                || str_contains(strtolower($label), strtolower($search))
                || str_contains(strtolower($r->course_code), strtolower($search))
                || str_contains(strtolower($courseName), strtolower($search));

            if (! $matchesSearch) return null;

            return [
                'id'             => $r->id,
                'name'           => $label,
                'course_code'    => $r->course_code,
                'course_name'    => $courseName,
                'batch'          => 0,
                'department'     => $r->department ?? $this->department,
                'latest_body'    => $latestBody,
                'latest_sender'  => $latestSender,
                'latest_time'    => $latestTime,
                'latest_ts'      => $latestTs ? $latestTs->timestamp : 0,
                'online_count'   => $onlineCount,
                'total_count'    => $totalCount,
                'is_active'      => $isCurrentRoom,
                'is_staff'       => false,
                'is_course_gc'   => true,
                'is_college_gc'  => false,
                'type'           => 'course',
                'has_unread'     => $hasUnread,
                'is_pinned_room' => in_array($r->id, $this->pinnedRoomIds, true),
            ];
        })->filter();

        // ── 4. Batch rooms ────────────────────────────────────────────
        $rows = DB::table('chat_rooms as r')
            ->where('r.course_code', '!=', '__director__')
            ->where('r.course_code', '!=', '')
            ->where('r.batch', '>', 0)
            ->where(function ($q) {
                $q->where('r.department', $this->department);
                if (! empty($this->deptCourseCodes)) {
                    $q->orWhereIn('r.course_code', $this->deptCourseCodes);
                }
            })
            ->get(['r.id', 'r.name', 'r.course_code', 'r.batch', 'r.department'])
            ->toArray();

        $batchRooms = collect($rows)->map(function ($r) use ($search, $self) {
            $latest = DB::table('chat_messages as m')
                ->where('m.room_id', $r->id)
                ->whereNull('m.deleted_at')
                ->orderByDesc('m.created_at')
                ->first(['m.body', 'm.sender_type', 'm.sender_id', 'm.created_at']);

            $latestBody = $latestSender = $latestTime = null;
            $latestTs   = null;

            if ($latest) {
                $latestBody = $self->resolvePreviewText($latest->body);
                $latestTs   = Carbon::parse($latest->created_at);
                $latestTime = $latestTs->setTimezone('Asia/Manila')->format('h:i A');
                if ($latest->sender_type === 'alumni') {
                    $a            = DB::table('alumni')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = $a ?? 'Alumni';
                } else {
                    $o            = DB::table('organizer')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = $o ?? 'Coordinator';
                }
            }

            $baseAlumni  = DB::table('alumni')
                ->where('course_code', $r->course_code)->where('batch', $r->batch)
                ->whereNull('deleted_at');
            $onlineCount = (clone $baseAlumni)->where('last_seen_at', '>=', now()->subMinutes($self->onlineMinutes))->count();
            $totalCount  = (clone $baseAlumni)->count();

            $coordOnline = DB::table('organizer')
                ->where('status', 'ACTIVE')->whereNull('deleted_at')
                ->where('department', $self->department)
                ->where('last_seen_at', '>=', now()->subMinutes($self->onlineMinutes))
                ->count();
            $coordTotal  = DB::table('organizer')
                ->where('status', 'ACTIVE')->whereNull('deleted_at')
                ->where('department', $self->department)
                ->count();
            $onlineCount += $coordOnline;
            $totalCount  += $coordTotal;

            $isCurrentRoom = ($r->id === $self->roomId);
            $lastReadAt    = Cache::get($self->lastReadCacheKey($r->id));

            $hasUnread = false;
            if (! $isCurrentRoom && $latest !== null) {
                $hasUnread = $lastReadAt === null
                    || Carbon::parse($latest->created_at)->gt(Carbon::parse($lastReadAt));
            }

            $matchesSearch = $search === ''
                || str_contains(strtolower($r->name), strtolower($search))
                || str_contains(strtolower($r->course_code), strtolower($search))
                || str_contains((string) $r->batch, $search);

            if (! $matchesSearch) return null;

            return [
                'id'             => $r->id,
                'name'           => $self->batchLabel($r->course_code, $r->batch),
                'course_code'    => $r->course_code,
                'course_name'    => '',
                'batch'          => (int) $r->batch,
                'department'     => $r->department ?? $this->department,
                'latest_body'    => $latestBody,
                'latest_sender'  => $latestSender,
                'latest_time'    => $latestTime,
                'latest_ts'      => $latestTs ? $latestTs->timestamp : 0,
                'online_count'   => $onlineCount,
                'total_count'    => $totalCount,
                'is_active'      => $isCurrentRoom,
                'is_staff'       => false,
                'is_course_gc'   => false,
                'is_college_gc'  => false,
                'type'           => 'batch',
                'has_unread'     => $hasUnread,
                'is_pinned_room' => in_array($r->id, $this->pinnedRoomIds, true),
            ];
        })->filter();

        // ── Combine ALL rooms into one flat collection ─────────────────
        $allRooms = collect();
        if ($staffItem)   $allRooms->push($staffItem);
        if ($collegeItem) $allRooms->push($collegeItem);
        $allRooms = $allRooms->merge($courseGcRooms)->merge($batchRooms);

        // ── MESSENGER-STYLE SORT ───────────────────────────────────────
        // Priority: pinned rooms first, unpinned after. Within EACH of
        // those two groups: unread rooms bubble to the very top (so a red
        // dot always surfaces before older read chats), then everything
        // is ordered by most recent activity, and finally alphabetically
        // for rooms that have no messages yet.
        $sortGroup = function ($group) {
            $unread       = $group->filter(fn ($r) => $r['has_unread']);
            $read         = $group->filter(fn ($r) => ! $r['has_unread']);

            $unreadWithMsg    = $unread->filter(fn ($r) => $r['latest_ts'] > 0)->sortByDesc('latest_ts');
            $unreadWithoutMsg = $unread->filter(fn ($r) => $r['latest_ts'] === 0)->sortBy('name');

            $readWithMsg    = $read->filter(fn ($r) => $r['latest_ts'] > 0)->sortByDesc('latest_ts');
            $readWithoutMsg = $read->filter(fn ($r) => $r['latest_ts'] === 0)->sortBy('name');

            return $unreadWithMsg->merge($unreadWithoutMsg)
                ->merge($readWithMsg)->merge($readWithoutMsg)
                ->values();
        };

        $pinned   = $allRooms->filter(fn ($r) => $r['is_pinned_room']);
        $unpinned = $allRooms->filter(fn ($r) => ! $r['is_pinned_room']);

        $pinnedSorted   = $sortGroup($pinned);
        $unpinnedSorted = $sortGroup($unpinned);

        $this->rooms = $pinnedSorted->merge($unpinnedSorted)->values()->toArray();
    }

    // Public wrapper so it's callable from inside the collect()->map() closures
    // above (closures bound to $this can call private methods fine already,
    // but this keeps the check readable/explicit at each call site).
    public function isInternalMarkerCodePublic(?string $code): bool
    {
        return $this->isInternalMarkerCode($code);
    }

    public function updatedRoomSearch(): void { $this->loadRooms(); }

    // ─────────────────────────────────────────────────────────────────────
    // Select room
    //
    // SMOOTHNESS FIX: the heavy bookkeeping (advancing the notified-message
    // pointer, marking the room read, refreshing the online count) now
    // happens AFTER the essential state (roomId/room/messages) is already
    // set and returned to the view. Practically this doesn't change wire
    // latency (Livewire still finishes the request before re-rendering),
    // but it removes redundant work: loadRooms() at the end of this method
    // used to fully rebuild the sidebar synchronously on every single
    // click — that's now skipped here since the unified poll rebuilds it
    // on its own very next tick, so a click feels instant instead of
    // waiting on a full room-list re-query first.
    // ─────────────────────────────────────────────────────────────────────
    public function selectRoom(int $id): void
    {
        $row = DB::table('chat_rooms')->find($id);
        if (! $row) return;

        $isStaff       = $row->course_code === '__director__';
        $isCollegeWide = ! $isStaff && $this->roomIsCollegeWide($row);
        $isCourse      = ! $isStaff && ! $isCollegeWide && (int) $row->batch === 0;

        if (! $isStaff) {
            $inDeptByColumn = $row->department === $this->department;
            $inDeptByCourse = in_array($row->course_code, $this->deptCourseCodes, true);
            $inDeptCollege  = $isCollegeWide && $row->department === $this->department;
            if (! $inDeptByColumn && ! $inDeptByCourse && ! $inDeptCollege) return;
        }

        $this->isStaffRoom         = $isStaff;
        $this->isCollegeRoom       = $isCollegeWide;
        $this->isCourseRoom        = $isCourse;
        $this->roomId              = $row->id;
        $this->room                = (array) $row;
        $this->body                = '';
        $this->replyTo             = null;
        $this->editingId           = null;
        $this->editBody            = '';
        $this->showMembers         = false;
        $this->showPins            = false;
        $this->memberSearch        = '';
        $this->reactionsPopupMsgId = null;
        $this->reactionsPopupData  = [];
        $this->alumni              = [];
        $this->coordinators        = [];
        $this->staffDirectors      = [];
        $this->staffCoords         = [];
        $this->openToolbarMsgId    = null;
        $this->confirmDeleteId     = null;

        // Load messages FIRST so the chat pane paints as fast as possible —
        // everything below this is bookkeeping the user never visually
        // waits on in a meaningful way, but ordering it after the message
        // fetch keeps the "core" render-blocking query first.
        $this->loadMessages();

        $maxId = (int) (DB::table('chat_messages')
            ->where('room_id', $id)
            ->whereNull('deleted_at')
            ->max('id') ?? 0);
        $this->lastNotifiedMessageIds[$id] = $maxId;
        Cache::put($this->lastNotifiedCacheKey($id), $maxId, now()->addDays(30));
        $this->markRoomAsRead($id);

        $this->refreshOnlineCount();

        if ($isStaff) {
            $this->loadStaffMembers();
        } else {
            $this->loadAlumni();
            $this->loadCoordinators();
        }

        $this->loadTypingIndicators();

        // Update just THIS room's active/unread flags locally instead of
        // re-querying the entire room list from scratch on every click.
        $this->rooms = collect($this->rooms)->map(function ($r) use ($id) {
            $r['is_active']  = ($r['id'] === $id);
            if ($r['id'] === $id) $r['has_unread'] = false;
            return $r;
        })->toArray();

        $this->dispatch('chat-scroll-bottom-force');
        $this->dispatch('chat-open-mobile');
    }

    public function backToList(): void
    {
        $this->dispatch('chat-close-mobile');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Presence — 1 min = offline.
    //
    // NOTE: This ping keeps the coordinator "online" while THIS component
    // is mounted/polling (i.e. while the Chat page is open). To make
    // "logged in = online" true app-wide — even on pages that never touch
    // this component — add a small heartbeat in your main authenticated
    // layout that posts to a `presence.ping` route every ~30s. That keeps
    // `last_seen_at` fresh no matter which page the coordinator is on,
    // while this same 1-minute `onlineMinutes` rule still governs when
    // they flip to offline. See the note left in the class docblock area
    // above `mount()` for the exact route/controller/script snippet.
    // ─────────────────────────────────────────────────────────────────────
    public function pingPresence(): void
    {
        try {
            DB::table('organizer')->where('id', $this->coordinatorId)->update(['last_seen_at' => now()]);
        } catch (\Throwable) {}
    }

    public function refreshOnlineCount(): void
    {
        if (! $this->room) return;
        try {
            if ($this->isStaffRoom) {
                $this->onlineCount = DB::table('director')->whereNull('deleted_at')
                    ->where('last_seen_at', '>=', now()->subMinutes($this->onlineMinutes))->count()
                    + DB::table('organizer')->where('status', 'ACTIVE')->whereNull('deleted_at')
                        ->where('last_seen_at', '>=', now()->subMinutes($this->onlineMinutes))->count();
                $this->totalCount  = DB::table('director')->whereNull('deleted_at')->count()
                    + DB::table('organizer')->where('status', 'ACTIVE')->whereNull('deleted_at')->count();

            } elseif ($this->isCollegeRoom) {
                if (! empty($this->deptCourseCodes)) {
                    $base = DB::table('alumni')->whereIn('course_code', $this->deptCourseCodes)->whereNull('deleted_at');
                    $alumniTotal   = (clone $base)->count();
                    $alumniOnline  = (clone $base)->where('last_seen_at', '>=', now()->subMinutes($this->onlineMinutes))->count();

                    $coordBase     = DB::table('organizer')->where('status', 'ACTIVE')->whereNull('deleted_at')->where('department', $this->department);
                    $coordTotal    = (clone $coordBase)->count();
                    $coordOnline   = (clone $coordBase)->where('last_seen_at', '>=', now()->subMinutes($this->onlineMinutes))->count();

                    $this->totalCount  = $alumniTotal + $coordTotal;
                    $this->onlineCount = $alumniOnline + $coordOnline;
                } else {
                    $this->totalCount = $this->onlineCount = 0;
                }

            } elseif ($this->isCourseRoom) {
                $base = DB::table('alumni')->where('course_code', $this->room['course_code'])->whereNull('deleted_at');
                $alumniTotal  = (clone $base)->count();
                $alumniOnline = (clone $base)->where('last_seen_at', '>=', now()->subMinutes($this->onlineMinutes))->count();

                $coordBase    = DB::table('organizer')->where('status', 'ACTIVE')->whereNull('deleted_at')->where('department', $this->department);
                $coordTotal   = (clone $coordBase)->count();
                $coordOnline  = (clone $coordBase)->where('last_seen_at', '>=', now()->subMinutes($this->onlineMinutes))->count();

                $this->totalCount  = $alumniTotal + $coordTotal;
                $this->onlineCount = $alumniOnline + $coordOnline;

            } else {
                $base = DB::table('alumni')
                    ->where('course_code', $this->room['course_code'])
                    ->where('batch', $this->room['batch'])
                    ->whereNull('deleted_at');
                $alumniTotal  = (clone $base)->count();
                $alumniOnline = (clone $base)->where('last_seen_at', '>=', now()->subMinutes($this->onlineMinutes))->count();

                $coordBase    = DB::table('organizer')->where('status', 'ACTIVE')->whereNull('deleted_at')->where('department', $this->department);
                $coordTotal   = (clone $coordBase)->count();
                $coordOnline  = (clone $coordBase)->where('last_seen_at', '>=', now()->subMinutes($this->onlineMinutes))->count();

                $this->totalCount  = $alumniTotal + $coordTotal;
                $this->onlineCount = $alumniOnline + $coordOnline;
            }
        } catch (\Throwable) {
            $this->totalCount = $this->onlineCount = 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Typing
    // ─────────────────────────────────────────────────────────────────────
    public function pingTyping(): void
    {
        if (trim($this->body) === '') { $this->stopTyping(); return; }
        try {
            DB::table('chat_typing')->updateOrInsert(
                ['room_id' => $this->roomId, 'sender_type' => 'coordinator', 'sender_id' => $this->coordinatorId],
                ['typed_at' => now(), 'updated_at' => now()]
            );
        } catch (\Throwable) {}
    }

    public function stopTyping(): void
    {
        try {
            DB::table('chat_typing')
                ->where('room_id', $this->roomId)
                ->where('sender_type', 'coordinator')
                ->where('sender_id', $this->coordinatorId)
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
                    $q->where('sender_type', '!=', 'coordinator')
                      ->orWhere('sender_id', '!=', $this->coordinatorId);
                })
                ->get(['sender_type', 'sender_id']);

            $names = [];
            foreach ($rows as $row) {
                if ($row->sender_type === 'alumni') {
                    $name = DB::table('alumni')->where('id', $row->sender_id)->value('first_name');
                    if ($name) $names[] = $name;
                } elseif ($row->sender_type === 'director') {
                    $name = DB::table('director')->where('id', $row->sender_id)->value('first_name');
                    if ($name) $names[] = $name . ' (Director)';
                } else {
                    $name = DB::table('organizer')->where('id', $row->sender_id)->value('first_name');
                    if ($name) $names[] = $name . ' (Coordinator)';
                }
            }
            $this->typingUsers = $names;
        } catch (\Throwable) { $this->typingUsers = []; }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Messages – Load
    // ─────────────────────────────────────────────────────────────────────
    public function loadMessages(): void
    {
        if (! $this->roomId) return;

        $rows = DB::table('chat_messages as m')
            ->where('m.room_id', $this->roomId)
            ->whereNull('m.deleted_at')
            ->orderBy('m.created_at')
            ->get(['m.id','m.sender_type','m.sender_id','m.body','m.reply_to_id','m.edited_at','m.created_at'])
            ->toArray();

        $aIds = collect($rows)->where('sender_type', 'alumni')->pluck('sender_id')->unique();
        $oIds = collect($rows)->where('sender_type', 'organizer')->pluck('sender_id')->unique();
        $dIds = collect($rows)->where('sender_type', 'director')->pluck('sender_id')->unique();

        $aMap = DB::table('alumni')->whereIn('id', $aIds)->get(['id','first_name','last_name','profile_photo','course_code','batch'])->keyBy(fn($a)=>(int)$a->id);
        $oMap = DB::table('organizer')->whereIn('id', $oIds)->get(['id','first_name','last_name','profile_photo'])->keyBy(fn($o)=>(int)$o->id);
        $dMap = DB::table('director')->whereIn('id', $dIds)->get(['id','first_name','last_name','profile_photo'])->keyBy(fn($d)=>(int)$d->id);

        $msgIds  = collect($rows)->pluck('id');
        $rxns    = DB::table('chat_reactions')->whereIn('message_id', $msgIds)->get()->groupBy('message_id');
        $pins    = DB::table('chat_pins')->whereIn('message_id', $msgIds)
            ->get(['message_id','pinned_by_type','pinned_by_id'])->keyBy('message_id');
        $rplyIds = collect($rows)->whereNotNull('reply_to_id')->pluck('reply_to_id')->unique();
        $rplyMap = DB::table('chat_messages')->whereIn('id', $rplyIds)->whereNull('deleted_at')
            ->get(['id','sender_type','sender_id','body'])->keyBy(fn($m)=>(int)$m->id);

        $self = $this;

        $this->messages = collect($rows)->map(function ($m) use ($aMap,$oMap,$dMap,$rxns,$pins,$rplyMap,$self) {
            $isDir   = $m->sender_type === 'director';
            $isCoord = $m->sender_type === 'organizer';
            $sid     = (int) $m->sender_id;
            $s       = $isDir ? $dMap->get($sid) : ($isCoord ? $oMap->get($sid) : $aMap->get($sid));

            $sName = (! $s && $isCoord && $sid === $self->coordinatorId)
                ? $self->coordinatorName
                : ($s ? trim(($s->first_name ?? '') . ' ' . ($s->last_name ?? '')) : 'Unknown');

            $photoUrl = $s ? $self->resolvePhotoUrl($s->profile_photo ?? null) : null;
            if ($isCoord && $sid === $self->coordinatorId && ! $photoUrl && $self->coordinatorPhoto) {
                $photoUrl = $self->coordinatorPhoto;
            }

            $msgRxns = $rxns->get($m->id, collect());
            $rxnGrps = $msgRxns->groupBy('reaction')->map(fn($g)=>$g->count())->toArray();
            $myRxn   = $msgRxns->first(fn($r)=>$r->reactor_type==='organizer'&&(int)$r->reactor_id===$self->coordinatorId);

            $reply = null;
            if ($m->reply_to_id && $rplyMap->has((int)$m->reply_to_id)) {
                $r  = $rplyMap->get((int)$m->reply_to_id);
                $rs = $r->sender_type === 'director' ? $dMap->get((int)$r->sender_id)
                    : ($r->sender_type === 'organizer' ? $oMap->get((int)$r->sender_id) : $aMap->get((int)$r->sender_id));
                $reply = ['id'=>$r->id,'body'=>$r->body,'name'=>$rs?trim(($rs->first_name??'').' '.($rs->last_name??'')):'Unknown'];
            }

            $isMe         = $isCoord && $sid === $self->coordinatorId;
            $isOtherCoord = $isCoord && ! $isMe;

            return [
                'id'             => $m->id,
                'sender_type'    => $m->sender_type,
                'sender_id'      => $m->sender_id,
                'sender_name'    => $sName,
                'sender_photo'   => $photoUrl,
                'sender_course'  => (! $isDir && ! $isCoord && $s) ? ($s->course_code ?? '') : '',
                'sender_batch'   => (! $isDir && ! $isCoord && $s) ? (string)($s->batch ?? '') : '',
                'body'           => $m->body,
                'post_preview'   => $self->resolvePostPreview($m->body),
                'edited'         => ! is_null($m->edited_at),
                'is_mine'        => $isMe,
                'is_director'    => $isDir,
                'is_coordinator' => $isCoord,
                'is_other_coord' => $isOtherCoord,
                'is_pinned'      => $pins->has($m->id),
                'is_pinned_by_me'=> $pins->has($m->id) && $pins->get($m->id)->pinned_by_type === 'organizer' && (int) $pins->get($m->id)->pinned_by_id === $self->coordinatorId,
                'reactions'      => $rxnGrps,
                'my_reaction'    => $myRxn ? $myRxn->reaction : null,
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
    // SMOOTHNESS FIX: this used to call loadRooms() (a full sidebar rebuild
    // touching 4+ tables) synchronously on every single send, which is why
    // sending felt like it paused/lagged for a moment. That full reload
    // isn't needed for the sender to see their own message appear — only
    // loadMessages() (this room only) is. The sidebar's "latest message"
    // preview for this room updates locally instead, and the next poll
    // tick (≤2.5s later) reconciles it with the DB for everyone else.
    // ─────────────────────────────────────────────────────────────────────
    public function sendMessage(): void
    {
        $body = trim($this->body);
        if ($body === '' || ! $this->roomId) return;

        $body = self::filterProfanity($body);

        $msgId = DB::table('chat_messages')->insertGetId([
            'room_id'     => $this->roomId,
            'sender_type' => 'organizer',
            'sender_id'   => $this->coordinatorId,
            'body'        => $body,
            'reply_to_id' => $this->replyTo['id'] ?? null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        if (preg_match_all('/@(everyone|\w+(?:\s\w+)?)/iu', $body, $matches)) {
            foreach (array_unique($matches[1]) as $mention) {
                if (strtolower($mention) === 'everyone') {
                    DB::table('chat_mentions')->insert(['message_id'=>$msgId,'mention_type'=>'everyone','mentioned_id'=>null,'created_at'=>now(),'updated_at'=>now()]);
                    continue;
                }

                if (! $this->isStaffRoom && $this->room) {
                    if ($this->isCollegeRoom && ! empty($this->deptCourseCodes)) {
                        $foundAlumni = DB::table('alumni')
                            ->whereIn('course_code', $this->deptCourseCodes)
                            ->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$mention}%")
                            ->value('id');
                    } else {
                        $alumniQ = DB::table('alumni')
                            ->where('course_code', $this->room['course_code'])
                            ->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$mention}%");
                        if (! $this->isCourseRoom) $alumniQ->where('batch', $this->room['batch']);
                        $foundAlumni = $alumniQ->value('id');
                    }
                    if ($foundAlumni) {
                        DB::table('chat_mentions')->insert(['message_id'=>$msgId,'mention_type'=>'alumni','mentioned_id'=>$foundAlumni,'created_at'=>now(),'updated_at'=>now()]);
                    }
                }

                $foundCoord = DB::table('organizer')
                    ->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$mention}%")->value('id');
                if ($foundCoord) DB::table('chat_mentions')->insert(['message_id'=>$msgId,'mention_type'=>'organizer','mentioned_id'=>$foundCoord,'created_at'=>now(),'updated_at'=>now()]);

                $foundDir = DB::table('director')->whereNull('deleted_at')
                    ->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$mention}%")->value('id');
                if ($foundDir) DB::table('chat_mentions')->insert(['message_id'=>$msgId,'mention_type'=>'director','mentioned_id'=>$foundDir,'created_at'=>now(),'updated_at'=>now()]);
            }
        }

        $this->lastNotifiedMessageIds[$this->roomId] = (int) $msgId;
        Cache::put($this->lastNotifiedCacheKey($this->roomId), (int) $msgId, now()->addDays(30));
        $this->markRoomAsRead($this->roomId);
        $this->notifyAlumniInRoom($body);

        $this->body = ''; $this->replyTo = null; $this->showMentions = false;
        $this->openToolbarMsgId = null;
        $this->stopTyping();

        // Reload only this room's messages — instant feedback for the
        // sender without waiting on a full sidebar rebuild.
        $this->loadMessages();

        // Update this room's own preview/order locally so the sidebar
        // still looks fresh immediately; the next poll tick reconciles
        // it fully (unread flags for other rooms, live counts, etc).
        $nowTs = now()->timestamp;
        $this->rooms = collect($this->rooms)->map(function ($r) use ($body, $nowTs) {
            if ($r['id'] === $this->roomId) {
                $r['latest_body']   = $body;
                $r['latest_sender'] = $this->coordinatorFirstName ?: $this->coordinatorName;
                $r['latest_time']   = now()->setTimezone('Asia/Manila')->format('h:i A');
                $r['latest_ts']     = $nowTs;
                $r['has_unread']    = false;
            }
            return $r;
        })->toArray();

        $this->dispatch('chat-scroll-bottom-force');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Toolbar toggle (single open toolbar, mobile-tap friendly)
    // ─────────────────────────────────────────────────────────────────────
    public function toggleToolbar(int $msgId): void
    {
        $this->openToolbarMsgId = ($this->openToolbarMsgId === $msgId) ? null : $msgId;
        $this->reactionsPopupMsgId = null;
        $this->reactionsPopupData  = [];
    }

    public function closeToolbar(): void
    {
        $this->openToolbarMsgId    = null;
        $this->reactionsPopupMsgId = null;
        $this->reactionsPopupData  = [];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Edit / Unsend
    // ─────────────────────────────────────────────────────────────────────
    public function startEdit(int $id): void
    {
        $msg = collect($this->messages)->firstWhere('id', $id);
        if (! $msg || ! $msg['is_mine']) return;
        $this->editingId = $id; $this->editBody = $msg['body'];
        $this->openToolbarMsgId = null;
    }

    public function saveEdit(): void
    {
        if (! $this->editingId || trim($this->editBody) === '') return;
        $body = self::filterProfanity(trim($this->editBody));
        DB::table('chat_messages')->where('id', $this->editingId)->where('sender_type','organizer')->where('sender_id',$this->coordinatorId)
            ->update(['body'=>$body,'edited_at'=>now(),'updated_at'=>now()]);
        $this->editingId = null; $this->editBody = '';
        $this->loadMessages();
    }

    public function cancelEdit(): void { $this->editingId = null; $this->editBody = ''; }

    // ── Delete confirmation modal flow ──────────────────────────────────────
    public function askDeleteConfirmation(int $id): void
    {
        $msg = collect($this->messages)->firstWhere('id', $id);
        if (! $msg || ! $msg['is_mine']) return;
        $this->confirmDeleteId  = $id;
        $this->openToolbarMsgId = null;
    }

    public function cancelDelete(): void
    {
        $this->confirmDeleteId = null;
    }

    public function unsend(int $id): void
    {
        DB::table('chat_messages')->where('id',$id)->where('sender_type','organizer')->where('sender_id',$this->coordinatorId)
            ->update(['deleted_at'=>now()]);
        DB::table('chat_pins')->where('message_id',$id)->delete();
        $this->openToolbarMsgId = null;
        $this->confirmDeleteId  = null;
        if ($this->editingId === $id) { $this->editingId = null; $this->editBody = ''; }
        $this->loadMessages();
        if ($this->showPins) $this->loadPins();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reactions
    // ─────────────────────────────────────────────────────────────────────
    public function react(int $msgId, string $reaction): void
    {
        if (! in_array($reaction, ['heart','purple','like','dislike'], true)) return;
        $existing = DB::table('chat_reactions')->where('message_id',$msgId)->where('reactor_type','organizer')->where('reactor_id',$this->coordinatorId)->first();
        if ($existing) {
            $existing->reaction === $reaction
                ? DB::table('chat_reactions')->where('id',$existing->id)->delete()
                : DB::table('chat_reactions')->where('id',$existing->id)->update(['reaction'=>$reaction,'updated_at'=>now()]);
        } else {
            DB::table('chat_reactions')->insert(['message_id'=>$msgId,'reactor_type'=>'organizer','reactor_id'=>$this->coordinatorId,'reaction'=>$reaction,'created_at'=>now(),'updated_at'=>now()]);
        }
        $this->openToolbarMsgId = null;
        $this->loadMessages();
        if ($this->reactionsPopupMsgId === $msgId) $this->openReactionsPopup($msgId);
    }

    public function openReactionsPopup(int $msgId): void
    {
        if ($this->reactionsPopupMsgId === $msgId) { $this->reactionsPopupMsgId = null; $this->reactionsPopupData = []; return; }
        $this->reactionsPopupMsgId = $msgId;
        $rows = DB::table('chat_reactions')->where('message_id',$msgId)->get(['reactor_type','reactor_id','reaction']);
        $data = [];
        foreach ($rows as $r) {
            if ($r->reactor_type === 'organizer') {
                $p = DB::table('organizer')->where('id',$r->reactor_id)->first(['first_name','last_name','profile_photo']);
                $name = $p ? trim(($p->first_name??'').' '.($p->last_name??'')) : 'Unknown';
                $photo = $p ? $this->resolvePhotoUrl($p->profile_photo??null) : null;
                $type = 'coordinator';
            } elseif ($r->reactor_type === 'director') {
                $p = DB::table('director')->where('id',$r->reactor_id)->first(['first_name','last_name','profile_photo']);
                $name = $p ? trim(($p->first_name??'').' '.($p->last_name??'')) : 'Unknown';
                $photo = $p ? $this->resolvePhotoUrl($p->profile_photo??null) : null;
                $type = 'director';
            } else {
                $p = DB::table('alumni')->where('id',$r->reactor_id)->first(['first_name','last_name','profile_photo']);
                $name = $p ? trim(($p->first_name??'').' '.($p->last_name??'')) : 'Unknown';
                $photo = $p ? $this->resolvePhotoUrl($p->profile_photo??null) : null;
                $type = 'alumni';
            }
            $data[] = ['name'=>$name,'photo'=>$photo,'reaction'=>$r->reaction,'type'=>$type,'is_me'=>$r->reactor_type==='organizer'&&(int)$r->reactor_id===$this->coordinatorId];
        }
        $this->reactionsPopupData = collect($data)->groupBy('reaction')->toArray();
    }

    public function closeReactionsPopup(): void { $this->reactionsPopupMsgId = null; $this->reactionsPopupData = []; }

    // ─────────────────────────────────────────────────────────────────────
    // Pin / Reply
    // ─────────────────────────────────────────────────────────────────────
    public function togglePin(int $msgId): void
    {
        $existing = DB::table('chat_pins')->where('message_id', $msgId)->first();

        if ($existing) {
            $isMine = $existing->pinned_by_type === 'organizer'
                && (int) $existing->pinned_by_id === $this->coordinatorId;

            if (! $isMine) {
                $this->openToolbarMsgId = null;
                return;
            }

            DB::table('chat_pins')->where('message_id', $msgId)->delete();
        } else {
            DB::table('chat_pins')->insert([
                'room_id'        => $this->roomId,
                'message_id'     => $msgId,
                'pinned_by_type' => 'organizer',
                'pinned_by_id'   => $this->coordinatorId,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        $this->openToolbarMsgId = null;
        $this->loadMessages();
        if ($this->showPins) $this->loadPins();
    }

    public function setReply(int $id): void
    {
        $msg = collect($this->messages)->firstWhere('id',$id);
        if (! $msg) return;
        $this->replyTo = ['id'=>$msg['id'],'body'=>$msg['body'],'name'=>$msg['sender_name']];
        $this->openToolbarMsgId = null;
        $this->dispatch('focus-input');
    }

    public function clearReply(): void { $this->replyTo = null; }

    // ─────────────────────────────────────────────────────────────────────
    // Side panels
    // ─────────────────────────────────────────────────────────────────────
    public function toggleMembers(): void
    {
        $this->showMembers  = ! $this->showMembers;
        $this->showPins     = false;
        $this->memberSearch = '';
        if ($this->showMembers) {
            if ($this->isStaffRoom) $this->loadStaffMembers();
            else { $this->loadAlumni(); $this->loadCoordinators(); }
        }
    }

    public function togglePins(): void
    {
        $this->showPins    = ! $this->showPins;
        $this->showMembers = false;
        if ($this->showPins) $this->loadPins();
    }

    public function closeSidePanel(): void
    {
        $this->showMembers = false;
        $this->showPins    = false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Alumni members
    // ─────────────────────────────────────────────────────────────────────
    public function loadAlumni(): void
    {
        if (! $this->room || $this->isStaffRoom) return;
        $q = trim($this->memberSearch);

        if ($this->isCollegeRoom) {
            if (empty($this->deptCourseCodes)) { $this->alumni = []; return; }
            $query = DB::table('alumni')->whereIn('course_code', $this->deptCourseCodes)->whereNull('deleted_at');
        } elseif ($this->isCourseRoom) {
            $query = DB::table('alumni')->where('course_code', $this->room['course_code'])->whereNull('deleted_at');
        } else {
            $query = DB::table('alumni')
                ->where('course_code', $this->room['course_code'])
                ->where('batch', $this->room['batch'])
                ->whereNull('deleted_at');
        }

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name',  'like', "%{$q}%")
                    ->orWhereRaw("CONCAT(first_name,' ',last_name) LIKE ?", ["%{$q}%"]);
            });
        }

        $self = $this;
        $this->alumni = $query
            ->orderBy('course_code')->orderBy('batch')->orderBy('first_name')
            ->get(['id','first_name','last_name','profile_photo','last_seen_at','batch','course_code'])
            ->map(fn ($a) => [
                'id'          => $a->id,
                'name'        => trim($a->first_name . ' ' . $a->last_name),
                'photo'       => $self->resolvePhotoUrl($a->profile_photo ?? null),
                'batch'       => $a->batch,
                'course_code' => $a->course_code,
                'is_online'   => isset($a->last_seen_at) && Carbon::parse($a->last_seen_at)->gte(now()->subMinutes($self->onlineMinutes)),
            ])->toArray();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Staff room members
    // ─────────────────────────────────────────────────────────────────────
    public function loadStaffMembers(): void
    {
        $q = trim($this->memberSearch);
        $self = $this;

        $dirQuery = DB::table('director')->whereNull('deleted_at');
        if ($q !== '') $dirQuery->where(function ($sub) use ($q) { $sub->where('first_name','like',"%{$q}%")->orWhere('last_name','like',"%{$q}%")->orWhereRaw("CONCAT(first_name,' ',last_name) LIKE ?", ["%{$q}%"]); });
        $this->staffDirectors = $dirQuery->orderBy('first_name')->get(['id','first_name','last_name','profile_photo','last_seen_at'])
            ->map(fn($d)=>['id'=>$d->id,'name'=>trim($d->first_name.' '.$d->last_name),'photo'=>$self->resolvePhotoUrl($d->profile_photo??null),'is_online'=>isset($d->last_seen_at)&&Carbon::parse($d->last_seen_at)->gte(now()->subMinutes($self->onlineMinutes))])->toArray();

        $coordQuery = DB::table('organizer')->where('status','ACTIVE')->whereNull('deleted_at');
        if ($q !== '') $coordQuery->where(function ($sub) use ($q) { $sub->where('first_name','like',"%{$q}%")->orWhere('last_name','like',"%{$q}%")->orWhereRaw("CONCAT(first_name,' ',last_name) LIKE ?", ["%{$q}%"]); });
        $this->staffCoords = $coordQuery->orderBy('first_name')->get(['id','first_name','last_name','profile_photo','last_seen_at'])
            ->map(fn($o)=>['id'=>$o->id,'name'=>trim($o->first_name.' '.$o->last_name),'photo'=>$self->resolvePhotoUrl($o->profile_photo??null),'is_me'=>(int)$o->id===$self->coordinatorId,'is_online'=>isset($o->last_seen_at)&&Carbon::parse($o->last_seen_at)->gte(now()->subMinutes($self->onlineMinutes))])->toArray();
    }

    public function loadCoordinators(): void
    {
        $self = $this;
        $this->coordinators = DB::table('organizer')->where('department',$this->department)->where('status','ACTIVE')->whereNull('deleted_at')->orderBy('first_name')
            ->get(['id','first_name','last_name','profile_photo','last_seen_at'])
            ->map(fn($o)=>['id'=>$o->id,'name'=>trim($o->first_name.' '.$o->last_name),'photo'=>$self->resolvePhotoUrl($o->profile_photo??null),'is_me'=>(int)$o->id===$self->coordinatorId,'is_online'=>isset($o->last_seen_at)&&Carbon::parse($o->last_seen_at)->gte(now()->subMinutes($self->onlineMinutes))])->toArray();
    }

    public function updatedMemberSearch(): void
    {
        if ($this->isStaffRoom) $this->loadStaffMembers();
        else $this->loadAlumni();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pinned messages
    // ─────────────────────────────────────────────────────────────────────
    public function loadPins(): void
    {
        $rows = DB::table('chat_pins as p')
            ->join('chat_messages as m','m.id','=','p.message_id')
            ->where('p.room_id',$this->roomId)->whereNull('m.deleted_at')
            ->orderByDesc('p.created_at')
            ->get(['m.id','m.sender_type','m.sender_id','m.body','p.created_at as pinned_at','p.pinned_by_type','p.pinned_by_id'])->toArray();

        $aIds = collect($rows)->where('sender_type','alumni')->pluck('sender_id')->unique();
        $oIds = collect($rows)->where('sender_type','organizer')->pluck('sender_id')->unique();
        $dIds = collect($rows)->where('sender_type','director')->pluck('sender_id')->unique();
        $aMap = DB::table('alumni')->whereIn('id',$aIds)->get(['id','first_name','last_name'])->keyBy(fn($a)=>(int)$a->id);
        $oMap = DB::table('organizer')->whereIn('id',$oIds)->get(['id','first_name','last_name'])->keyBy(fn($o)=>(int)$o->id);
        $dMap = DB::table('director')->whereIn('id',$dIds)->get(['id','first_name','last_name'])->keyBy(fn($d)=>(int)$d->id);

        $self = $this;

        $this->pinnedMessages = collect($rows)->map(function ($p) use ($aMap,$oMap,$dMap,$self) {
            $s = $p->sender_type==='director'?$dMap->get((int)$p->sender_id):($p->sender_type==='organizer'?$oMap->get((int)$p->sender_id):$aMap->get((int)$p->sender_id));
            return [
                'id'           => $p->id,
                'body'         => $self->resolvePreviewText($p->body),
                'from'         => $s?trim($s->first_name.' '.$s->last_name):'Unknown',
                'pinned_at'    => Carbon::parse($p->pinned_at)->setTimezone('Asia/Manila')->format('M d, Y h:i A'),
                'pinned_by_me' => $p->pinned_by_type === 'organizer' && (int) $p->pinned_by_id === $self->coordinatorId,
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
            $suggestions = [['id'=>0,'name'=>'everyone','type'=>'everyone']];

            if (! $this->isStaffRoom && $this->room) {
                if ($this->isCollegeRoom && ! empty($this->deptCourseCodes)) {
                    $alumniQ = DB::table('alumni')
                        ->whereIn('course_code', $this->deptCourseCodes)
                        ->whereNull('deleted_at')
                        ->where(fn($sub)=>$sub->where('first_name','like',"%{$q}%")->orWhere('last_name','like',"%{$q}%"));
                } else {
                    $alumniQ = DB::table('alumni')
                        ->where('course_code', $this->room['course_code'])
                        ->whereNull('deleted_at')
                        ->where(fn($sub)=>$sub->where('first_name','like',"%{$q}%")->orWhere('last_name','like',"%{$q}%"));
                    if (! $this->isCourseRoom) $alumniQ->where('batch', $this->room['batch']);
                }
                $alumni = $alumniQ->limit(5)->get(['id','first_name','last_name'])
                    ->map(fn($a)=>['id'=>$a->id,'name'=>trim($a->first_name.' '.$a->last_name),'type'=>'alumni'])->toArray();
                $suggestions = array_merge($suggestions, $alumni);
            }

            $dirs = DB::table('director')->whereNull('deleted_at')
                ->where(fn($sub)=>$sub->where('first_name','like',"%{$q}%")->orWhere('last_name','like',"%{$q}%"))
                ->limit(3)->get(['id','first_name','last_name'])
                ->map(fn($d)=>['id'=>$d->id,'name'=>trim($d->first_name.' '.$d->last_name),'type'=>'director'])->toArray();

            $coords = DB::table('organizer')->where('status','ACTIVE')->whereNull('deleted_at')
                ->where(fn($sub)=>$sub->where('first_name','like',"%{$q}%")->orWhere('last_name','like',"%{$q}%"))
                ->limit(3)->get(['id','first_name','last_name'])
                ->map(fn($o)=>['id'=>$o->id,'name'=>trim($o->first_name.' '.$o->last_name),'type'=>'coordinator'])->toArray();

            $this->mentionSuggestions = array_merge($suggestions, $dirs, $coords);
            $this->showMentions       = true;
        } else {
            $this->showMentions = false; $this->mentionSuggestions = [];
        }
    }

    public function selectMention(string $name): void
    {
        $this->body = preg_replace('/@\w*$/', '@' . $name . ' ', $this->body);
        $this->showMentions = false; $this->mentionSuggestions = [];
        $this->dispatch('focus-input');
    }
}; ?>

{{-- ══════════════════════════════════════════════════════════════════════
     TEMPLATE — Messenger-style responsive UI (matches alumni messenger)
     - height: calc(100vh - 180px) to match alumni messenger.blade.php
     - Mobile: sidebar/chat pane toggle via mobileChatOpen, back button
     - All action buttons (react/reply/pin/edit/delete) work via a single
       toggled toolbar (mobile tap-friendly) instead of hover-only x-data
     - Delete now uses a confirm modal instead of inline yes/no
     - Batch labels always render "Batch 2026 · BSIT" (never flipped)
     - Room search now shows a thin animated filtering progress bar,
       matching the yearbook page, while wire:model.live.debounce is
       resolving on the server
     - FIX: Pin/unpin tooltip is now ALWAYS clearly visible (solid dark
       background, forced display, no longer relies on the generic
       .org-tooltip opacity/hover rules that could leave it unreadable
       or hidden on some viewports)
     - FIX: Presence — this component pings presence on every poll tick
       while the chat page is open, keeping the coordinator "online"
       continuously with the 1-minute timeout.
     - FIX: Raw internal room markers (e.g. "CLG_xxxxxxxxxxxx") can NEVER
       be printed anywhere — header, room list, tooltip, or compose
       placeholder all route through displayCourseLabel()/roomDisplayName()
       in the class above, which detect the marker format and always
       substitute the human-readable college label instead.
     - PERF: poll interval relaxed to 2500ms and now uses wire:poll.visible
       so it fully pauses while the tab is unfocused. Selecting a room and
       sending a message no longer force a full sidebar rebuild on every
       click/send — only the affected room's local state updates instantly,
       and the next poll tick reconciles everything else. This removes the
       lag/delay that used to happen on click.
══════════════════════════════════════════════════════════════════════════ --}}
<div
    x-data="{ mobileChatOpen: false }"
    @chat-open-mobile.window="mobileChatOpen = true"
    @chat-close-mobile.window="mobileChatOpen = false"
    class="flex rounded-2xl border border-[#ddd3e8] bg-white shadow-sm overflow-hidden"
    style="height: calc(100vh - 180px); max-height: calc(100vh - 180px); overflow: hidden;"
    @if(! $confirmDeleteId) wire:poll.2500ms.visible="unifiedPoll" @endif>

    <style>
        #org-room-list button,
        #org-room-list .org-pin-btn,
        .org-bubble,
        .org-panel,
        .org-tooltip { transition: all .16s cubic-bezier(.4,0,.2,1); }

        #org-room-list > div { transition: transform .18s ease, opacity .18s ease; }

        /* ── Search highlight — same light-blue mark as Alumni Records ── */
        mark.org-hl {
            background: #BFDBFE;
            color: inherit;
            border-radius: 2px;
            padding: 0 1px;
            font-weight: 700;
        }

        .org-bubble { transform-origin: bottom; position: relative; }

        /* ── Open-message indicator — clearly marks which bubble's
             toolbar is currently active. Stronger ring + soft glow,
             plus a slight lift so it visibly separates from the rest. ── */
        .org-bubble-open {
            box-shadow:
                0 0 0 2px #6b2490,
                0 0 0 5px rgba(107,36,144,.18),
                0 6px 18px rgba(107,36,144,.28) !important;
            transform: translateY(-1px);
        }

        /* ── Loading spinner shown while toggleToolbar() round-trips ── */
        .org-bubble-spinner {
            display: none;
            position: absolute;
            top: -8px;
            right: -8px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #6b2490;
            color: #ffffff;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            box-shadow: 0 2px 8px rgba(107,36,144,.4);
            z-index: 2;
        }
        .org-bubble-loading .org-bubble-spinner { display: flex; }
        .org-bubble-loading { opacity: .85; }

        /* ── Smooth message entrance — new/rendered messages ease in
             instead of popping in abruptly ── */
        .org-msg-in { animation: orgMsgIn .22s ease-out both; }
        @keyframes orgMsgIn {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes orgPop {
            from { opacity: 0; transform: translateY(6px) scale(.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes orgPanelIn {
            from { opacity: 0; transform: translateX(12px); }
            to   { opacity: 1; transform: translateX(0); }
        }
        @keyframes orgModalIn {
            from { opacity: 0; transform: scale(.94); }
            to   { opacity: 1; transform: scale(1); }
        }
        @keyframes orgFadeUp {
            from { opacity: 0; transform: translateY(6px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        button:not(:disabled),
        [role="button"],
        .org-pin-btn,
        #org-room-list > div button,
        label[for] { cursor: pointer; }
        button:disabled { cursor: not-allowed; }

        #org-room-list { overflow-x: hidden; }
        .overflow-y-auto { scroll-behavior: smooth; }

        /* ── Instant visual feedback on room click — removes the feeling
           of "delay" while the Livewire request round-trips. The clicked
           row dims/scales immediately via :active, before the server even
           responds. ── */
        #org-room-list button:active {
            transform: scale(0.98);
            transition: transform .08s ease;
        }

        /* ── Tooltips: desktop/hover only ─────────────────────────────── */
        .org-tooltip {
            display: none;
            position: absolute;
            z-index: 999;
            background: #1a1a1a;
            color: #ffffff;
            font-weight: 700;
            font-size: 11px;
            line-height: 1.3;
            letter-spacing: .02em;
            white-space: nowrap;
            pointer-events: none;
            opacity: 0;
            transform: translateY(-2px);
            border: none;
            box-shadow: 0 4px 14px rgba(0,0,0,0.3);
        }
        @media (min-width: 640px) {
            .org-tooltip { display: block; }
        }
        .org-tooltip-wrap:hover .org-tooltip {
            opacity: 1;
            transform: translateY(0);
        }

        /* ── Pin/Unpin tooltip fix ────────────────────────────────────────
           Tooltip only appears when hovering the pin BUTTON itself, not
           whenever the wrapper (button + tooltip) becomes visible on room
           row hover. Kept the solid/high-contrast styling from before —
           just gated behind :hover again instead of forced permanently
           visible, which was showing "Pin to top" the instant the row
           was hovered (before the button itself was even touched). ── */
        #org-room-list .org-pin-tooltip-wrap {
            z-index: 950;
        }
        #org-room-list .org-pin-tooltip-wrap .org-tooltip {
            position: absolute;
            z-index: 951;
            white-space: normal;
            max-width: 140px;
            text-align: center;
            background: #1a1a1a;
            color: #ffffff;
            padding: 6px 10px;
            border-radius: 8px;
            box-shadow: 0 6px 18px rgba(0,0,0,.35);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .02em;
            line-height: 1.3;
            pointer-events: none;
            display: block;
            opacity: 0;
            transform: translateY(-2px);
            transition: opacity .16s ease, transform .16s ease;
        }
        #org-room-list .org-pin-tooltip-wrap:hover .org-tooltip {
            opacity: 1;
            transform: translateY(0);
        }

        #org-chat-header { position: relative; z-index: 10; }
        #msg-list { overflow-x: visible; }
        .org-reaction-toolbar { z-index: 300; }
        .org-reaction-toolbar .org-tooltip { z-index: 301; }

        .org-reactions-popup { z-index: 300; }
        .org-reactions-popup-list {
            height: 230px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #c9aee0 #f5f0fa;
        }
        .org-reactions-popup-list::-webkit-scrollbar { width: 6px; }
        .org-reactions-popup-list::-webkit-scrollbar-track { background: #f5f0fa; }
        .org-reactions-popup-list::-webkit-scrollbar-thumb { background: #c9aee0; border-radius: 999px; }
        .org-reactions-popup-list::-webkit-scrollbar-thumb:hover { background: #ad8ac7; }

        /* ── Scroll-to-top / scroll-to-bottom floating nav ──────────────── */
        .org-scroll-nav {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            bottom: 18px;
            display: flex;
            gap: 10px;
            z-index: 50;
            pointer-events: none;
        }
        .org-scroll-nav .org-scroll-btn { pointer-events: auto; }
        .org-scroll-btn {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #ffffff;
            border: 2px solid #6b2490;
            color: #6b2490;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            box-shadow: 0 4px 16px rgba(107,36,144,.30);
            cursor: pointer;
            transition: background .15s ease, transform .15s ease, box-shadow .15s ease;
        }
        .org-scroll-btn:hover {
            background: #6b2490;
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(107,36,144,.38);
        }
        .org-scroll-btn:active { transform: translateY(0) scale(.94); }

        /* ── Delete confirmation modal ─────────────────────────────────── */
        .org-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(26,15,34,.45);
            backdrop-filter: blur(2px);
            z-index: 400;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .org-modal-card {
            width: 100%;
            max-width: 340px;
            background: #ffffff;
            border-radius: 1.1rem;
            box-shadow: 0 20px 50px rgba(58,27,77,.35);
            overflow: hidden;
            animation: orgModalIn .16s ease-out;
        }
        .org-modal-icon {
            width: 46px; height: 46px; border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            background: #FDECEC; color: #DC2626; flex-shrink: 0;
        }

        /* ── Search filtering spinner — bouncing dots ("...") ─────────────── */
        .org-filter-spinner {
            display: inline-flex;
            align-items: center;
            gap: 2.5px;
        }
        .org-filter-spinner span {
            width: 4px; height: 4px;
            border-radius: 50%;
            background: #6b2490;
            animation: orgDotBounce .9s ease-in-out infinite;
        }
        .org-filter-spinner span:nth-child(1) { animation-delay: 0s; }
        .org-filter-spinner span:nth-child(2) { animation-delay: .15s; }
        .org-filter-spinner span:nth-child(3) { animation-delay: .3s; }
        @keyframes orgDotBounce {
            0%, 60%, 100% { transform: translateY(0); opacity: .5; }
            30% { transform: translateY(-3px); opacity: 1; }
        }

        /* ── Smoother room-list & message list transitions ──────────────── */
        #org-room-list, #msg-list {
            transition: opacity .15s ease;
        }
        .org-room-fade {
            animation: orgFadeUp .16s ease-out both;
        }

        @media (max-width: 768px) {
            #org-sidebar { display: none; }
            #org-sidebar.org-mobile-show { display: flex; width: 100% !important; }
            #org-chatpane { display: none; }
            #org-chatpane.org-mobile-show { display: flex; width: 100% !important; }
        }
        /* ── Shared Job/Event post-preview card — mirrors
           director/director-messenger.blade.php card design (msgr-post-*)
           so shared events/jobs render as a rich card here instead of raw
           marker/plain text. ── */
        .msgr-post-card {
            width: 100%;
            max-width: 260px;
            border-radius: 1rem;
            overflow: hidden;
            background: linear-gradient(160deg, #6b2490 0%, #4a1863 100%);
            border: 1px solid rgba(107,36,144,.25);
            box-shadow: 0 4px 14px rgba(107,36,144,.22);
        }
        .msgr-post-card.is-mine { border-color: rgba(255,255,255,.28); }
        .msgr-post-card.is-unavailable { opacity: .82; }

        .msgr-post-thumb {
            position: relative;
            height: 165px;
            width: 100%;
            overflow: hidden;
            background: linear-gradient(135deg,#8e3fb8,#4a1863);
        }
        .msgr-post-thumb img {
            width: 100%; height: 100%; object-fit: cover; display: block;
            filter: saturate(1.02);
        }
        .msgr-post-card.is-unavailable .msgr-post-thumb img { filter: grayscale(.55) saturate(.7); }

        .msgr-post-thumb-placeholder {
            width: 100%; height: 100%;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #7d3aa3 0%, #4a1863 100%);
        }
        .msgr-post-thumb-placeholder i {
            font-size: 42px;
            color: rgba(255,255,255,.35);
        }

        .msgr-post-thumb-gradient {
            width: 100%; height: 100%;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #6b2490 0%, #38134f 100%);
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
            background: linear-gradient(to top, rgba(48,20,66,.82) 0%, rgba(48,20,66,0) 55%);
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
        .msgr-post-overlay-strip p .accent { color: #6b2490; }

        .msgr-post-thumb-overlay {
            position: absolute; inset: 0; z-index: 3;
            display: flex; align-items: center; justify-content: center;
            background: rgba(48,20,66,0); transition: background .18s ease;
        }
        .msgr-post-card:not(.is-unavailable):hover .msgr-post-thumb-overlay { background: rgba(48,20,66,.32); }
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

        /* ── Floating background bubbles + college/course watermark —
           same drifting lavender-circle theme as the alumni Messenger
           page, so the coordinator's chat feels like one design system
           with the alumni-facing side instead of a flat gray backdrop. ── */
        .org-bubble-bg {
            position: relative;
            background-color: #FFFFFF;
            overflow: hidden;
        }
        .org-bubble-bg::before,
        .org-bubble-bg::after,
        .org-bubble-layer {
            content: '';
            position: absolute;
            inset: -60px;
            z-index: 0;
            pointer-events: none;
        }
        .org-bubble-bg::before {
            background-image:
                radial-gradient(circle, rgba(216,180,254,0.55) 0, rgba(216,180,254,0.55) 22px, transparent 23px),
                radial-gradient(circle, rgba(107,36,144,0.3) 0, rgba(107,36,144,0.3) 17px, transparent 18px);
            background-repeat: repeat;
            background-size: 340px 340px, 300px 300px;
            background-position: 20px 40px, 90px 220px;
        }
        .org-bubble-bg::after {
            background-image:
                radial-gradient(circle, rgba(216,180,254,0.4) 0, rgba(216,180,254,0.4) 14px, transparent 15px),
                radial-gradient(circle, rgba(107,36,144,0.22) 0, rgba(107,36,144,0.22) 8px, transparent 9px);
            background-repeat: repeat;
            background-size: 260px 260px, 180px 180px;
            background-position: 180px 120px, 40px 260px;
        }
        .org-bubble-layer {
            background-image:
                radial-gradient(circle, rgba(216,180,254,0.45) 0, rgba(216,180,254,0.45) 10px, transparent 11px);
            background-repeat: repeat;
            background-size: 220px 220px;
            background-position: 250px 60px;
        }
        .org-bubble-bg > * { position: relative; z-index: 1; }

        #org-chat-body-wrap { position: relative; }
        .org-watermark {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
            user-select: none;
            padding: 0 8%;
        }
        .org-watermark span {
            font-size: clamp(48px, 11vw, 140px);
            font-weight: 900;
            color: #6b2490;
            opacity: 0.07;
            letter-spacing: .04em;
            white-space: normal;
            text-align: center;
            transform: rotate(-6deg);
            line-height: 1.15;
            max-width: 90%;
        }
        #msg-list { position: relative; z-index: 1; background: transparent; }
    </style>

    @php
        $defaultAv = asset('storage/alumni-photos/default.png');

        $watermarkText = '';
        if ($isStaffRoom) {
            $watermarkText = 'STAFF';
        } elseif ($isCollegeRoom) {
            $watermarkText = mb_strtoupper(trim($department));
        } elseif ($isCourseRoom || (! $isStaffRoom && $room)) {
            $watermarkText = strtoupper($room['course_code'] ?? '');
        }
    @endphp

    {{-- ══════════════════════════════════════════════════════════════════
         LEFT SIDEBAR
         ══════════════════════════════════════════════════════════════════ --}}
    <div id="org-sidebar"
         :class="mobileChatOpen ? '' : 'org-mobile-show'"
         class="w-full md:w-80 flex-shrink-0 flex flex-col border-r border-[#ddd3e8] bg-white">

        {{-- Sidebar header --}}
        <div class="px-4 py-3.5 border-b border-[#5c2778] flex-shrink-0" style="background:#6b2490;">
            <div class="flex items-center gap-2.5 mb-1">
                <div class="w-9 h-9 rounded-xl flex-shrink-0 overflow-hidden ring-2 ring-white/30"
                     style="background:rgba(255,255,255,.18);">
                    <img src="{{ $coordinatorPhoto ?: $defaultAv }}"
                         class="w-full h-full object-cover"
                         onerror="this.src='{{ $defaultAv }}'"
                         alt="{{ $coordinatorFirstName }}">
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-white font-semibold text-sm leading-tight truncate">{{ $coordinatorName }}</p>
                    <div class="flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                        <span class="text-xs text-white/70 font-semibold">Online · Coordinator</span>
                    </div>
                </div>
            </div>
            <p class="text-xs text-white/50 font-semibold truncate mt-0.5">
                <i class="fa-solid fa-building-columns mr-1"></i>{{ $department }}
            </p>
        </div>

        {{-- Search bar — Alpine-buffered like Alumni Records so typing
             never waits on a server round-trip per keystroke; only the
             debounced value hits the wire, so it stays smooth even while
             the 2.5s unified poll is running. --}}
        <div class="px-3 py-2.5 border-b border-[#ddd3e8] flex-shrink-0 bg-white">
            <div class="relative" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.roomSearch??''; $wire.$watch('roomSearch',v=>{ if(v!==this.q)this.q=v; }); } }">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[#aaaaaa] text-xs pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.200ms="$wire.set('roomSearch',q)"
                       placeholder="Search chats…"
                       autocomplete="off" spellcheck="false"
                       class="w-full pl-9 pr-8 py-2 text-sm rounded-xl border border-[#ddd3e8] bg-[#f9f7fc] focus:outline-none focus:border-[#6b2490] focus:ring-2 focus:ring-[#6b2490]/15 transition placeholder-[#aaaaaa]"/>
                <div wire:loading wire:target="roomSearch" class="absolute right-2.5 top-1/2 -translate-y-1/2">
                    <span class="org-filter-spinner"><span></span><span></span><span></span></span>
                </div>
                <button type="button" x-show="q !== ''" x-cloak @click="q=''; $wire.set('roomSearch','')"
                        wire:loading.remove wire:target="roomSearch"
                        class="absolute right-2.5 top-1/2 -translate-y-1/2 w-5 h-5 flex items-center justify-center rounded-full text-[#aaaaaa] hover:text-[#333333] hover:bg-[#f0eaf7] transition">
                    <i class="fa-solid fa-xmark text-xs"></i>
                </button>
            </div>
        </div>

        {{-- Chat count label --}}
        <div class="px-4 pt-2.5 pb-1 flex-shrink-0 bg-white">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest">
                    <i class="fa-solid fa-comments mr-1"></i>Chats
                </p>
                <span class="text-xs font-semibold text-[#999999] bg-[#f5f5f5] px-2 py-0.5 rounded-full border border-[#ddd3e8]">
                    {{ count($rooms) }}
                </span>
            </div>
        </div>

        {{-- Room list --}}
        <div id="org-room-list" class="flex-1 overflow-y-auto px-2 pb-3 space-y-0.5 bg-white"
             wire:loading.class="opacity-60" wire:target="roomSearch">
            @forelse($rooms as $r)
            @php
                $hasUnread  = $r['has_unread'];
                $isPinnedRm = $r['is_pinned_room'];
                $isActive   = $r['is_active'];
                $hasMsg     = ! empty($r['latest_body']);
            @endphp

            <div wire:key="org-room-{{ $r['id'] }}" class="relative org-room-fade" x-data="{ hovered: false }" @mouseenter="hovered = true" @mouseleave="hovered = false" style="isolation: isolate;">

                <button wire:click="selectRoom({{ $r['id'] }})"
                        wire:loading.class="opacity-70"
                        wire:target="selectRoom({{ $r['id'] }})"
                        class="w-full text-left rounded-xl px-3 py-3 transition-all border cursor-pointer
                               @if($isActive)      border-[#c49bdb] bg-[#f2e8f9]
                               @elseif($hasUnread) border-[#d9b8ef] bg-[#ede5f7] hover:bg-[#e4d8f2]
                               @else               border-transparent hover:border-[#ddd3e8] hover:bg-[#fafafa] @endif">

                    <div class="flex items-start gap-2.5">
                        {{-- Icon with badges --}}
                        <div class="relative flex-shrink-0 self-start mt-0.5">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-sm flex-shrink-0 {{ $isActive ? 'text-white' : 'text-[#6b2490]' }}"
                                 style="{{ $isActive ? 'background:#6b2490;' : 'background:#ede9f6;' }}">
                                <i class="fa-solid {{ $r['type']==='staff' ? 'fa-shield-halved' : ($r['type']==='college' ? 'fa-school' : ($r['type']==='course' ? 'fa-layer-group' : 'fa-users')) }}"></i>
                            </div>
                            @if($hasUnread)
                            <span class="absolute -top-1 -right-1 w-3 h-3 rounded-full bg-red-500 border-2 border-white z-20 animate-pulse"
                                  style="box-shadow: 0 0 0 2px #fff;"></span>
                            @endif
                            @if($isPinnedRm)
                            <span class="absolute -bottom-1 -right-1 w-4 h-4 rounded-full bg-amber-400 border-2 border-white flex items-center justify-center z-10" title="Pinned">
                                <i class="fa-solid fa-thumbtack text-white" style="font-size:7px; transform:rotate(45deg);"></i>
                            </span>
                            @endif
                        </div>

                        <div class="flex-1 min-w-0 pr-7">
                            <div class="flex items-center justify-between gap-1">
                                <p class="text-sm leading-tight truncate
                                          @if($isActive)      font-semibold text-[#6b2490]
                                          @elseif($hasUnread) font-bold text-[#1a1a1a]
                                          @else               font-semibold text-[#333333] @endif">
                                    @if($r['type'] === 'staff') {!! $this->highlight('Staff Chat', $roomSearch) !!}
                                    @elseif($r['type'] === 'college') {!! $this->highlight($department, $roomSearch) !!}
                                    @elseif($r['type'] === 'course') {!! $this->highlight(strtoupper($r['course_code']), $roomSearch) !!}
                                    @else {!! $this->highlight($r['name'], $roomSearch) !!}
                                    @endif
                                </p>
                                <div class="flex items-center gap-1 flex-shrink-0">
                                    @if($hasUnread && ! $isActive)
                                    <span class="w-2 h-2 rounded-full bg-red-500 flex-shrink-0 animate-pulse"></span>
                                    @endif
                                    @if($r['latest_time'])
                                    <span class="text-[11px] font-semibold whitespace-nowrap {{ $hasUnread ? 'text-red-500 font-bold' : 'text-[#999999]' }}">{{ $r['latest_time'] }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-1 flex-wrap mt-0.5 mb-0.5">
                                @if($r['type'] === 'staff')
                                    <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#f2e8f9] text-[#6b2490]"><i class="fa-solid fa-shield-halved text-[9px] mr-0.5"></i>Internal</span>
                                    <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#f2e8f9] text-[#8b35b8]">{{ $r['total_count'] }} staff</span>
                                @elseif($r['type'] === 'college')
                                    <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#f2e8f9] text-[#6b2490]"><i class="fa-solid fa-users-between-lines text-[9px] mr-0.5"></i>All Courses</span>
                                    <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#f2e8f9] text-[#8b35b8]">{{ $r['total_count'] }} members</span>
                                @elseif($r['type'] === 'course')
                                    <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#f2e8f9] text-[#6b2490]"><i class="fa-solid fa-users-between-lines text-[9px] mr-0.5"></i>All Batches</span>
                                    <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#f2e8f9] text-[#8b35b8]">{{ $r['total_count'] }} members</span>
                                @else
                                    <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#f2e8f9] text-[#6b2490]"><i class="fa-solid fa-graduation-cap text-[9px] mr-0.5"></i>Batch {{ $r['batch'] }}</span>
                                    <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#f2e8f9] text-[#8b35b8]">{{ strtoupper($r['course_code']) }}</span>
                                @endif
                            </div>

                            @if($r['latest_body'])
                            <p class="text-xs truncate leading-tight {{ $hasUnread ? 'text-[#1a1a1a] font-semibold' : 'text-[#666666]' }}">
                                @if($r['latest_sender'])<span class="font-semibold">{{ $r['latest_sender'] }}:</span> @endif
                                {{ Str::limit($r['latest_body'], 36) }}
                            </p>
                            @else
                            <p class="text-xs text-[#999999] italic mt-0.5">No messages yet</p>
                            @endif

                            @if($r['online_count'] > 0)
                            <div class="flex items-center gap-1 mt-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
                                <span class="text-xs font-semibold text-emerald-600">{{ $r['online_count'] }}/{{ $r['total_count'] }} online</span>
                            </div>
                            @else
                            <p class="text-xs text-[#999999] mt-1">{{ $r['total_count'] }} {{ $r['type']==='staff' ? 'staff members' : 'members' }}</p>
                            @endif
                        </div>
                    </div>
                </button>

                {{-- Pin/Unpin button on hover — org-pin-tooltip-wrap carries
                     the raised z-index + forced-visible style fix so this
                     tooltip always renders clearly above neighboring room
                     rows instead of being clipped or invisible --}}
                <div class="absolute top-2 right-2 z-30 org-tooltip-wrap org-pin-tooltip-wrap"
                     x-show="hovered"
                     x-transition:enter="transition ease-out duration-100"
                     x-transition:enter-start="opacity-0 scale-90"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-75"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-90"
                     style="display:none;">
                    <button wire:click.stop="togglePinRoom({{ $r['id'] }})"
                            class="org-pin-btn w-7 h-7 rounded-full flex items-center justify-center shadow-md border transition-all
                                   {{ $isPinnedRm
                                       ? 'bg-amber-400 border-amber-500 text-white hover:bg-amber-500'
                                       : 'bg-white border-[#ddd3e8] text-[#aaaaaa] hover:bg-amber-50 hover:text-amber-500 hover:border-amber-300' }}">
                        <i class="fa-solid fa-thumbtack" style="font-size:10px;"></i>
                    </button>
                    <span class="org-tooltip top-full right-0 mt-2 px-2.5 py-1.5 rounded-lg">
                        {{ $isPinnedRm ? 'Unpin' : 'Pin' }}
                    </span>
                </div>

            </div>

            @empty
            <div class="flex flex-col items-center justify-center py-16 text-[#999999] text-center px-4">
                <i class="fa-solid fa-comments-slash text-3xl text-[#ddd3e8] mb-3"></i>
                <p class="text-sm font-semibold text-[#666666]">
                    {{ $roomSearch !== '' ? 'No chats found' : 'No chats yet' }}
                </p>
                <p class="text-xs mt-1 text-[#999999] leading-snug">
                    @if($roomSearch !== '')
                        No results for "<span class="font-semibold text-[#6b2490]">{{ $roomSearch }}</span>"
                    @else
                        Chats will appear once alumni are added under <span class="font-semibold text-[#6b2490]">{{ $department }}</span>.
                    @endif
                </p>
                @if($roomSearch !== '')
                <button wire:click="$set('roomSearch','')" class="mt-3 text-xs font-semibold text-[#6b2490] hover:underline">Clear search</button>
                @endif
            </div>
            @endforelse
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════
         MAIN AREA
         ══════════════════════════════════════════════════════════════════ --}}
    @if($room)
    <div id="org-chatpane"
         :class="mobileChatOpen ? 'org-mobile-show' : ''"
         class="flex flex-col flex-1 min-w-0 w-full">

        {{-- Chat header --}}
        <div id="org-chat-header" class="flex items-center gap-3 px-3 sm:px-5 py-3.5 flex-shrink-0 border-b border-[#5c2778]" style="background:#6b2490;">
            <button @click="mobileChatOpen = false" wire:click="backToList"
                    class="md:hidden w-8 h-8 -ml-1 flex items-center justify-center rounded-full text-white hover:bg-white/15 transition-all duration-200 flex-shrink-0 cursor-pointer">
                <i class="fa-solid fa-arrow-left text-sm"></i>
            </button>
            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 bg-white/20 border border-white/30">
                <i class="fa-solid {{ $isStaffRoom ? 'fa-shield-halved' : ($isCollegeRoom ? 'fa-school' : ($isCourseRoom ? 'fa-layer-group' : 'fa-users')) }} text-white text-sm"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-white font-semibold text-sm leading-tight truncate uppercase tracking-wide">
                    {{-- FIX: was strtoupper($room['course_code']) directly — that
                         could print the raw CLG_xxxxxxxxxxxx marker if a
                         college room somehow still had isCourseRoom true.
                         displayCourseLabel() below always substitutes the
                         department label whenever the code is a marker. --}}
                    @if($isStaffRoom) Staff Chat
                    @elseif($isCollegeRoom) {{ $department }} · All Courses & Batches
                    @elseif($isCourseRoom) {{ $this->displayCourseLabel($room['course_code'] ?? '') }} · All Batches GC
                    @else Batch {{ $room['batch'] }} · {{ $this->displayCourseLabel($room['course_code'] ?? '') }}
                    @endif
                </p>
                <div class="flex items-center gap-2 flex-wrap mt-0.5">
                    @if($onlineCount > 0)
                    <div class="flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                        <span class="text-white/75 text-xs font-semibold">{{ $onlineCount }}/{{ $totalCount }} online</span>
                    </div>
                    <span class="text-white/30 text-xs hidden sm:inline">·</span>
                    @endif
                    @if($isStaffRoom)
                    <span class="text-white/60 text-xs font-semibold items-center gap-1 hidden sm:flex"><i class="fa-solid fa-lock text-[10px]"></i>Internal · Directors + Coordinators</span>
                    @elseif($isCollegeRoom)
                    <span class="text-white/60 text-xs font-semibold items-center gap-1 hidden sm:flex"><i class="fa-solid fa-school text-[10px]"></i>All Courses & Batches · {{ $totalCount }} members total</span>
                    @elseif($isCourseRoom)
                    <span class="text-white/60 text-xs font-semibold items-center gap-1 hidden sm:flex"><i class="fa-solid fa-users-between-lines text-[10px]"></i>All Batch Years · {{ $totalCount }} members total</span>
                    @else
                    <span class="text-white/60 text-xs font-semibold">{{ $totalCount }} members</span>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-1.5 flex-shrink-0">
                <button wire:click="togglePins" wire:loading.attr="disabled" wire:target="togglePins"
                        class="flex items-center gap-1 px-2 py-1.5 rounded-lg text-xs font-semibold border transition cursor-pointer disabled:opacity-60"
                        style="{{ $showPins ? 'background:rgba(255,255,255,.28);color:#fff;border-color:rgba(255,255,255,.40);' : 'background:rgba(255,255,255,.12);color:rgba(255,255,255,.75);border-color:rgba(255,255,255,.20);' }}">
                    <i class="fa-solid fa-thumbtack text-xs" wire:loading.remove wire:target="togglePins"></i>
                    <i class="fa-solid fa-spinner fa-spin text-xs" wire:loading wire:target="togglePins"></i>
                    <span class="hidden sm:inline ml-1">Pins</span>
                </button>
                <button wire:click="toggleMembers" wire:loading.attr="disabled" wire:target="toggleMembers"
                        class="flex items-center gap-1 px-2 py-1.5 rounded-lg text-xs font-semibold border transition cursor-pointer disabled:opacity-60"
                        style="{{ $showMembers ? 'background:rgba(255,255,255,.28);color:#fff;border-color:rgba(255,255,255,.40);' : 'background:rgba(255,255,255,.12);color:rgba(255,255,255,.75);border-color:rgba(255,255,255,.20);' }}">
                    <i class="fa-solid fa-user-group text-xs" wire:loading.remove wire:target="toggleMembers"></i>
                    <i class="fa-solid fa-spinner fa-spin text-xs" wire:loading wire:target="toggleMembers"></i>
                    <span class="hidden sm:inline ml-1">Members</span>
                </button>
            </div>
        </div>

        <div class="flex flex-1 min-h-0 relative">
            <div class="flex flex-col flex-1 min-w-0">

                {{-- Message list --}}
                <div id="org-chat-body-wrap" class="relative flex-1 min-h-0 flex flex-col org-bubble-bg"
                     x-data="{
                         nearBottom: true,
                         scrollDir: null,
                         dirTimer: null,
                         lastTop: 0,
                         onScroll(el) {
                             const cur = el.scrollTop;
                             this.scrollDir = cur < this.lastTop ? 'up' : (cur > this.lastTop ? 'down' : this.scrollDir);
                             this.lastTop = cur;
                             this.nearBottom = (el.scrollHeight - el.scrollTop - el.clientHeight) < 120;
                             clearTimeout(this.dirTimer);
                             this.dirTimer = setTimeout(() => { this.scrollDir = null; }, 1200);
                         }
                     }">

                    <div class="org-bubble-layer" aria-hidden="true"></div>

                    @if($watermarkText !== '')
                    <div class="org-watermark" aria-hidden="true">
                        <span>{{ $watermarkText }}</span>
                    </div>
                    @endif

                    <div id="msg-list"
                         class="flex-1 overflow-y-auto px-3 sm:px-4 py-4"
                         x-init="lastTop = $el.scrollTop; $el.scrollTop = $el.scrollHeight; $el.addEventListener('scroll', () => onScroll($el));"
                         @chat-scroll-bottom.window="if (nearBottom) { $nextTick(() => { $el.scrollTop = $el.scrollHeight; }); }"
                         @chat-scroll-bottom-force.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight; nearBottom = true; scrollDir = null; })"
                         @click="$wire.closeToolbar()">

                        @php
                            $prevDate    = null;
                            $prevSendKey = null;
                            $lastIdx     = count($messages) - 1;
                        @endphp

                        @forelse($messages as $msgIdx => $msg)
                            @php
                                $dateChanged = $msg['date'] !== $prevDate;
                                $senderKey   = $msg['sender_type'] . $msg['sender_id'];
                                $sameGroup   = ! $dateChanged && $senderKey === $prevSendKey;
                                $prevDate    = $msg['date'];
                                $prevSendKey = $senderKey;
                                $isLast      = $msgIdx === $lastIdx;
                                $toolbarOpen = $openToolbarMsgId === $msg['id'];
                                $canTogglePin = ! $msg['is_pinned'] || $msg['is_pinned_by_me'];

                                if ($msg['is_mine']) {
                                    $bubbleBg  = 'background:#6b2490;';
                                    $bubbleCls = 'text-white rounded-br-none';
                                    $avatarGrad = 'background:#6b2490;';
                                } elseif ($msg['is_director']) {
                                    $bubbleBg  = 'background:#7A3F91;';
                                    $bubbleCls = 'text-white rounded-bl-none';
                                    $avatarGrad = 'background:#4a1d78;';
                                } elseif ($msg['is_coordinator']) {
                                    $bubbleBg  = 'background:#6b2490;';
                                    $bubbleCls = 'text-white rounded-bl-none';
                                    $avatarGrad = 'background:#6b2490;';
                                } else {
                                    $bubbleBg  = '';
                                    $bubbleCls = 'bg-white border border-[#ddd3e8] text-[#333333] rounded-bl-none';
                                    $avatarGrad = 'background:#6b2490;';
                                }
                                $senderPhotoSrc = $msg['sender_photo'] ?: $defaultAv;
                            @endphp

                            @if($dateChanged)
                            <div class="flex items-center gap-3 my-4">
                                <div class="flex-1 h-px bg-[#ddd3e8]"></div>
                                <span class="text-xs font-semibold text-[#999999] tracking-widest uppercase px-2 whitespace-nowrap">{{ $msg['date_label'] }}</span>
                                <div class="flex-1 h-px bg-[#ddd3e8]"></div>
                            </div>
                            @endif

                            <div wire:key="org-msg-{{ $msg['id'] }}" class="org-msg-in flex {{ $msg['is_mine'] ? 'justify-end' : 'justify-start' }} items-end gap-2 {{ $sameGroup ? 'mt-0.5' : 'mt-3' }}">

                                @if(! $msg['is_mine'])
                                <div class="w-7 h-7 rounded-full flex-shrink-0 overflow-hidden flex items-center justify-center text-xs font-semibold text-white mb-1 self-end"
                                     style="{{ $avatarGrad }}" title="{{ $msg['sender_name'] }}">
                                    <img src="{{ $senderPhotoSrc }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="">
                                </div>
                                @endif

                                <div class="flex flex-col {{ $msg['is_mine'] ? 'items-end' : 'items-start' }} max-w-[82%] sm:max-w-[70%]">

                                    @if(! $msg['is_mine'] && ! $sameGroup)
                                    <p class="text-xs font-semibold px-1 mb-0.5 {{ $msg['is_director'] ? 'text-violet-700' : 'text-[#6b2490]' }}">
                                        {{ $msg['sender_name'] }}
                                        @if($msg['is_director'])
                                            <span class="ml-1 text-[10px] font-semibold bg-violet-100 text-violet-700 px-1.5 py-0.5 rounded"><i class="fa-solid fa-shield-halved text-[9px] mr-0.5"></i>Director</span>
                                        @elseif($msg['is_coordinator'])
                                            <span class="ml-1 text-[10px] font-semibold bg-[#f2e8f9] text-[#6b2490] px-1.5 py-0.5 rounded">Coordinator</span>
                                        @elseif($isCollegeRoom)
                                            @if($msg['sender_course'])
                                                <span class="ml-1 text-[10px] font-semibold bg-[#f2e8f9] text-[#6b2490] px-1.5 py-0.5 rounded">{{ strtoupper($msg['sender_course']) }}</span>
                                            @endif
                                            @if($msg['sender_batch'])
                                                <span class="ml-1 text-[10px] font-semibold bg-[#ede0f5] text-[#5c2d7a] px-1.5 py-0.5 rounded">Batch {{ $msg['sender_batch'] }}</span>
                                            @endif
                                        @elseif($isCourseRoom)
                                            @if($msg['sender_batch'])
                                                <span class="ml-1 text-[10px] font-semibold bg-[#ede0f5] text-[#5c2d7a] px-1.5 py-0.5 rounded">Batch {{ $msg['sender_batch'] }}</span>
                                            @endif
                                        @endif
                                    </p>
                                    @endif

                                    @if($msg['is_pinned'])
                                    <div class="flex items-center gap-1 text-xs text-amber-600 font-semibold mb-0.5 px-1">
                                        <i class="fa-solid fa-thumbtack text-xs"></i> Pinned
                                    </div>
                                    @endif

                                    @if($msg['reply_to'])
                                    <div class="text-sm rounded-lg px-2.5 py-1.5 mb-1 max-w-full border-l-[3px] leading-snug {{ $msg['is_mine'] ? 'bg-purple-200/60 border-white/70 text-purple-900' : 'bg-white border-[#ddd3e8] text-[#666666]' }}">
                                        <span class="font-semibold block truncate text-xs">{{ $msg['reply_to']['name'] }}</span>
                                        <span class="truncate block text-xs">{{ Str::limit($msg['reply_to']['body'], 70) }}</span>
                                    </div>
                                    @endif

                                    <div class="relative">

                                        @if($editingId === $msg['id'])
                                        <div class="flex flex-col gap-1.5 min-w-[220px]">
                                            <textarea wire:model="editBody" rows="2"
                                                      class="text-sm rounded-lg border border-[#6b2490] px-3 py-2 resize-none focus:outline-none focus:ring-2 focus:ring-[#6b2490]/30 w-full bg-white shadow-sm"
                                                      wire:keydown.escape="cancelEdit"></textarea>
                                            <div class="flex gap-1.5 justify-end">
                                                <button wire:click="cancelEdit" class="text-xs px-3 py-1.5 rounded-lg border border-[#ddd3e8] text-[#666666] hover:bg-[#f5f5f5] transition font-semibold cursor-pointer">Cancel</button>
                                                <button wire:click="saveEdit" class="text-xs px-3 py-1.5 rounded-lg text-white font-semibold hover:opacity-90 transition cursor-pointer" style="background:#6b2490;">Save</button>
                                            </div>
                                        </div>

                                        {{-- ══ SHARED JOB / EVENT PREVIEW CARD ══
                                             Rendered whenever body carries a
                                             [[JOB:id]] or [[EVENT:TYPE:id]] marker
                                             — mirrors director/director-messenger.blade.php
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
                                        <div wire:click.stop="toggleToolbar({{ $msg['id'] }})"
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
                                                       class="msgr-post-view-btn px-3 py-1.5 rounded-full bg-white text-[#4a1863] text-xs font-bold shadow-md inline-flex items-center gap-1.5">
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
                                        @else
                                        @php
                                            $safe = htmlspecialchars($msg['body'], ENT_QUOTES, 'UTF-8');
                                            $mentionClass = $msg['is_mine']
                                                ? 'font-semibold text-yellow-200 bg-yellow-400/20 px-0.5 rounded'
                                                : 'font-semibold text-[#6b2490] bg-[#f2e8f9] px-0.5 rounded';
                                            $formatted = preg_replace('/@(everyone|\w+(?:\s\w+)?)/u', '<span class="'.$mentionClass.'">@$1</span>', $safe);
                                        @endphp
                                        <button wire:click.stop="toggleToolbar({{ $msg['id'] }})"
                                             wire:loading.class="org-bubble-loading" wire:target="toggleToolbar({{ $msg['id'] }})"
                                             class="org-bubble text-left px-3.5 py-2.5 rounded-2xl text-sm leading-relaxed break-words w-full cursor-pointer shadow-sm {{ $bubbleCls }} {{ $toolbarOpen ? 'org-bubble-open' : '' }}"
                                             style="{{ $bubbleBg }}">
                                            {!! $formatted !!}
                                            @if($msg['edited'])<span class="text-xs opacity-50 ml-1 italic">(edited)</span>@endif
                                            <span class="org-bubble-spinner" wire:loading wire:target="toggleToolbar({{ $msg['id'] }})">
                                                <i class="fa-solid fa-spinner fa-spin"></i>
                                            </span>
                                        </button>
                                        @endif

                                        {{-- Action toolbar --}}
                                        @if($toolbarOpen)
                                        <div class="org-reaction-toolbar absolute bottom-full mb-2 {{ $msg['is_mine'] ? 'right-0' : 'left-0' }}
                                                    flex flex-wrap items-center gap-0.5 bg-white border border-[#ddd3e8] rounded-2xl px-2 py-1.5 shadow-xl whitespace-nowrap animate-[orgPop_.14s_ease-out]"
                                             @click.stop>
                                            @foreach(['heart'=>'❤️','purple'=>'💜','like'=>'👍','dislike'=>'👎'] as $rk=>$re)
                                            <div class="relative org-tooltip-wrap">
                                                <button wire:click.stop="react({{ $msg['id'] }},'{{ $rk }}')"
                                                        class="w-9 h-9 flex items-center justify-center rounded-xl text-xl leading-none transition-all duration-150 cursor-pointer hover:scale-125 active:scale-110 {{ $msg['my_reaction']===$rk?'bg-[#f2e8f9] ring-2 ring-[#6b2490]':'hover:bg-[#f9f5fd]' }}">{{ $re }}</button>
                                                <span class="org-tooltip top-full left-1/2 -translate-x-1/2 mt-2 px-2.5 py-1.5 rounded-lg">{{ ucfirst($rk) }}</span>
                                            </div>
                                            @endforeach

                                            <span class="w-px h-5 bg-[#ddd3e8] mx-0.5 flex-shrink-0"></span>

                                            <div class="relative org-tooltip-wrap">
                                                <button wire:click.stop="setReply({{ $msg['id'] }})"
                                                        class="w-8 h-8 flex items-center justify-center rounded-xl text-[#555] cursor-pointer hover:bg-[#f2e8f9] hover:text-[#6b2490] transition-all duration-150">
                                                    <i class="fa-solid fa-reply text-xs"></i>
                                                </button>
                                                <span class="org-tooltip top-full left-1/2 -translate-x-1/2 mt-2 px-2.5 py-1.5 rounded-lg">Reply</span>
                                            </div>

                                            <div class="relative org-tooltip-wrap">
                                                <button wire:click.stop="togglePin({{ $msg['id'] }})"
                                                        @if(! $canTogglePin) disabled @endif
                                                        class="w-8 h-8 flex items-center justify-center rounded-xl transition-all duration-150
                                                               {{ $msg['is_pinned']
                                                                   ? ($canTogglePin
                                                                       ? 'text-amber-600 bg-amber-50 hover:bg-amber-100 cursor-pointer'
                                                                       : 'text-amber-300 bg-amber-50/50 cursor-not-allowed')
                                                                   : 'text-[#555] hover:bg-amber-50 hover:text-amber-600 cursor-pointer' }}">
                                                    <i class="fa-solid fa-thumbtack text-xs"></i>
                                                </button>
                                                <span class="org-tooltip top-full left-1/2 -translate-x-1/2 mt-2 px-2.5 py-1.5 rounded-lg">{{ $msg['is_pinned'] ? ($canTogglePin ? 'Unpin' : 'Only pinner can unpin') : 'Pin' }}</span>
                                            </div>

                                            @if($msg['is_mine'])
                                            <span class="w-px h-5 bg-[#ddd3e8] mx-0.5 flex-shrink-0"></span>

                                            <div class="relative org-tooltip-wrap">
                                                <button wire:click.stop="startEdit({{ $msg['id'] }})"
                                                        class="w-8 h-8 flex items-center justify-center rounded-xl text-[#555] cursor-pointer hover:bg-[#f2e8f9] hover:text-[#6b2490] transition-all duration-150">
                                                    <i class="fa-solid fa-pen text-xs"></i>
                                                </button>
                                                <span class="org-tooltip top-full left-1/2 -translate-x-1/2 mt-2 px-2.5 py-1.5 rounded-lg">Edit</span>
                                            </div>

                                            <div class="relative org-tooltip-wrap">
                                                <button wire:click.stop="askDeleteConfirmation({{ $msg['id'] }})"
                                                        class="w-8 h-8 flex items-center justify-center rounded-xl text-[#555] cursor-pointer hover:bg-red-50 hover:text-red-600 transition-all duration-150">
                                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                                </button>
                                                <span class="org-tooltip top-full left-1/2 -translate-x-1/2 mt-2 px-2.5 py-1.5 rounded-lg">Delete</span>
                                            </div>
                                            @endif
                                        </div>
                                        @endif

                                        {{-- Reactions popup --}}
                                        @if($reactionsPopupMsgId === $msg['id'] && ! empty($reactionsPopupData))
                                        <div class="org-reactions-popup absolute top-full mt-2 {{ $msg['is_mine'] ? 'right-0' : 'left-0' }} bg-white border border-[#ddd3e8] rounded-2xl shadow-xl w-64 max-w-[80vw] overflow-hidden animate-[orgPop_.14s_ease-out]" wire:click.stop>
                                            <div class="flex items-center justify-between px-3.5 py-2.5 border-b border-[#ddd3e8] bg-[#fafafa]">
                                                <p class="text-xs font-semibold text-[#333333] uppercase tracking-widest"><i class="fa-solid fa-face-smile text-[#6b2490] mr-1.5"></i>Reactions</p>
                                                <button wire:click="closeReactionsPopup" class="w-6 h-6 flex items-center justify-center rounded-full text-[#999999] hover:text-[#333333] hover:bg-[#f5f5f5] transition cursor-pointer"><i class="fa-solid fa-xmark text-xs"></i></button>
                                            </div>
                                            <div class="org-reactions-popup-list">
                                                @php $emojiMap=['heart'=>'❤️','purple'=>'💜','like'=>'👍','dislike'=>'👎']; @endphp
                                                @foreach($reactionsPopupData as $rKey=>$rGroup)
                                                <div class="px-3.5 py-2 border-b border-[#ddd3e8] last:border-0">
                                                    <div class="flex items-center gap-1.5 mb-1.5">
                                                        <span class="text-base">{{ $emojiMap[$rKey]??'👍' }}</span>
                                                        <span class="text-xs font-semibold text-[#666666]">{{ count($rGroup) }} {{ count($rGroup)===1?'person':'people' }}</span>
                                                    </div>
                                                    @foreach($rGroup as $reactor)
                                                    <div class="flex items-center gap-2 py-1">
                                                        <div class="w-6 h-6 rounded-full flex-shrink-0 overflow-hidden flex items-center justify-center text-xs font-semibold text-white" style="background:#6b2490;">
                                                            <img src="{{ $reactor['photo']??$defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="">
                                                        </div>
                                                        <div class="flex-1 min-w-0">
                                                            <p class="text-xs font-semibold text-[#333333] truncate">{{ $reactor['name'] }}@if($reactor['is_me'])<span class="text-[#6b2490] font-semibold"> (You)</span>@endif</p>
                                                            <p class="text-[10px] font-medium {{ $reactor['type']==='director'?'text-violet-700':'text-[#6b2490]' }}">{{ ucfirst($reactor['type']) }}</p>
                                                        </div>
                                                    </div>
                                                    @endforeach
                                                </div>
                                                @endforeach
                                            </div>
                                        </div>
                                        @endif

                                    </div>

                                    {{-- Reaction counts --}}
                                    @if(! empty($msg['reactions']))
                                    <div class="flex gap-1 mt-1 flex-wrap {{ $msg['is_mine']?'justify-end':'justify-start' }}">
                                        @foreach($msg['reactions'] as $rk=>$cnt)
                                        @php $emoji=match($rk){'heart'=>'❤️','purple'=>'💜','like'=>'👍','dislike'=>'👎',default=>'👍'}; @endphp
                                        <button wire:click.stop="openReactionsPopup({{ $msg['id'] }})"
                                                class="inline-flex items-center gap-0.5 text-xs px-1.5 py-0.5 rounded-full border transition-all cursor-pointer
                                                       {{ $msg['my_reaction']===$rk?'bg-[#f2e8f9] border-[#c49bdb] text-[#6b2490] font-semibold':'bg-white border-[#ddd3e8] text-[#666666] hover:border-[#c49bdb]' }}">
                                            {{ $emoji }}<span class="font-semibold ml-0.5">{{ $cnt }}</span>
                                        </button>
                                        @endforeach
                                    </div>
                                    @endif

                                    <p class="text-xs text-[#999999] mt-0.5 px-1">{{ $msg['time'] }}</p>
                                </div>

                                @if($msg['is_mine'])
                                <div class="w-7 h-7 rounded-full flex-shrink-0 overflow-hidden flex items-center justify-center text-xs font-semibold text-white mb-1 self-end" style="background:#6b2490;">
                                    <img src="{{ $coordinatorPhoto ?: $defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="">
                                </div>
                                @endif
                            </div>

                        @empty
                            <div class="flex flex-col items-center justify-center h-full py-20 text-[#999999] select-none">
                                <div class="w-20 h-20 rounded-2xl flex items-center justify-center mb-5 bg-[#f2e8f9]">
                                    <i class="fa-solid fa-hand-pointer text-4xl text-[#6b2490]"></i>
                                </div>
                                <p class="text-base font-semibold text-[#333333]">No messages yet</p>
                                <p class="text-sm text-[#999999] mt-2 max-w-xs text-center leading-relaxed">
                                    @if($isStaffRoom) Start the internal staff conversation! 👋
                                    @elseif($isCollegeRoom) Start the {{ $department }} college-wide conversation! 👋
                                    @elseif($isCourseRoom) Start the {{ $this->displayCourseLabel($room['course_code'] ?? '') }} all-batches conversation! 👋
                                    @else Be the first to message this batch! 👋
                                    @endif
                                </p>
                            </div>
                        @endforelse

                        <div class="h-10"></div>
                    </div>

                    {{-- ── Scroll-to-top / scroll-to-bottom quick nav ───────────────
                         wire:ignore.self keeps Livewire's poll-driven morph from
                         touching this node mid Alpine transition. --}}
                    <div class="org-scroll-nav" wire:ignore.self>
                        <button type="button" class="org-scroll-btn"
                                x-show="scrollDir === 'up'"
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-150"
                                x-transition:leave-start="opacity-100"
                                x-transition:leave-end="opacity-0"
                                onclick="document.getElementById('msg-list').scrollBy({top:-300,behavior:'smooth'});"
                                style="display:none;">
                            <i class="fa-solid fa-arrow-up"></i>
                        </button>
                        <button type="button" class="org-scroll-btn"
                                x-show="scrollDir === 'down'"
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-150"
                                x-transition:leave-start="opacity-100"
                                x-transition:leave-end="opacity-0"
                                onclick="document.getElementById('msg-list').scrollBy({top:300,behavior:'smooth'});"
                                style="display:none;">
                            <i class="fa-solid fa-arrow-down"></i>
                        </button>
                    </div>

                </div>

                {{-- Typing indicator --}}
                <div class="flex-shrink-0">
                    @if(! empty($typingUsers))
                    <div class="flex items-center gap-2.5 px-4 py-2 bg-[#fafafa] border-t border-[#ddd3e8]">
                        <div class="flex items-end gap-0.5 h-4">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#6b2490] animate-bounce" style="animation-delay:0ms;animation-duration:900ms;"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-[#6b2490] animate-bounce" style="animation-delay:180ms;animation-duration:900ms;"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-[#6b2490] animate-bounce" style="animation-delay:360ms;animation-duration:900ms;"></span>
                        </div>
                        <p class="text-xs text-[#666666] font-medium">
                            @php $visible=array_slice($typingUsers,0,3); $extra=count($typingUsers)-count($visible); @endphp
                            <span class="font-semibold text-[#6b2490]">{{ implode(', ',$visible) }}{{ $extra>0?" +{$extra}":'' }}</span>
                            {{ count($typingUsers)===1?'is':'are' }} typing…
                        </p>
                    </div>
                    @endif
                </div>

                {{-- Reply bar --}}
                @if($replyTo)
                <div class="flex items-center gap-3 px-4 py-2.5 border-t border-[#ddd3e8] bg-[#f2e8f9] flex-shrink-0 animate-[orgPop_.14s_ease-out]">
                    <div class="w-1 h-10 rounded-full flex-shrink-0" style="background:#6b2490;"></div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-[#6b2490] truncate uppercase tracking-widest">Replying to {{ $replyTo['name'] }}</p>
                        <p class="text-xs text-[#666666] truncate">{{ Str::limit($replyTo['body'],90) }}</p>
                    </div>
                    <button wire:click="clearReply" class="w-7 h-7 flex items-center justify-center rounded-full text-[#999999] hover:text-red-600 hover:bg-red-50 transition flex-shrink-0 cursor-pointer">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>
                @endif

                {{-- Compose bar --}}
                <div class="px-3 sm:px-4 py-3 border-t border-[#ddd3e8] bg-white flex-shrink-0" x-data>
                    @if($showMentions && ! empty($mentionSuggestions))
                    <div class="mb-2 bg-white border border-[#ddd3e8] rounded-2xl shadow-md overflow-hidden animate-[orgPop_.14s_ease-out]">
                        @foreach($mentionSuggestions as $sug)
                        <button wire:click="selectMention('{{ addslashes($sug['name']) }}')"
                                class="flex items-center gap-2.5 w-full px-3 py-2.5 hover:bg-[#f2e8f9] transition-colors text-left cursor-pointer">
                            <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-semibold text-white" style="background:#6b2490;">
                                @if($sug['name']==='everyone')<i class="fa-solid fa-users text-xs"></i>
                                @else{{ strtoupper(substr($sug['name'],0,1)) }}@endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-[#333333] truncate">&#64;{{ $sug['name'] }}</p>
                                @if($sug['name']==='everyone')
                                    <p class="text-xs text-[#6b2490] font-medium">Notify all members</p>
                                @elseif($sug['type']==='director')
                                    <p class="text-xs text-violet-700 font-medium"><i class="fa-solid fa-shield-halved text-[10px] mr-0.5"></i>Director</p>
                                @elseif($sug['type']==='coordinator')
                                    <p class="text-xs text-[#6b2490] font-medium">Coordinator</p>
                                @endif
                            </div>
                        </button>
                        @endforeach
                    </div>
                    @endif

                    <div class="flex items-end gap-2">
                        <div class="flex-1 relative">
                            <textarea id="chat-input"
                                wire:model.live.debounce.200ms="body"
                                wire:keyup.debounce.800ms="pingTyping"
                                placeholder="Message {{ $isStaffRoom ? 'Staff Chat' : ($isCollegeRoom ? $department.' College GC' : ($isCourseRoom ? $this->displayCourseLabel($room['course_code'] ?? '').' All Batches GC' : ('Batch '.$room['batch'].' · '.$this->displayCourseLabel($room['course_code'] ?? '')))) }}… (@ to mention)"
                                rows="1"
                                @keydown.enter="if(!$event.shiftKey){$event.preventDefault();$wire.sendMessage();}"
                                @focus-input.window="$el.focus()"
                                x-init="$el.addEventListener('input',function(){this.style.height='auto';this.style.height=Math.min(this.scrollHeight,120)+'px';});"
                                class="w-full resize-none rounded-xl border-2 border-[#6b2490]/40 bg-[#fafafa] px-4 py-2.5 text-sm leading-relaxed text-[#333333] focus:outline-none focus:border-[#6b2490] focus:ring-2 focus:ring-[#6b2490]/20 transition placeholder-[#999999]"
                                style="max-height:120px;overflow-y:auto;"></textarea>
                        </div>
                        <button wire:click="sendMessage" wire:loading.attr="disabled" wire:target="sendMessage"
                                class="w-10 h-10 rounded-full flex items-center justify-center text-white flex-shrink-0 transition hover:opacity-90 active:scale-95 shadow-sm disabled:opacity-60 cursor-pointer"
                                style="background:#6b2490;">
                            <i class="fa-solid fa-paper-plane text-base" wire:loading.remove wire:target="sendMessage"></i>
                            <span class="hidden items-center gap-1" wire:loading.flex wire:target="sendMessage">
                                <span class="w-1.5 h-1.5 rounded-full bg-white animate-bounce" style="animation-delay:0ms;animation-duration:800ms;"></span>
                                <span class="w-1.5 h-1.5 rounded-full bg-white animate-bounce" style="animation-delay:150ms;animation-duration:800ms;"></span>
                                <span class="w-1.5 h-1.5 rounded-full bg-white animate-bounce" style="animation-delay:300ms;animation-duration:800ms;"></span>
                            </span>
                        </button>
                    </div>
                    <p class="text-xs text-[#999999] text-center mt-1.5 hidden sm:block">
                        <kbd class="bg-[#f5f5f5] border border-[#ddd3e8] rounded px-1 py-0.5 text-xs">Enter</kbd> send &nbsp;·&nbsp;
                        <kbd class="bg-[#f5f5f5] border border-[#ddd3e8] rounded px-1 py-0.5 text-xs">Shift+Enter</kbd> new line &nbsp;·&nbsp;
                        <kbd class="bg-[#f5f5f5] border border-[#ddd3e8] rounded px-1 py-0.5 text-xs">@</kbd> mention
                    </p>
                </div>
            </div>

            {{-- Side panel (Members / Pins) --}}
            @if($showMembers || $showPins)
            <div wire:key="org-side-panel-{{ $showPins ? 'pins' : 'members' }}"
                 class="org-panel w-full md:w-72 flex flex-col flex-shrink-0 bg-white border-l border-[#ddd3e8]
                        fixed md:static inset-0 z-[150] md:z-auto"
                 style="animation: orgPanelIn .16s ease-out;">
                <div class="flex items-center gap-2.5 px-4 py-3 border-b border-[#ddd3e8] flex-shrink-0 bg-[#f9f7fc]">
                    @if($showPins)
                        <i class="fa-solid fa-thumbtack text-amber-600"></i>
                        <p class="text-sm font-semibold text-[#333333] flex-1 uppercase tracking-wide">Pinned Messages</p>
                    @elseif($isStaffRoom)
                        <i class="fa-solid fa-shield-halved text-[#6b2490]"></i>
                        <p class="text-sm font-semibold text-[#333333] flex-1 uppercase tracking-wide">Staff Members <span class="text-xs font-semibold text-[#999999] ml-1">({{ count($staffDirectors)+count($staffCoords) }})</span></p>
                    @elseif($isCollegeRoom)
                        <i class="fa-solid fa-school text-[#6b2490]"></i>
                        <p class="text-sm font-semibold text-[#333333] flex-1 uppercase tracking-wide">All Members <span class="text-xs font-semibold text-[#999999] ml-1">({{ count($alumni) + count($coordinators) }})</span>@if($onlineCount > 0)<span class="ml-1 text-xs font-semibold text-emerald-600">· {{ $onlineCount }} online</span>@endif</p>
                    @elseif($isCourseRoom)
                        <i class="fa-solid fa-layer-group text-[#6b2490]"></i>
                        <p class="text-sm font-semibold text-[#333333] flex-1 uppercase tracking-wide">All Members <span class="text-xs font-semibold text-[#999999] ml-1">({{ count($alumni) + count($coordinators) }})</span>@if($onlineCount > 0)<span class="ml-1 text-xs font-semibold text-emerald-600">· {{ $onlineCount }} online</span>@endif</p>
                    @else
                        <i class="fa-solid fa-user-group text-[#6b2490]"></i>
                        <p class="text-sm font-semibold text-[#333333] flex-1 uppercase tracking-wide">Members <span class="text-xs font-semibold text-[#999999] ml-1">({{ count($alumni) + count($coordinators) }})</span>@if($onlineCount > 0)<span class="ml-1 text-xs font-semibold text-emerald-600">· {{ $onlineCount }} online</span>@endif</p>
                    @endif
                    <button wire:click="closeSidePanel" wire:loading.attr="disabled" wire:target="closeSidePanel"
                            class="w-7 h-7 flex items-center justify-center rounded-lg text-[#999999] hover:text-[#333333] hover:bg-[#f5f5f5] transition cursor-pointer disabled:opacity-60">
                        <i class="fa-solid fa-xmark text-sm" wire:loading.remove wire:target="closeSidePanel"></i>
                        <i class="fa-solid fa-spinner fa-spin text-sm" wire:loading wire:target="closeSidePanel"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto flex flex-col">
                    @if($showMembers && $isStaffRoom)
                        @if(! empty($staffDirectors))
                        <div class="px-3 pt-3 pb-1 flex-shrink-0">
                            <p class="text-xs font-semibold text-violet-700 uppercase tracking-widest mb-2 px-1"><i class="fa-solid fa-shield-halved text-xs mr-1"></i>Directors — {{ count($staffDirectors) }}</p>
                            @foreach($staffDirectors as $dir)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2 mb-1 bg-violet-50 border border-violet-100">
                                <div class="relative flex-shrink-0">
                                    <div class="w-8 h-8 rounded-full overflow-hidden flex items-center justify-center" style="background:#4a1d78;">
                                        <img src="{{ $dir['photo']??$defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="">
                                    </div>
                                    @if($dir['is_online'])<span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-400 border-2 border-white"></span>@endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#333333] truncate">{{ $dir['name'] }}</p>
                                    <p class="text-xs font-medium {{ $dir['is_online']?'text-emerald-600':'text-violet-700' }}">{{ $dir['is_online']?'🟢 Online':'Director' }}</p>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        @endif
                        <div class="px-3 py-2.5 border-b border-[#ddd3e8] flex-shrink-0">
                            <p class="text-xs font-semibold text-[#6b2490] uppercase tracking-widest mb-2 px-1"><i class="fa-solid fa-users text-xs mr-1"></i>Coordinators — {{ count($staffCoords) }}</p>
                            <div class="relative" wire:ignore
                                 x-data="{ q:'', init(){ this.q=$wire.memberSearch??''; $wire.$watch('memberSearch',v=>{ if(v!==this.q)this.q=v; }); } }">
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[#999999] text-xs pointer-events-none"
                                   wire:loading.class="opacity-0" wire:target="memberSearch"></i>
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 hidden"
                                      wire:loading.class.remove="hidden" wire:target="memberSearch">
                                    <span class="org-filter-spinner"><span></span><span></span><span></span></span>
                                </span>
                                <input type="text" x-model="q" @input.debounce.200ms="$wire.set('memberSearch',q)"
                                       placeholder="Search staff…"
                                       autocomplete="off" spellcheck="false"
                                       class="w-full pl-8 pr-3 py-2 text-sm rounded-lg border border-[#ddd3e8] bg-[#fafafa] focus:outline-none focus:border-[#6b2490] focus:ring-1 focus:ring-[#6b2490]/20 transition placeholder-[#999999]"/>
                            </div>
                        </div>
                        <div class="flex-1 overflow-y-auto px-3 pb-3 space-y-1 pt-2">
                            @php $onlineCoords=collect($staffCoords)->where('is_online',true)->values(); $offlineCoords=collect($staffCoords)->where('is_online',false)->values(); @endphp
                            @foreach($onlineCoords as $coord)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 border border-[#ddd3e8] hover:border-[#c49bdb] hover:bg-[#f2e8f9] transition-all {{ $coord['is_me']?'bg-[#f2e8f9] border-[#c49bdb]':'' }}">
                                <div class="relative flex-shrink-0">
                                    <div class="w-8 h-8 rounded-full overflow-hidden flex items-center justify-center" style="background:#6b2490;">
                                        <img src="{{ $coord['photo']??$defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="">
                                    </div>
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-400 border-2 border-white"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#333333] truncate">{{ $coord['name'] }}@if($coord['is_me'])<span class="text-[#6b2490] font-semibold"> (You)</span>@endif</p>
                                    <p class="text-xs font-medium text-emerald-600">🟢 Online</p>
                                </div>
                            </div>
                            @endforeach
                            @foreach($offlineCoords as $coord)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 border border-[#ddd3e8] hover:bg-[#fafafa] transition-all opacity-70 {{ $coord['is_me']?'bg-[#f2e8f9] border-[#c49bdb] opacity-100':'' }}">
                                <div class="w-8 h-8 rounded-full flex-shrink-0 overflow-hidden flex items-center justify-center" style="background:#c4a8d4;">
                                    <img src="{{ $coord['photo']??$defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#666666] truncate">{{ $coord['name'] }}@if($coord['is_me'])<span class="text-[#6b2490] font-semibold"> (You)</span>@endif</p>
                                    <p class="text-xs font-medium text-[#999999]">Offline</p>
                                </div>
                            </div>
                            @endforeach
                            @if(empty($staffCoords))
                            <div class="flex flex-col items-center justify-center py-8 text-[#999999]">
                                <p class="text-sm font-semibold">No results</p>
                            </div>
                            @endif
                        </div>

                    @elseif($showMembers && ! $isStaffRoom)
                        @if($isCollegeRoom)
                        <div class="px-4 py-2 bg-[#f2e8f9] border-b border-[#ddd3e8] flex-shrink-0">
                            <p class="text-xs font-semibold text-[#6b2490] flex items-center gap-1.5">
                                <i class="fa-solid fa-school text-xs"></i>All courses & batches — {{ $department }}
                            </p>
                        </div>
                        @endif
                        @if(! empty($coordinators) && $memberSearch === '')
                        <div class="px-3 pt-3 pb-1 flex-shrink-0">
                            <p class="text-xs font-semibold text-[#6b2490] uppercase tracking-widest mb-2 px-1"><i class="fa-solid fa-shield-halved text-xs mr-1"></i>Coordinators</p>
                            @foreach($coordinators as $coord)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2 mb-1 bg-[#f2e8f9] border border-[#ddd3e8]">
                                <div class="relative flex-shrink-0">
                                    <div class="w-8 h-8 rounded-full overflow-hidden flex items-center justify-center" style="background:#6b2490;">
                                        <img src="{{ $coord['photo']??$defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="">
                                    </div>
                                    @if($coord['is_online']||$coord['is_me'])<span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-400 border-2 border-white"></span>@endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#333333] truncate">{{ $coord['name'] }}@if($coord['is_me'])<span class="text-xs text-[#6b2490] font-semibold"> (You)</span>@endif</p>
                                    <p class="text-xs text-[#6b2490] font-medium">{{ ($coord['is_online']||$coord['is_me'])?'🟢 Online':'Coordinator' }}</p>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        <div class="px-3 pb-1 flex-shrink-0">
                            <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest mb-1 px-1">
                                <i class="fa-solid fa-users text-xs mr-1"></i>Alumni
                                @if($isCollegeRoom)<span class="text-[#6b2490] ml-1">· All Courses & Batches</span>
                                @elseif($isCourseRoom)<span class="text-[#6b2490] ml-1">· All Batches</span>@endif
                            </p>
                        </div>
                        @endif
                        <div class="px-3 py-2.5 border-b border-[#ddd3e8] flex-shrink-0">
                            <div class="relative" wire:ignore
                                 x-data="{ q:'', init(){ this.q=$wire.memberSearch??''; $wire.$watch('memberSearch',v=>{ if(v!==this.q)this.q=v; }); } }">
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[#999999] text-xs pointer-events-none"
                                   wire:loading.class="opacity-0" wire:target="memberSearch"></i>
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 hidden"
                                      wire:loading.class.remove="hidden" wire:target="memberSearch">
                                    <span class="org-filter-spinner"><span></span><span></span><span></span></span>
                                </span>
                                <input type="text" x-model="q" @input.debounce.200ms="$wire.set('memberSearch',q)"
                                       placeholder="{{ $isCollegeRoom ? 'Search all alumni…' : ($isCourseRoom?'Search all alumni…':'Search alumni…') }}"
                                       autocomplete="off" spellcheck="false"
                                       class="w-full pl-8 pr-3 py-2 text-sm rounded-lg border border-[#ddd3e8] bg-[#fafafa] focus:outline-none focus:border-[#6b2490] focus:ring-1 focus:ring-[#6b2490]/20 transition placeholder-[#999999]"/>
                            </div>
                        </div>
                        <div class="flex-1 overflow-y-auto px-3 pb-3 space-y-1 pt-2.5">
                            @php $onlineAlumni=collect($alumni)->where('is_online',true)->values(); $offlineAlumni=collect($alumni)->where('is_online',false)->values(); @endphp
                            @if(count($onlineAlumni)>0)
                            <p class="text-xs font-semibold text-emerald-600 uppercase tracking-widest px-1 pb-1"><i class="fa-solid fa-circle text-[9px] mr-1"></i>Online — {{ count($onlineAlumni) }}</p>
                            @foreach($onlineAlumni as $al)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 border border-[#ddd3e8] hover:border-[#c49bdb] hover:bg-[#f2e8f9] transition-all">
                                <div class="relative flex-shrink-0">
                                    <div class="w-9 h-9 rounded-full overflow-hidden flex items-center justify-center" style="background:#6b2490;">
                                        <img src="{{ $al['photo']??$defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="">
                                    </div>
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-400 border-2 border-white"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#333333] truncate">{{ $al['name'] }}</p>
                                    <p class="text-xs font-medium text-emerald-600">
                                        Online
                                        @if($isCollegeRoom || $isCourseRoom)
                                            @if($al['course_code'])<span class="text-[#6b2490] ml-1">· {{ strtoupper($al['course_code']) }}</span>@endif
                                            @if($al['batch'])<span class="text-[#999999] ml-1">Batch {{ $al['batch'] }}</span>@endif
                                        @endif
                                    </p>
                                </div>
                            </div>
                            @endforeach
                            @endif
                            @if(count($offlineAlumni)>0 && count($onlineAlumni)>0 && $memberSearch==='')
                            <div class="pt-2.5 pb-1 px-1">
                                <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest"><i class="fa-solid fa-circle text-[9px] mr-1 opacity-40"></i>Offline — {{ count($offlineAlumni) }}</p>
                            </div>
                            @endif
                            @foreach($offlineAlumni as $al)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 border border-[#ddd3e8] hover:bg-[#fafafa] transition-all opacity-70">
                                <div class="w-9 h-9 rounded-full flex-shrink-0 overflow-hidden flex items-center justify-center" style="background:#c4a8d4;">
                                    <img src="{{ $al['photo']??$defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#666666] truncate">{{ $al['name'] }}</p>
                                    <p class="text-xs font-medium text-[#999999]">
                                        Offline
                                        @if($isCollegeRoom || $isCourseRoom)
                                            @if($al['course_code'])<span class="ml-1">· {{ strtoupper($al['course_code']) }}</span>@endif
                                            @if($al['batch'])<span class="ml-1">Batch {{ $al['batch'] }}</span>@endif
                                        @endif
                                    </p>
                                </div>
                            </div>
                            @endforeach
                            @if(empty($alumni))
                            <div class="flex flex-col items-center justify-center py-10 text-[#999999]">
                                <i class="fa-solid fa-user-slash text-3xl text-[#ddd3e8] mb-3"></i>
                                <p class="text-sm font-semibold">No results</p>
                                <p class="text-xs mt-1">Try a different name</p>
                            </div>
                            @endif
                        </div>

                    @elseif($showPins)
                        <div class="flex-1 overflow-y-auto p-3 space-y-2">
                            @forelse($pinnedMessages as $pin)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 transition-all duration-150">
                                <div class="flex items-start justify-between gap-2 mb-1.5">
                                    <div class="flex items-center gap-1.5 min-w-0">
                                        <i class="fa-solid fa-thumbtack text-amber-600 text-xs flex-shrink-0"></i>
                                        <p class="text-xs font-semibold text-amber-800 truncate">{{ $pin['from'] }}</p>
                                    </div>
                                    @if($pin['pinned_by_me'])
                                    <button wire:click="togglePin({{ $pin['id'] }})"
                                            wire:loading.attr="disabled" wire:target="togglePin({{ $pin['id'] }})"
                                            class="w-5 h-5 flex items-center justify-center rounded-full text-[#999999] hover:text-red-600 hover:bg-red-50 transition-all duration-150 flex-shrink-0 cursor-pointer disabled:opacity-60">
                                        <i class="fa-solid fa-xmark text-xs" wire:loading.remove wire:target="togglePin({{ $pin['id'] }})"></i>
                                        <i class="fa-solid fa-spinner fa-spin text-xs" wire:loading wire:target="togglePin({{ $pin['id'] }})"></i>
                                    </button>
                                    @else
                                    <span class="w-5 h-5 flex items-center justify-center text-[#cccccc] flex-shrink-0" title="Only the pinner can remove this">
                                        <i class="fa-solid fa-lock text-[10px]"></i>
                                    </span>
                                    @endif
                                </div>
                                <p class="text-sm text-[#333333] leading-snug break-words">{{ Str::limit($pin['body'],140) }}</p>
                                <p class="text-xs text-[#999999] mt-1.5">{{ $pin['pinned_at'] }}</p>
                            </div>
                            @empty
                            <div class="flex flex-col items-center justify-center py-14 text-[#999999]">
                                <i class="fa-solid fa-thumbtack text-4xl text-[#ddd3e8] mb-3"></i>
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
    {{-- No room selected --}}
    <div id="org-chatpane"
         :class="mobileChatOpen ? 'org-mobile-show' : ''"
         class="hidden md:flex flex-1 items-center justify-center bg-[#fafafa]">
        <div class="flex flex-col items-center text-center px-8">
            <div class="w-20 h-20 rounded-2xl flex items-center justify-center mb-5 bg-[#f2e8f9]">
                <i class="fa-solid fa-hand-pointer text-4xl text-[#6b2490]"></i>
            </div>
            <p class="text-lg font-semibold text-[#333333]">Click a message to view</p>
            <p class="text-sm text-[#999999] mt-2 max-w-xs leading-relaxed">
                Select a chat from the left panel to start messaging.
            </p>
            <div class="mt-5 flex items-center gap-2 text-xs text-[#999999]">
                <span class="w-5 h-5 flex items-center justify-center rounded-lg bg-[#f2e8f9]"><i class="fa-solid fa-shield-halved text-[#6b2490]" style="font-size:10px;"></i></span>
                <span>Staff</span>
                <span class="mx-1 text-[#ddd3e8]">·</span>
                <span class="w-5 h-5 flex items-center justify-center rounded-lg bg-[#f2e8f9]"><i class="fa-solid fa-school text-[#6b2490]" style="font-size:10px;"></i></span>
                <span>College</span>
                <span class="mx-1 text-[#ddd3e8]">·</span>
                <span class="w-5 h-5 flex items-center justify-center rounded-lg bg-[#f2e8f9]"><i class="fa-solid fa-users text-[#6b2490]" style="font-size:10px;"></i></span>
                <span>Batch</span>
            </div>
        </div>
    </div>
    @endif

    {{-- ══ Delete confirmation modal ══════════════════════════════════════
         Single instance inside the component root, driven by
         $confirmDeleteId (server-side state), so Confirm/Cancel wire:click
         bindings always work — no lost state from row re-renders. --}}
    @if($confirmDeleteId)
    @php $delMsg = collect($messages)->firstWhere('id', $confirmDeleteId); @endphp
    <div class="org-modal-backdrop" wire:click="cancelDelete">
        <div class="org-modal-card" wire:click.stop>
            <div class="p-5">
                <div class="flex items-start gap-3.5">
                    <div class="org-modal-icon">
                        <i class="fa-solid fa-trash-can text-lg"></i>
                    </div>
                    <div class="flex-1 min-w-0 pt-1">
                        <p class="text-sm font-semibold text-[#1a1a1a]">Delete this message?</p>
                        <p class="text-xs text-[#666666] mt-1 leading-relaxed">
                            Are you sure you want to delete this message? This can't be undone
                            @if($delMsg && (! empty($delMsg['reactions']) || ($delMsg['is_pinned'] ?? false)))
                                , and it will also remove its reactions{{ ($delMsg['is_pinned'] ?? false) ? ' and unpin it' : '' }}
                            @endif
                            .
                        </p>
                    </div>
                </div>
            </div>
            <div class="flex border-t border-[#ddd3e8]">
                <button wire:click="cancelDelete"
                        class="flex-1 py-3 text-sm font-semibold text-[#555555] hover:bg-[#f5f5f5] transition-all duration-150 cursor-pointer">
                    Cancel
                </button>
                <div class="w-px bg-[#ddd3e8]"></div>
                <button wire:click="unsend({{ $confirmDeleteId }})"
                        wire:loading.attr="disabled" wire:target="unsend"
                        class="flex-1 py-3 text-sm font-semibold text-red-600 hover:bg-red-50 transition-all duration-150 cursor-pointer disabled:opacity-60">
                    <span wire:loading.remove wire:target="unsend">Confirm</span>
                    <span wire:loading wire:target="unsend">Deleting…</span>
                </button>
            </div>
        </div>
    </div>
    @endif

</div>