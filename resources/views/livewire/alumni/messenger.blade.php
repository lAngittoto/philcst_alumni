{{-- resources/views/livewire/alumni/messenger.blade.php --}}

<?php

use App\Models\Alumni;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

new class extends \Livewire\Volt\Component {

    public array  $rooms        = [];
    public ?array $room         = null;
    public int    $roomId       = 0;
    public string $roomType     = '';

    public array $messages = [];

    public string $body    = '';
    public ?array $replyTo = null;

    public ?int   $editingId = null;
    public string $editBody  = '';

    public bool  $showBatchmates = false;
    public bool  $showPins       = false;
    public array $batchmates     = [];
    public array $coordinators   = [];
    public array $pinnedMessages = [];

    public string $batchSearch = '';

    public int $onlineCount = 0;
    public int $totalCount  = 0;

    public array $mentionSuggestions = [];
    public bool  $showMentions       = false;

    public array $typingUsers = [];

    public ?int  $reactionsPopupMsgId = null;
    public array $reactionsPopupData  = [];

    public int    $alumniId        = 0;
    public string $alumniName      = '';
    public string $alumniFirstName = '';
    public string $alumniPhoto     = '';
    public string $alumniCourse    = '';
    public string $alumniBatch     = '';
    public string $alumniCollege   = '';

    public ?int $openToolbarMsgId = null;

    public array $lastNotifiedMessageIds = [];
    public array $pinnedRoomIds          = [];

    // ── tick counter — drives staggered work inside the single poll ──
    public int $pollTick = 0;

    // ── Cache key helpers ──────────────────────────────────────────────────
    private function lastReadCacheKey(int $roomId): string
    {
        return "chat_read.alumni.{$this->alumniId}.room.{$roomId}";
    }

    private function lastNotifiedCacheKey(int $roomId): string
    {
        return "chat_notified.alumni.{$this->alumniId}.room.{$roomId}";
    }

    private function pinnedRoomsCacheKey(): string
    {
        return "chat_pinned_rooms.alumni.{$this->alumniId}";
    }

    // ── These are kept for the notification dispatch side-effect only ──────
    private function unreadCacheKey(): string
    {
        return "chat_unread_rooms.alumni.{$this->alumniId}";
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

    private function resolvePhotoUrl(?string $path): string
    {
        $default = asset('storage/alumni-photos/default.png');
        if (! $path || str_contains($path, 'default.png')) return $default;
        if (
            str_starts_with($path, 'alumni-photos/') ||
            str_starts_with($path, 'organizers/')    ||
            str_starts_with($path, 'directors/')
        ) {
            return \Illuminate\Support\Facades\Storage::disk('public')->exists($path)
                ? asset('storage/' . $path)
                : $default;
        }
        return $default;
    }

    // ── 1-minute online threshold — also gives a natural 1-min "grace
    //    period" after logout before a user flips to Offline, since we
    //    only compare against the last `last_seen_at` ping timestamp ──────
    private function formatLastSeen(?string $lastSeenAt): string
    {
        if (! $lastSeenAt) return 'Offline';
        $ts  = Carbon::parse($lastSeenAt)->setTimezone('Asia/Manila');
        $now = Carbon::now('Asia/Manila');
        $diff = $now->diffInSeconds($ts);
        if ($diff < 60)                return 'Online';
        if ($diff < 3600)              return 'Active ' . floor($diff / 60) . 'm ago';
        if ($ts->isToday())            return 'Active today at ' . $ts->format('h:i A');
        if ($ts->isYesterday())        return 'Active yesterday';
        if ($now->diffInDays($ts) < 7) return 'Active ' . $now->diffInDays($ts) . 'd ago';
        return 'Active ' . $ts->format('M d');
    }

    // ── 1-minute online threshold (same grace window as above) ─────────────
    private function isOnline(?string $lastSeenAt): bool
    {
        if (! $lastSeenAt) return false;
        return Carbon::parse($lastSeenAt)->gte(Carbon::now()->subMinutes(1));
    }

    /**
     * ── College-room marker (course_code value used for college-wide rooms)
     *
     * ROOT CAUSE OF "only 1 chat shows" BUG:
     *   Every college-wide room used to be stored with course_code = '' and
     *   batch = 0. The `chat_rooms` table has a UNIQUE constraint on
     *   (course_code, batch) — so the FIRST college that ever got its room
     *   created "claimed" the ('', 0) slot. Every other college (and every
     *   alumni in it) could never get their own College GC created, because
     *   the insert silently failed the unique check and was caught/skipped.
     *   That's why only ONE group chat (the Batch GC) was visible.
     *
     * FIX (no migration needed):
     *   Each college now gets its own unique, deterministic course_code
     *   marker instead of sharing the blank one — e.g. "CLG_a1b2c3d4".
     *   This keeps (course_code, batch) genuinely unique per college under
     *   the existing DB constraint, so every college can have its own
     *   College GC at the same time.
     */
    private function collegeMarker(string $college): string
    {
        return 'CLG_' . substr(md5($college), 0, 12);
    }

    public function mount(): void
    {
        $user = Auth::user();
        if (! $user || $user->role !== 'alumni') { $this->redirect(route('login')); return; }
        $alumni = Alumni::where('user_id', $user->id)->first();
        if (! $alumni) { $this->redirect(route('login')); return; }

        $this->alumniId        = $alumni->id;
        $this->alumniName      = trim(($alumni->first_name ?? '') . ' ' . ($alumni->last_name ?? ''));
        $this->alumniFirstName = $alumni->first_name ?? '';
        $this->alumniPhoto     = $this->resolvePhotoUrl($alumni->profile_photo ?? null);
        $this->alumniCourse    = $alumni->course_code ?? '';
        $this->alumniBatch     = (string) ($alumni->batch ?? '');
        $this->alumniCollege   = DB::table('courses')->where('code', $alumni->course_code)->value('college') ?? '';

        $this->pinnedRoomIds = Cache::get($this->pinnedRoomsCacheKey(), []);

        // ── Every alumni gets exactly 2 group chats:
        //      1) Batch GC  (their course_code + batch)
        //      2) College GC (all courses & batches under their college —
        //         e.g. BSIT + BSCS + etc. — where Coordinators of that
        //         college also belong)
        $this->ensureRoomsExist();
        $this->pingPresence();
        $this->loadRooms();
        $this->seedNotifiedPointers();
    }

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

    /**
     * ── FIX: Duplicate-entry crash AND "missing college room" bug ───────
     * See collegeMarker() above for the full root-cause explanation.
     *
     * Batch rooms stay keyed on the alumni's real (course_code, batch) —
     * that pairing is genuinely supposed to be unique. College rooms now
     * use a per-college unique marker in course_code instead of a shared
     * blank value, so every college can have its own room.
     *
     * try/catch against UniqueConstraintViolationException is kept as a
     * safety net for race conditions (two requests creating the same room
     * at the same time) — whichever request wins, we just re-fetch
     * normally afterward.
     *
     * ── SELF-HEAL NOW RUNS UNCONDITIONALLY EVERY MOUNT ──────────────────
     * Previously the legacy ('', 0) row was only migrated for the FIRST
     * college that happened to load the page after this fix shipped.
     * Every other college's room stayed stuck on the blank marker, which
     * is exactly why the sidebar badge could still show a stray
     * course-code-looking row instead of "All Courses" — that row was a
     * *different* college's legacy ('', 0) chat, misclassified as if it
     * might belong here. We now check + self-heal on every single mount
     * for whichever college the current alumni belongs to, so it
     * converges immediately instead of depending on load order.
     */
    protected function ensureRoomsExist(): void
    {
        $college = $this->alumniCollege;

        // ── Batch room (course_code + batch is genuinely unique per batch) ──
        $batchExists = DB::table('chat_rooms')
            ->where('course_code', $this->alumniCourse)
            ->where('batch', (int) $this->alumniBatch)
            ->exists();

        if (! $batchExists) {
            try {
                DB::table('chat_rooms')->insert([
                    'name'        => strtoupper($this->alumniCourse) . ' · Batch ' . $this->alumniBatch,
                    'course_code' => $this->alumniCourse,
                    'batch'       => (int) $this->alumniBatch,
                    'department'  => $college,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Another concurrent request already created this exact
                // batch room — that's fine, just continue.
            }
        }

        // ── College room — unique marker per college, NOT a shared ('', 0) ──
        if ($college) {
            $marker = $this->collegeMarker($college);

            $collegeExists = DB::table('chat_rooms')
                ->where('department', $college)
                ->where('course_code', $marker)
                ->where('batch', 0)
                ->exists();

            // ── Self-heal: a legacy row may exist from the old ('', 0)
            //    scheme, *for this college specifically*. Migrate it in
            //    place instead of creating a dupe, so existing message
            //    history for that college isn't lost. This now runs on
            //    every mount (not just once globally) so each college
            //    converges to its own marker independently.
            if (! $collegeExists) {
                $legacyRow = DB::table('chat_rooms')
                    ->where('department', $college)
                    ->where('course_code', '')
                    ->where('batch', 0)
                    ->first();

                if ($legacyRow) {
                    try {
                        DB::table('chat_rooms')->where('id', $legacyRow->id)->update([
                            'course_code' => $marker,
                            'updated_at'  => now(),
                        ]);
                        $collegeExists = true;
                    } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                        // marker somehow already taken — fall through and
                        // let the exists() check below handle it normally.
                    }
                }
            }

            if (! $collegeExists) {
                try {
                    DB::table('chat_rooms')->insert([
                        'name'        => $college . ' · All Courses & Batches',
                        'course_code' => $marker,
                        'batch'       => 0,
                        'department'  => $college,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    // Concurrent request for the same college already
                    // created it — safe to skip.
                }
            }
        }
    }

    private function isCollegeRoom($row): bool
    {
        return (int) ($row->batch ?? 0) === 0 && (string) ($row->course_code ?? '') !== '';
    }

    /**
     * ── FIX: stray "BSIT-BSCS"-looking row in sidebar ───────────────────
     * loadRooms() used to match ANY room with course_code IN [marker, '']
     * and batch = 0 under the alumni's department — including legacy
     * ('', 0) rows that belong to a *different* college that hasn't been
     * self-healed yet (department was sometimes blank/stale on old rows).
     * Now we only ever match this alumni's own college marker, and the
     * legacy blank-marker fallback is dropped entirely now that
     * ensureRoomsExist() unconditionally self-heals on every mount.
     */
    public function loadRooms(): void
    {
        $college = $this->alumniCollege;
        $marker  = $college ? $this->collegeMarker($college) : null;

        $rows = DB::table('chat_rooms')
            ->where(function ($q) use ($college, $marker) {
                $q->where(function ($sub) {
                    $sub->where('course_code', $this->alumniCourse)
                        ->where('batch', (int) $this->alumniBatch);
                });
                if ($college && $marker) {
                    $q->orWhere(function ($sub) use ($college, $marker) {
                        $sub->where('department', $college)
                            ->where('course_code', $marker)
                            ->where('batch', 0);
                    });
                }
            })
            ->get()->toArray();

        $self = $this;

        $mapped = collect($rows)->map(function ($r) use ($self, $college) {
            $isCollege = $self->isCollegeRoom($r);

            $latest = DB::table('chat_messages as m')
                ->where('m.room_id', $r->id)
                ->whereNull('m.deleted_at')
                ->orderByDesc('m.created_at')
                ->first(['m.body', 'm.sender_type', 'm.sender_id', 'm.created_at']);

            $latestBody = $latestSender = $latestTime = null;
            $latestTs   = null;
            if ($latest) {
                $latestBody = $latest->body;
                $latestTs   = Carbon::parse($latest->created_at);
                $latestTime = $latestTs->setTimezone('Asia/Manila')->format('h:i A');
                if ($latest->sender_type === 'alumni') {
                    $name = DB::table('alumni')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = $name ?? 'Alumni';
                } else {
                    $name = DB::table('organizer')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = ($name ?? 'Coordinator') . ' (Coordinator)';
                }
            }

            $collegeCourseCodes = [];
            if ($isCollege && $college) {
                $collegeCourses = DB::table('courses')->where('college', $college)->pluck('code');
                $collegeCourseCodes = $collegeCourses->map(fn ($c) => strtoupper($c))->values()->toArray();
                $alumniBase = DB::table('alumni')->whereIn('course_code', $collegeCourses)->whereNull('deleted_at');
                $coordBase  = DB::table('organizer')->where('department', $college)->where('status', 'ACTIVE')->whereNull('deleted_at');
            } else {
                $alumniBase = DB::table('alumni')
                    ->where('course_code', $r->course_code)
                    ->where('batch', $r->batch)
                    ->whereNull('deleted_at');
                $coordBase = DB::table('organizer')
                    ->where('department', $r->department ?? $college)
                    ->where('status', 'ACTIVE')
                    ->whereNull('deleted_at');
            }

            $totalAlumni  = (clone $alumniBase)->count();
            $totalCoord   = (clone $coordBase)->count();
            $total        = $totalAlumni + $totalCoord;

            // ── 1-minute online threshold ──────────────────────────────────
            $onlineAlumni = (clone $alumniBase)->where('last_seen_at', '>=', now()->subMinutes(1))->count();
            $onlineCoord  = (clone $coordBase)->where('last_seen_at', '>=', now()->subMinutes(1))->count();
            $online       = $onlineAlumni + $onlineCoord;

            $isCurrentRoom = ($r->id === $self->roomId);

            $lastReadAt = Cache::get($self->lastReadCacheKey($r->id));
            $hasUnread  = ! $isCurrentRoom
                && $latest !== null
                && (
                    $lastReadAt === null
                    || Carbon::parse($latest->created_at)->gt(Carbon::parse($lastReadAt))
                );

            return [
                'id'             => $r->id,
                'name'           => $r->name,
                'course_code'    => $r->course_code,
                'course_codes'   => $collegeCourseCodes,
                'batch'          => (int) $r->batch,
                'department'     => $r->department,
                'type'           => $isCollege ? 'college' : 'batch',
                'latest_body'    => $latestBody,
                'latest_sender'  => $latestSender,
                'latest_time'    => $latestTime,
                'latest_ts'      => $latestTs ? $latestTs->timestamp : 0,
                'total_count'    => $total,
                'online_count'   => $online,
                'is_active'      => $isCurrentRoom,
                'is_pinned_room' => in_array($r->id, $self->pinnedRoomIds, true),
                'has_unread'     => $hasUnread,
            ];
        });

        // ── Messenger-style ordering: Pinned rooms always float to the top.
        //    Within each tier (pinned / not pinned), unread-first, then by
        //    most recent activity — exactly like real Messenger/FB chat. ────
        $this->rooms = $mapped->sort(function ($a, $b) {
            $aPinned = $a['is_pinned_room'] ? 1 : 0;
            $bPinned = $b['is_pinned_room'] ? 1 : 0;
            if ($aPinned !== $bPinned) return $bPinned - $aPinned;
            if ($aPinned && $bPinned) return $b['latest_ts'] - $a['latest_ts'];

            $aUnread = $a['has_unread'] ? 1 : 0;
            $bUnread = $b['has_unread'] ? 1 : 0;
            if ($aUnread !== $bUnread) return $bUnread - $aUnread;

            return $b['latest_ts'] - $a['latest_ts'];
        })->values()->toArray();
    }

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

    public function selectRoom(int $id): void
    {
        $row = DB::table('chat_rooms')->find($id);
        if (! $row) return;
        $isCollege = $this->isCollegeRoom($row);
        $isBatch   = $row->course_code === $this->alumniCourse && (int)$row->batch === (int)$this->alumniBatch;
        if ($isCollege && ($row->department !== $this->alumniCollege)) return;
        if (! $isCollege && ! $isBatch) return;

        $this->roomType            = $isCollege ? 'college' : 'batch';
        $this->roomId              = $row->id;
        $this->room                = (array) $row;
        $this->body                = '';
        $this->replyTo             = null;
        $this->editingId           = null;
        $this->editBody            = '';
        $this->showBatchmates      = false;
        $this->showPins            = false;
        $this->batchSearch         = '';
        $this->reactionsPopupMsgId = null;
        $this->reactionsPopupData  = [];
        $this->batchmates          = [];
        $this->coordinators        = [];
        $this->openToolbarMsgId    = null;

        $maxId = (int) (DB::table('chat_messages')
            ->where('room_id', $id)
            ->whereNull('deleted_at')
            ->max('id') ?? 0);

        $this->lastNotifiedMessageIds[$id] = $maxId;
        Cache::put($this->lastNotifiedCacheKey($id), $maxId, now()->addDays(30));

        $this->clearUnreadRoomId($id);
        $this->markRoomAsRead($id);
        $this->refreshOnlineCount();
        $this->loadMessages();
        $this->loadBatchmates();
        $this->loadCoordinators();
        $this->loadTypingIndicators();
        $this->loadRooms();
        $this->dispatch('chat-scroll-bottom');
        $this->dispatch('chat-open-mobile');
    }

    public function backToList(): void
    {
        $this->dispatch('chat-close-mobile');
    }

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

    // ── Explicit, single-purpose toggles for the side panel ────────────────
    // Each one is self-contained and forces the other panel closed, so the
    // panel's visibility is driven purely by these two booleans — no mixed
    // Alpine/Livewire state to fall out of sync.
    public function openMembersPanel(): void
    {
        $this->showBatchmates = true;
        $this->showPins       = false;
        $this->batchSearch    = '';
        $this->loadBatchmates();
        $this->loadCoordinators();
    }

    public function openPinsPanel(): void
    {
        $this->showPins       = true;
        $this->showBatchmates = false;
        $this->loadPins();
    }

    public function closeSidePanel(): void
    {
        $this->showBatchmates = false;
        $this->showPins       = false;
    }

    public function toggleBatchmates(): void
    {
        if ($this->showBatchmates) { $this->closeSidePanel(); return; }
        $this->openMembersPanel();
    }

    public function togglePins(): void
    {
        if ($this->showPins) { $this->closeSidePanel(); return; }
        $this->openPinsPanel();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  SINGLE POLL — wire:poll.1500ms
    // ─────────────────────────────────────────────────────────────────────
    public function unifiedPoll(): void
    {
        $this->pollTick++;

        // ── Every tick (~1.5s): fast unread detection + room list ─────────
        $this->checkAndDispatchNewMessageNotifications();
        $this->loadRooms();

        // ── Every tick: typing indicators (lightweight) ───────────────────
        if ($this->roomId) {
            $this->loadTypingIndicators();
        }

        // ── Every 2nd tick (~3s): load messages + mark read ───────────────
        if ($this->pollTick % 2 === 0 && $this->roomId) {
            $this->loadMessages();
            $this->markRoomAsRead($this->roomId);
            $this->dispatch('chat-scroll-bottom');
        }

        // ── Every 4th tick (~6s): heavier presence work ───────────────────
        if ($this->pollTick % 4 === 0) {
            $this->pingPresence();
            $this->refreshOnlineCount();
        }
    }

    private function checkAndDispatchNewMessageNotifications(): void
    {
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

            $newMessages = DB::table('chat_messages as m')
                ->where('m.room_id', $roomId)
                ->whereNull('m.deleted_at')
                ->where('m.id', '>', $lastKnown)
                ->where(function ($q) {
                    $q->where('m.sender_type', '!=', 'alumni')
                      ->orWhere('m.sender_id', '!=', $this->alumniId);
                })
                ->orderBy('m.id')
                ->get(['m.id', 'm.sender_type', 'm.sender_id', 'm.body'])
                ->toArray();

            if (empty($newMessages)) continue;

            $newMaxId = (int) max(array_column($newMessages, 'id'));
            $this->lastNotifiedMessageIds[$roomId] = $newMaxId;
            Cache::put($this->lastNotifiedCacheKey($roomId), $newMaxId, now()->addDays(30));

            if ($roomId === $this->roomId) {
                continue;
            }

            $latest     = end($newMessages);
            $senderName = 'Someone';
            if (in_array($latest->sender_type, ['organizer', 'coordinator'], true)) {
                $firstName  = DB::table('organizer')->where('id', $latest->sender_id)->value('first_name');
                $senderName = ($firstName ?? 'Coordinator') . ' (Coordinator)';
            } else {
                $firstName  = DB::table('alumni')->where('id', $latest->sender_id)->value('first_name');
                $senderName = $firstName ?? 'Someone';
            }

            $this->dispatch('message-received',
                sender: $senderName,
                room:   $room['name'] ?? 'Group Chat',
                body:   mb_substr($latest->body ?? '', 0, 60),
                count:  count($newMessages),
            );
        }
    }

    public function pingPresence(): void
    {
        try { DB::table('alumni')->where('id', $this->alumniId)->update(['last_seen_at' => now()]); } catch (\Throwable) {}
    }

    public function refreshOnlineCount(): void
    {
        if (! $this->room) return;
        try {
            $college = $this->alumniCollege ?: ($this->room['department'] ?? '');

            if ($this->roomType === 'college' && $college) {
                $collegeCourses = DB::table('courses')->where('college', $college)->pluck('code');
                $alumniBase = DB::table('alumni')->whereIn('course_code', $collegeCourses)->whereNull('deleted_at');
                $coordBase  = DB::table('organizer')->where('department', $college)->where('status', 'ACTIVE')->whereNull('deleted_at');
            } else {
                $alumniBase = DB::table('alumni')
                    ->where('course_code', $this->room['course_code'])
                    ->where('batch', $this->room['batch'])
                    ->whereNull('deleted_at');
                $coordBase = DB::table('organizer')
                    ->where('department', $this->room['department'] ?? $college)
                    ->where('status', 'ACTIVE')
                    ->whereNull('deleted_at');
            }

            $totalAlumni  = (clone $alumniBase)->count();
            $totalCoord   = (clone $coordBase)->count();
            $this->totalCount = $totalAlumni + $totalCoord;

            // ── 1-minute online threshold ──────────────────────────────────
            $onlineAlumni = (clone $alumniBase)->where('last_seen_at', '>=', now()->subMinutes(1))->count();
            $onlineCoord  = (clone $coordBase)->where('last_seen_at', '>=', now()->subMinutes(1))->count();
            $this->onlineCount = $onlineAlumni + $onlineCoord;

        } catch (\Throwable) {
            $this->totalCount  = count($this->batchmates) + count($this->coordinators);
            $this->onlineCount = 0;
        }
    }

    public function pingTyping(): void
    {
        if (trim($this->body) === '') { $this->stopTyping(); return; }
        try {
            DB::table('chat_typing')->updateOrInsert(
                ['room_id' => $this->roomId, 'sender_type' => 'alumni', 'sender_id' => $this->alumniId],
                ['typed_at' => now(), 'updated_at' => now()]
            );
        } catch (\Throwable) {}
    }

    public function stopTyping(): void
    {
        try {
            DB::table('chat_typing')
                ->where('room_id', $this->roomId)
                ->where('sender_type', 'alumni')
                ->where('sender_id', $this->alumniId)
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
                    $q->where('sender_type', '!=', 'alumni')->orWhere('sender_id', '!=', $this->alumniId);
                })
                ->get(['sender_type', 'sender_id']);
            $names = [];
            foreach ($rows as $row) {
                if ($row->sender_type === 'alumni') {
                    $name = DB::table('alumni')->where('id', $row->sender_id)->value('first_name');
                    if ($name) $names[] = $name;
                } else {
                    $name = DB::table('organizer')->where('id', $row->sender_id)->value('first_name');
                    if ($name) $names[] = $name . ' (Coordinator)';
                }
            }
            $this->typingUsers = $names;
        } catch (\Throwable) { $this->typingUsers = []; }
    }

    public function loadMessages(): void
    {
        if (! $this->roomId) return;
        $rows = DB::table('chat_messages as m')
            ->where('m.room_id', $this->roomId)
            ->whereNull('m.deleted_at')
            ->orderBy('m.created_at')
            ->get(['m.id','m.sender_type','m.sender_id','m.body','m.reply_to_id','m.edited_at','m.created_at'])
            ->toArray();

        $aIds = collect($rows)->where('sender_type','alumni')->pluck('sender_id')->unique();
        $oIds = collect($rows)->whereIn('sender_type',['organizer','coordinator'])->pluck('sender_id')->unique();
        $aMap = DB::table('alumni')->whereIn('id', $aIds)
            ->get(['id','first_name','last_name','profile_photo','batch','course_code','last_seen_at'])
            ->keyBy(fn ($a) => (int) $a->id);
        $oMap = DB::table('organizer')->whereIn('id', $oIds)
            ->get(['id','first_name','last_name','profile_photo'])
            ->keyBy(fn ($o) => (int) $o->id);

        $msgIds  = collect($rows)->pluck('id');
        $rxns    = DB::table('chat_reactions')->whereIn('message_id', $msgIds)->get()->groupBy('message_id');
        $pins    = DB::table('chat_pins')->whereIn('message_id', $msgIds)->pluck('message_id')->flip();
        $rplyIds = collect($rows)->whereNotNull('reply_to_id')->pluck('reply_to_id')->unique();
        $rplyMap = DB::table('chat_messages')->whereIn('id', $rplyIds)->whereNull('deleted_at')
            ->get(['id','sender_type','sender_id','body'])
            ->keyBy(fn ($m) => (int) $m->id);

        $self = $this;
        $this->messages = collect($rows)->map(function ($m) use ($aMap, $oMap, $rxns, $pins, $rplyMap, $self) {
            $isCoord = in_array($m->sender_type, ['organizer','coordinator'], true);
            $sid     = (int) $m->sender_id;
            $s       = $isCoord ? $oMap->get($sid) : $aMap->get($sid);
            $sName   = $s ? trim(($s->first_name ?? '') . ' ' . ($s->last_name ?? ''))
                          : ($isCoord ? 'Coordinator' : 'Alumni');
            $photo   = $s ? $self->resolvePhotoUrl($s->profile_photo ?? null) : $self->resolvePhotoUrl(null);

            $msgRxns = $rxns->get($m->id, collect());
            $rxnGrps = $msgRxns->groupBy('reaction')->map(fn ($g) => $g->count())->toArray();
            $myRxn   = $msgRxns->first(fn ($r) => $r->reactor_type === 'alumni' && (int)$r->reactor_id === $self->alumniId);

            $reply = null;
            if ($m->reply_to_id && $rplyMap->has((int)$m->reply_to_id)) {
                $r  = $rplyMap->get((int)$m->reply_to_id);
                $rs = in_array($r->sender_type, ['organizer','coordinator'], true)
                    ? $oMap->get((int)$r->sender_id)
                    : $aMap->get((int)$r->sender_id);
                $reply = [
                    'id'   => $r->id,
                    'body' => $r->body,
                    'name' => $rs ? trim(($rs->first_name ?? '') . ' ' . ($rs->last_name ?? '')) : 'Alumni',
                ];
            }

            return [
                'id'              => $m->id,
                'sender_type'     => $m->sender_type,
                'sender_id'       => $m->sender_id,
                'sender_name'     => $sName,
                'sender_photo'    => $photo,
                'sender_course'   => (! $isCoord && $s) ? ($s->course_code ?? '') : '',
                'sender_batch'    => (! $isCoord && $s) ? (string)($s->batch ?? '') : '',
                'sender_lastseen' => (! $isCoord && $s) ? ($s->last_seen_at ?? null) : null,
                'body'            => $m->body,
                'edited'          => ! is_null($m->edited_at),
                'is_mine'         => $m->sender_type === 'alumni' && $sid === $self->alumniId,
                'is_coordinator'  => $isCoord,
                'is_pinned'       => isset($pins[$m->id]),
                'reactions'       => $rxnGrps,
                'my_reaction'     => $myRxn ? $myRxn->reaction : null,
                'reply_to'        => $reply,
                'time'            => Carbon::parse($m->created_at)->setTimezone('Asia/Manila')->format('h:i A'),
                'date'            => Carbon::parse($m->created_at)->setTimezone('Asia/Manila')->format('Y-m-d'),
                'date_label'      => Carbon::parse($m->created_at)->setTimezone('Asia/Manila')->format('M d, Y'),
            ];
        })->values()->toArray();
    }

    public function sendMessage(): void
    {
        $body = trim($this->body);
        if ($body === '' || ! $this->roomId) return;
        $college = $this->alumniCollege;

        $msgId = DB::table('chat_messages')->insertGetId([
            'room_id'     => $this->roomId,
            'sender_type' => 'alumni',
            'sender_id'   => $this->alumniId,
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
                if ($this->roomType === 'college' && $college) {
                    $collegeCourses = DB::table('courses')->where('college', $college)->pluck('code');
                    $foundAlumni = DB::table('alumni')->whereIn('course_code', $collegeCourses)
                        ->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$mention}%")->value('id');
                } else {
                    $foundAlumni = DB::table('alumni')
                        ->where('course_code', $this->room['course_code'])
                        ->where('batch', $this->room['batch'])
                        ->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$mention}%")->value('id');
                }
                if ($foundAlumni) DB::table('chat_mentions')->insert(['message_id'=>$msgId,'mention_type'=>'alumni','mentioned_id'=>$foundAlumni,'created_at'=>now(),'updated_at'=>now()]);
                if ($college) {
                    $foundCoord = DB::table('organizer')->where('department', $college)
                        ->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$mention}%")->value('id');
                    if ($foundCoord) DB::table('chat_mentions')->insert(['message_id'=>$msgId,'mention_type'=>'coordinator','mentioned_id'=>$foundCoord,'created_at'=>now(),'updated_at'=>now()]);
                }
            }
        }

        $this->body = ''; $this->replyTo = null; $this->showMentions = false;
        $this->openToolbarMsgId = null;
        $this->stopTyping();
        $this->markRoomAsRead($this->roomId);

        $this->lastNotifiedMessageIds[$this->roomId] = (int) $msgId;
        Cache::put($this->lastNotifiedCacheKey($this->roomId), (int) $msgId, now()->addDays(30));

        $this->loadMessages();
        $this->loadRooms();
        $this->dispatch('chat-scroll-bottom');
    }

    public function startEdit(int $id): void
    {
        $msg = collect($this->messages)->firstWhere('id', $id);
        if (! $msg || ! $msg['is_mine']) return;
        $this->editingId        = $id;
        $this->editBody         = $msg['body'];
        $this->openToolbarMsgId = null;
    }

    public function saveEdit(): void
    {
        if (! $this->editingId || trim($this->editBody) === '') return;
        DB::table('chat_messages')
            ->where('id', $this->editingId)->where('sender_type','alumni')->where('sender_id', $this->alumniId)
            ->update(['body' => trim($this->editBody), 'edited_at' => now(), 'updated_at' => now()]);
        $this->editingId = null; $this->editBody = '';
        $this->loadMessages();
    }

    public function cancelEdit(): void { $this->editingId = null; $this->editBody = ''; }

    public function unsend(int $id): void
    {
        DB::table('chat_messages')->where('id',$id)->where('sender_type','alumni')->where('sender_id',$this->alumniId)->update(['deleted_at' => now()]);
        DB::table('chat_pins')->where('message_id', $id)->delete();
        $this->openToolbarMsgId = null;
        $this->loadMessages(); $this->loadRooms();
        if ($this->showPins) $this->loadPins();
    }

    public function react(int $msgId, string $reaction): void
    {
        if (! in_array($reaction, ['heart','purple','like','dislike','happy','sad'], true)) return;
        $existing = DB::table('chat_reactions')
            ->where('message_id', $msgId)->where('reactor_type', 'alumni')->where('reactor_id', $this->alumniId)->first();
        if ($existing) {
            $existing->reaction === $reaction
                ? DB::table('chat_reactions')->where('id', $existing->id)->delete()
                : DB::table('chat_reactions')->where('id', $existing->id)->update(['reaction' => $reaction, 'updated_at' => now()]);
        } else {
            DB::table('chat_reactions')->insert([
                'message_id'=>$msgId,'reactor_type'=>'alumni','reactor_id'=>$this->alumniId,
                'reaction'=>$reaction,'created_at'=>now(),'updated_at'=>now(),
            ]);
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
            if (in_array($r->reactor_type,['organizer','coordinator'],true)) {
                $p = DB::table('organizer')->where('id',$r->reactor_id)->first(['first_name','last_name','profile_photo']);
                $name = $p ? trim(($p->first_name??'').' '.($p->last_name??'')) : 'Coordinator';
                $photo= $this->resolvePhotoUrl($p?->profile_photo??null); $type='coordinator';
            } else {
                $p = DB::table('alumni')->where('id',$r->reactor_id)->first(['first_name','last_name','profile_photo']);
                $name = $p ? trim(($p->first_name??'').' '.($p->last_name??'')) : 'Alumni';
                $photo= $this->resolvePhotoUrl($p?->profile_photo??null); $type='alumni';
            }
            $data[] = ['name'=>$name,'photo'=>$photo,'reaction'=>$r->reaction,'type'=>$type,
                'is_me'=>$r->reactor_type==='alumni'&&(int)$r->reactor_id===$this->alumniId];
        }
        $this->reactionsPopupData = collect($data)->groupBy('reaction')->toArray();
    }

    public function closeReactionsPopup(): void { $this->reactionsPopupMsgId=null; $this->reactionsPopupData=[]; }

    public function togglePin(int $msgId): void
    {
        DB::table('chat_pins')->where('message_id',$msgId)->exists()
            ? DB::table('chat_pins')->where('message_id',$msgId)->delete()
            : DB::table('chat_pins')->insert(['room_id'=>$this->roomId,'message_id'=>$msgId,'pinned_by_type'=>'alumni','pinned_by_id'=>$this->alumniId,'created_at'=>now(),'updated_at'=>now()]);
        $this->openToolbarMsgId = null;
        $this->loadMessages();
        if ($this->showPins) $this->loadPins();
    }

    public function setReply(int $id): void
    {
        $msg = collect($this->messages)->firstWhere('id',$id);
        if (!$msg) return;
        $this->replyTo          = ['id'=>$msg['id'],'body'=>$msg['body'],'name'=>$msg['sender_name']];
        $this->openToolbarMsgId = null;
        $this->dispatch('focus-input');
    }

    public function clearReply(): void { $this->replyTo = null; }

    public function loadBatchmates(): void
    {
        $q = trim($this->batchSearch); $college = $this->alumniCollege;
        if ($this->roomType === 'college' && $college) {
            $collegeCourses = DB::table('courses')->where('college',$college)->pluck('code');
            $query = DB::table('alumni')->whereIn('course_code',$collegeCourses)->whereNull('deleted_at');
        } else {
            $query = DB::table('alumni')->where('course_code',$this->room['course_code'])->where('batch',$this->room['batch'])->whereNull('deleted_at');
        }
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('first_name','like',"%{$q}%")->orWhere('last_name','like',"%{$q}%")->orWhereRaw("CONCAT(first_name,' ',last_name) LIKE ?", ["%{$q}%"]);
            });
        }
        $self = $this;
        $this->batchmates = $query->orderBy('course_code')->orderBy('batch')->orderBy('first_name')
            ->get(['id','first_name','last_name','profile_photo','last_seen_at','batch','course_code'])
            ->map(fn ($a) => [
                'id'            => $a->id,
                'name'          => trim($a->first_name . ' ' . $a->last_name),
                'photo'         => $self->resolvePhotoUrl($a->profile_photo ?? null),
                'batch'         => $a->batch,
                'course_code'   => $a->course_code,
                'is_me'         => $a->id === $self->alumniId,
                // ── 1-minute online threshold ──────────────────────────────
                'is_online'     => $self->isOnline($a->last_seen_at ?? null),
                'last_seen_at'  => $a->last_seen_at ?? null,
                'last_seen_fmt' => $self->formatLastSeen($a->last_seen_at ?? null),
            ])->toArray();
    }

    public function updatedBatchSearch(): void { $this->loadBatchmates(); }

    public function loadCoordinators(): void
    {
        $college = $this->alumniCollege ?: ($this->room['department'] ?? '');
        if (! $college) { $this->coordinators = []; return; }
        $self = $this;
        $this->coordinators = DB::table('organizer')
            ->where('department',$college)->where('status','ACTIVE')->whereNull('deleted_at')->orderBy('first_name')
            ->get(['id','first_name','last_name','profile_photo','department','last_seen_at'])
            ->map(fn ($o) => [
                'id'            => $o->id,
                'name'          => trim($o->first_name.' '.$o->last_name),
                'photo'         => $self->resolvePhotoUrl($o->profile_photo??null),
                'dept'          => $o->department,
                // ── 1-minute online threshold ──────────────────────────────
                'is_online'     => $self->isOnline($o->last_seen_at ?? null),
                'last_seen_fmt' => $self->formatLastSeen($o->last_seen_at ?? null),
            ])
            ->toArray();
    }

    public function loadPins(): void
    {
        $rows = DB::table('chat_pins as p')
            ->join('chat_messages as m','m.id','=','p.message_id')
            ->where('p.room_id',$this->roomId)->whereNull('m.deleted_at')
            ->orderByDesc('p.created_at')
            ->get(['m.id','m.sender_type','m.sender_id','m.body','p.created_at as pinned_at'])->toArray();

        $aIds = collect($rows)->where('sender_type','alumni')->pluck('sender_id')->unique();
        $oIds = collect($rows)->whereIn('sender_type',['organizer','coordinator'])->pluck('sender_id')->unique();
        $aMap = DB::table('alumni')->whereIn('id',$aIds)->get(['id','first_name','last_name'])->keyBy(fn($a)=>(int)$a->id);
        $oMap = DB::table('organizer')->whereIn('id',$oIds)->get(['id','first_name','last_name'])->keyBy(fn($o)=>(int)$o->id);

        $this->pinnedMessages = collect($rows)->map(function ($p) use ($aMap,$oMap) {
            $isCoord = in_array($p->sender_type,['organizer','coordinator'],true);
            $s = $isCoord ? $oMap->get((int)$p->sender_id) : $aMap->get((int)$p->sender_id);
            return [
                'id'        => $p->id,
                'body'      => $p->body,
                'from'      => $s ? trim($s->first_name.' '.$s->last_name) : ($isCoord ? 'Coordinator' : 'Alumni'),
                'pinned_at' => Carbon::parse($p->pinned_at)->setTimezone('Asia/Manila')->format('M d, Y h:i A'),
            ];
        })->toArray();
    }

    public function updatedBody(string $value): void
    {
        if (preg_match('/@(\w*)$/', $value, $m)) {
            $q = $m[1]; $college = $this->alumniCollege;
            if ($this->roomType === 'college' && $college) {
                $collegeCourses = DB::table('courses')->where('college',$college)->pluck('code');
                $alumniQ = DB::table('alumni')->whereIn('course_code',$collegeCourses)->whereNull('deleted_at')
                    ->where(fn($sub)=>$sub->where('first_name','like',"%{$q}%")->orWhere('last_name','like',"%{$q}%"));
            } else {
                $alumniQ = DB::table('alumni')->where('course_code',$this->room['course_code'])->where('batch',$this->room['batch'])->whereNull('deleted_at')
                    ->where(fn($sub)=>$sub->where('first_name','like',"%{$q}%")->orWhere('last_name','like',"%{$q}%"));
            }
            $alumni = $alumniQ->limit(5)->get(['id','first_name','last_name'])
                ->map(fn($a)=>['id'=>$a->id,'name'=>trim($a->first_name.' '.$a->last_name),'type'=>'alumni'])->toArray();
            $coordinators = $college
                ? DB::table('organizer')->where('department',$college)->where('status','ACTIVE')->whereNull('deleted_at')
                    ->where(fn($sub)=>$sub->where('first_name','like',"%{$q}%")->orWhere('last_name','like',"%{$q}%"))
                    ->limit(3)->get(['id','first_name','last_name'])
                    ->map(fn($o)=>['id'=>$o->id,'name'=>trim($o->first_name.' '.$o->last_name),'type'=>'coordinator'])->toArray()
                : [];
            $this->mentionSuggestions = array_merge([['id'=>0,'name'=>'everyone','type'=>'everyone']],$alumni,$coordinators);
            $this->showMentions = true;
        } else {
            $this->showMentions = false;
            $this->mentionSuggestions = [];
        }
    }

    public function selectMention(string $name): void
    {
        $this->body = preg_replace('/@\w*$/', '@' . $name . ' ', $this->body);
        $this->showMentions = false; $this->mentionSuggestions = [];
        $this->dispatch('focus-input');
    }
}; ?>

{{-- ══════════════════════════════════════════════════════════════════════════
     TEMPLATE — Messenger-style group chat UI
     ─────────────────────────────────────────────────────────────────────────
     CHANGES IN THIS REVISION
     ─────────────────────────────────────────────────────────────────────────
     1) REMOVED the 419 "page expired" auto-reload script entirely, per
        request. wire:poll continues to run as a normal Livewire poll — if a
        session genuinely expires Laravel's default behavior applies.

     2) FIXED Pins/Members panel not opening on click.
        Root cause: the panel's visibility was driven by a Blade boolean
        baked into a literal Alpine `x-show="true"/"false"` STRING at render
        time, layered on top of a *separate* Tailwind class toggle on the
        same element. Two different "is this open" signals fighting each
        other, plus an Alpine x-transition that re-evaluates against a value
        that never changes reactively after first paint, meant clicks could
        update the Livewire property correctly but the panel still wouldn't
        visibly show/hide. Replaced with a single source of truth: plain
        Blade `@if($showBatchmates || $showPins)` controls whether the panel
        node exists in the DOM at all (no Alpine x-show needed), and two
        explicit methods (openMembersPanel / openPinsPanel / closeSidePanel)
        replace the old toggle-with-side-effects logic so state can't get
        stuck.

     3) FIXED stray course-code-looking room in the sidebar instead of a
        clean "All Courses" college room.
        Root cause: loadRooms() matched ANY legacy ('', 0) college room
        across colleges as a fallback, and ensureRoomsExist() only
        self-healed the legacy row for whichever college loaded first.
        Self-heal now runs for the current alumni's college on every mount,
        and loadRooms() only ever matches this alumni's own college marker
        — no more blank-marker fallback that could pull in a different
        college's leftover row.

     4) FIXED reaction toolbar tooltips getting visually clipped/hidden
        behind the purple chat header. Header now gets an explicit modest
        z-index (z-10) and establishes its own stacking context only for
        itself; the reaction toolbar + tooltips render with z-[300], well
        above the header, and `overflow: visible` is enforced up the
        relevant ancestor chain so nothing clips the floating toolbar near
        the top of the message list.

     5) Pinned rooms still float to the top; new messages bump a room
        toward the top of its tier — Messenger style. Layout stays fully
        responsive: small screens behave like a single-pane Messenger app
        (list ⇄ chat with a back button), side panel becomes a full-screen
        overlay on mobile and a fixed 288px column on desktop.
════════════════════════════════════════════════════════════════════════════ --}}
<div
    x-data="{ mobileChatOpen: false }"
    @chat-open-mobile.window="mobileChatOpen = true"
    @chat-close-mobile.window="mobileChatOpen = false"
    class="flex rounded-2xl border border-[#E8E0F0] bg-white shadow-sm overflow-hidden"
    style="height: calc(100vh - 90px);"
    wire:poll.1500ms="unifiedPoll">

    <style>
        /* ── Smooth Messenger-style transitions ── */
        #msgr-room-list button,
        #msgr-room-list .msgr-pin-btn,
        .msgr-bubble,
        .msgr-panel,
        .msgr-tooltip { transition: all .22s cubic-bezier(.4,0,.2,1); }

        #msgr-room-list > div { transition: transform .25s ease, opacity .25s ease; }

        .msgr-bubble { transform-origin: bottom; animation: msgrPop .18s ease-out; }
        @keyframes msgrPop {
            from { opacity: 0; transform: translateY(6px) scale(.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes msgrPanelIn {
            from { opacity: 0; transform: translateX(12px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        /* ── Tooltip overlay — guaranteed on top of EVERYTHING, including
           the sticky purple header, never clipped. Black bg / white text,
           no border. ──────────────────────────────────────────────────── */
        .msgr-tooltip {
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
        .msgr-tooltip-wrap:hover .msgr-tooltip {
            opacity: 1;
            transform: translateY(0);
        }

        /* ── Stacking-context fix: header sits at z-10 (its own low
           context), reaction toolbar + tooltips sit at z-[300] so they
           always render above the header regardless of scroll position
           in the message list. Ancestors kept overflow-visible so the
           toolbar (which is positioned bottom-full off the bubble) is
           never clipped. ─────────────────────────────────────────────── */
        #msgr-chat-header { position: relative; z-index: 10; }
        #msg-list { overflow-x: visible; }
        .msgr-reaction-toolbar { z-index: 300; }
        .msgr-reaction-toolbar .msgr-tooltip { z-index: 301; }

        /* Readable header text on the purple bar */
        .msgr-hdr-strong { color: #ffffff; }
        .msgr-hdr-soft    { color: #EDE0F5; } /* light lavender — readable on purple */
        .msgr-hdr-faint   { color: #D9C2EE; } /* still readable, used for secondary meta */

        @media (max-width: 768px) {
            #msgr-sidebar { display: none; }
            #msgr-sidebar.msgr-mobile-show { display: flex; width: 100% !important; }
            #msgr-chatpane { display: none; }
            #msgr-chatpane.msgr-mobile-show { display: flex; width: 100% !important; }
        }
    </style>

    @php $defaultAv = asset('storage/alumni-photos/default.png'); @endphp

    {{-- ══ LEFT SIDEBAR ══ --}}
    <div id="msgr-sidebar"
         :class="mobileChatOpen ? '' : 'msgr-mobile-show'"
         class="w-full md:w-72 flex-shrink-0 flex flex-col border-r border-[#E8E0F0] bg-white">

        {{-- My profile header --}}
        <div class="px-4 py-3.5 border-b border-[#5c2778] flex-shrink-0 bg-[#7A3F91]">
            <div class="flex items-center gap-2.5 mb-1">
                <div class="w-9 h-9 rounded-xl flex-shrink-0 overflow-hidden ring-2 ring-white/30 bg-white/18">
                    <img src="{{ $alumniPhoto ?: $defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="{{ $alumniFirstName }}">
                </div>
                <div class="flex-1 min-w-0">
                    <p class="msgr-hdr-strong font-semibold text-sm leading-tight truncate">{{ $alumniName }}</p>
                    <div class="flex items-center gap-1 mt-0.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                        <span class="msgr-hdr-soft text-xs font-semibold">Online · Alumni</span>
                    </div>
                </div>
            </div>
            <p class="msgr-hdr-faint text-xs font-semibold truncate mt-0.5">
                <i class="fa-solid fa-graduation-cap mr-1"></i>{{ strtoupper($alumniCourse) }} · Batch {{ $alumniBatch }}
            </p>
            @if($alumniCollege)
            <p class="msgr-hdr-faint text-xs font-semibold truncate mt-0.5">
                <i class="fa-solid fa-school mr-1"></i>{{ $alumniCollege }}
            </p>
            @endif
        </div>

        {{-- Section label --}}
        <div class="px-4 pt-3 pb-1.5 flex-shrink-0 bg-white border-b border-[#E8E0F0]">
            <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest flex items-center gap-1.5">
                <i class="fa-solid fa-comments"></i> Chats
                <span class="text-xs font-semibold text-[#999999] bg-[#f5f5f5] px-2 py-0.5 rounded-full border border-[#E8E0F0] ml-auto">{{ count($rooms) }}</span>
            </p>
        </div>

        {{-- Room list --}}
        <div id="msgr-room-list" class="flex-1 overflow-y-auto px-2 py-2 space-y-1 bg-white">
            @forelse($rooms as $r)
            @php
                $hasUnread  = $r['has_unread'];
                $isPinnedRm = $r['is_pinned_room'];
                $isActive   = $r['is_active'];
            @endphp

            <div wire:key="room-{{ $r['id'] }}" class="relative" x-data="{ hovered: false }" @mouseenter="hovered = true" @mouseleave="hovered = false" style="isolation: isolate;">

                <button wire:click="selectRoom({{ $r['id'] }})"
                        class="w-full text-left rounded-xl px-3 py-3 transition-all duration-200 border
                               @if($isActive)      border-[#d9c9e8] bg-[#f3eef8]
                               @elseif($hasUnread) border-[#d9b8ef] bg-[#ede5f7] hover:bg-[#e4d8f2]
                               @else               border-transparent hover:border-[#E8E0F0] hover:bg-[#fafafa] @endif">

                    <div class="flex items-start gap-2.5">
                        <div class="relative flex-shrink-0 self-start mt-0.5">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-sm bg-[#7a3f91] transition-transform duration-200">
                                <i class="fa-solid {{ $r['type']==='college' ? 'fa-school' : 'fa-users' }}"></i>
                            </div>
                            @if($hasUnread)
                            <span class="absolute -top-1 -right-1 w-3 h-3 rounded-full bg-red-500 border-2 border-white z-20 animate-pulse"
                                  style="box-shadow: 0 0 0 2px #fff;"></span>
                            @endif
                            @if($isPinnedRm)
                            <span class="absolute -bottom-1 -right-1 w-4 h-4 rounded-full bg-amber-400 border-2 border-white flex items-center justify-center z-10 transition-transform duration-200" title="Pinned">
                                <i class="fa-solid fa-thumbtack text-white" style="font-size:7px; transform: rotate(45deg);"></i>
                            </span>
                            @endif
                        </div>

                        <div class="flex-1 min-w-0 pr-7">
                            <div class="flex items-center justify-between gap-1">
                                <p class="text-sm leading-tight truncate
                                          @if($isActive)      font-semibold text-[#7a3f91]
                                          @elseif($hasUnread) font-bold text-[#1a1a1a]
                                          @else               font-semibold text-[#333333] @endif">
                                    {{ $r['type']==='college' ? $r['department'] : $r['name'] }}
                                </p>
                                <div class="flex items-center gap-1 flex-shrink-0">
                                    @if($hasUnread && ! $isActive)
                                    <span class="w-2 h-2 rounded-full bg-red-500 flex-shrink-0 animate-pulse"></span>
                                    @endif
                                    @if($r['latest_time'])
                                    <span class="text-[11px] font-semibold whitespace-nowrap {{ $hasUnread ? 'text-red-500 font-bold' : 'text-[#999999]' }}">
                                        {{ $r['latest_time'] }}
                                    </span>
                                    @endif
                                </div>
                            </div>

                            {{-- ── College rooms show their actual course codes
                                 (e.g. "BSIT, BSCS") instead of a generic
                                 "All Courses" label, so it's never mistaken
                                 for a single catch-all room. Batch rooms keep
                                 their course code + batch badge. Each type
                                 gets its own light-purple solid (no gradient)
                                 shade so they're visually distinguishable at
                                 a glance — like Messenger room theming. ── --}}
                            <div class="flex items-center gap-1 flex-wrap mt-0.5 mb-0.5">
                                @if($r['type']==='college')
                                <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#EDE0F8] text-[#5C2D7A]"><i class="fa-solid fa-school text-[9px] mr-0.5"></i>{{ implode(', ', $r['course_codes']) ?: 'College' }}</span>
                                <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#EDE0F8] text-[#5C2D7A]">{{ $r['total_count'] }} members</span>
                                @else
                                <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#F3EEF8] text-[#7A3F91]"><i class="fa-solid fa-graduation-cap text-[9px] mr-0.5"></i>Batch {{ $r['batch'] }}</span>
                                <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#F3EEF8] text-[#7A3F91]">{{ strtoupper($r['course_code']) }}</span>
                                @endif
                            </div>

                            @if($r['latest_body'])
                            <p class="text-xs truncate leading-tight {{ $hasUnread ? 'text-[#1a1a1a] font-semibold' : 'text-[#666666]' }}">
                                @if($r['latest_sender'])<span class="font-semibold">{{ $r['latest_sender'] }}:</span> @endif
                                {{ Str::limit($r['latest_body'], 34) }}
                            </p>
                            @else
                            <p class="text-xs text-[#999999] italic">No messages yet</p>
                            @endif

                            @if($r['online_count'] > 0)
                            <div class="flex items-center gap-1 mt-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
                                <span class="text-xs font-semibold text-emerald-600">{{ $r['online_count'] }}/{{ $r['total_count'] }} online</span>
                            </div>
                            @else
                            <p class="text-xs text-[#999999] mt-1">{{ $r['total_count'] }} members</p>
                            @endif
                        </div>
                    </div>
                </button>

                <div class="absolute top-2 right-2 z-30 msgr-tooltip-wrap"
                     x-show="hovered"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 scale-90"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-100"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-90"
                     style="display: none;">
                    <button wire:click.stop="togglePinRoom({{ $r['id'] }})"
                            class="msgr-pin-btn w-7 h-7 rounded-full flex items-center justify-center shadow-md border transition-all duration-200
                                   {{ $isPinnedRm
                                       ? 'bg-amber-400 border-amber-500 text-white hover:bg-amber-500 scale-105'
                                       : 'bg-white border-[#E8E0F0] text-[#aaaaaa] hover:bg-amber-50 hover:text-amber-500 hover:border-amber-300' }}">
                        <i class="fa-solid fa-thumbtack" style="font-size: 10px;"></i>
                    </button>
                    <span class="msgr-tooltip top-full left-1/2 -translate-x-1/2 mt-2 px-2.5 py-1.5 rounded-lg">
                        {{ $isPinnedRm ? 'Unpin room' : 'Pin to top' }}
                    </span>
                </div>

            </div>

            @empty
            <div class="flex flex-col items-center justify-center py-16 text-center px-4">
                <i class="fa-solid fa-comments-slash text-3xl text-[#E8E0F0] mb-3"></i>
                <p class="text-sm font-semibold text-[#666666]">No chats found</p>
            </div>
            @endforelse
        </div>
    </div>

    {{-- ══ MAIN CHAT AREA ══ --}}
    @if($room)
    <div id="msgr-chatpane"
         :class="mobileChatOpen ? 'msgr-mobile-show' : ''"
         class="flex flex-col flex-1 min-w-0 w-full">

        {{-- Chat header --}}
        <div id="msgr-chat-header" class="flex items-center gap-3 px-3 sm:px-5 py-3.5 flex-shrink-0 border-b border-[#5c2778] bg-[#7A3F91]">
            {{-- Mobile back button --}}
            <button @click="mobileChatOpen = false" wire:click="backToList"
                    class="md:hidden w-8 h-8 -ml-1 flex items-center justify-center rounded-full text-white hover:bg-white/15 transition-all duration-200 flex-shrink-0">
                <i class="fa-solid fa-arrow-left text-sm"></i>
            </button>
            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 bg-white/18 border border-white/28">
                <i class="fa-solid {{ $roomType === 'college' ? 'fa-school' : 'fa-users' }} text-white text-sm"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="msgr-hdr-strong font-semibold text-sm leading-tight truncate uppercase tracking-wide">
                    {{ $roomType === 'college' ? $alumniCollege : ($room['name'] ?? 'Group Chat') }}
                </p>
                <div class="flex items-center gap-2 flex-wrap mt-0.5">
                    @if($onlineCount > 0)
                    <div class="flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                        <span class="msgr-hdr-soft text-xs font-semibold">{{ $onlineCount }} online</span>
                    </div>
                    <span class="msgr-hdr-faint text-xs hidden sm:inline">·</span>
                    @endif
                    @if($roomType === 'college')
                    <span class="msgr-hdr-soft text-xs font-semibold items-center gap-1 hidden sm:flex">
                        <i class="fa-solid fa-school text-[10px]"></i>{{ implode(', ', collect($rooms)->firstWhere('id', $roomId)['course_codes'] ?? []) ?: $alumniCollege }} · {{ $totalCount }} members
                    </span>
                    @else
                    <span class="msgr-hdr-soft text-xs font-semibold">{{ $totalCount }} members</span>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-1.5 flex-shrink-0">
                <div class="relative msgr-tooltip-wrap">
                    <button type="button" wire:click="togglePins"
                            class="w-8 h-8 flex items-center justify-center rounded-lg transition-all duration-200
                                   {{ $showPins ? 'bg-white/25 text-white' : 'bg-white/15 msgr-hdr-soft hover:bg-white/25' }}">
                        <i class="fa-solid fa-thumbtack text-xs"></i>
                    </button>
                    <span class="msgr-tooltip top-full left-1/2 -translate-x-1/2 mt-2 px-2.5 py-1.5 rounded-lg">Pins</span>
                </div>
                <div class="relative msgr-tooltip-wrap">
                    <button type="button" wire:click="toggleBatchmates"
                            class="w-8 h-8 flex items-center justify-center rounded-lg transition-all duration-200
                                   {{ $showBatchmates ? 'bg-white/25 text-white' : 'bg-white/15 msgr-hdr-soft hover:bg-white/25' }}">
                        <i class="fa-solid fa-user-group text-xs"></i>
                    </button>
                    <span class="msgr-tooltip top-full left-1/2 -translate-x-1/2 mt-2 px-2.5 py-1.5 rounded-lg">Members</span>
                </div>
            </div>
        </div>

        {{-- Body --}}
        <div class="flex flex-1 min-h-0 relative">
            <div class="flex flex-col flex-1 min-w-0">

                <div id="msg-list"
                     class="flex-1 overflow-y-auto px-3 sm:px-4 py-4 bg-[#F4ECFB]"
                     x-data
                     x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight; })"
                     @chat-scroll-bottom.window="$nextTick(() => { $el.scrollTop = $el.scrollHeight; })"
                     @click="$wire.closeToolbar()">

                    @php $prevDate = null; $prevSendKey = null; $lastIdx = count($messages) - 1; @endphp

                    @forelse($messages as $msgIdx => $msg)
                        @php
                            $dateChanged  = $msg['date'] !== $prevDate;
                            $senderKey    = $msg['sender_type'] . $msg['sender_id'];
                            $sameGroup    = ! $dateChanged && $senderKey === $prevSendKey;
                            $prevDate     = $msg['date'];
                            $prevSendKey  = $senderKey;
                            $isLast       = $msgIdx === $lastIdx;
                            $toolbarOpen  = $openToolbarMsgId === $msg['id'];
                        @endphp

                        @if($dateChanged)
                        <div class="flex items-center gap-3 my-4">
                            <div class="flex-1 h-px bg-[#E8E0F0]"></div>
                            <span class="text-xs font-semibold text-[#999999] tracking-widest uppercase px-2 whitespace-nowrap">{{ $msg['date_label'] }}</span>
                            <div class="flex-1 h-px bg-[#E8E0F0]"></div>
                        </div>
                        @endif

                        <div wire:key="msg-{{ $msg['id'] }}" class="flex {{ $msg['is_mine'] ? 'justify-end' : 'justify-start' }} items-end gap-2 {{ $sameGroup ? 'mt-0.5' : 'mt-3' }}">

                            @if(! $msg['is_mine'])
                            <div class="w-8 h-8 rounded-full flex-shrink-0 overflow-hidden mb-1 self-end bg-[#7a3f91]" title="{{ $msg['sender_name'] }}">
                                <img src="{{ $msg['sender_photo'] ?? $defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="{{ $msg['sender_name'] }}">
                            </div>
                            @endif

                            <div class="flex flex-col {{ $msg['is_mine'] ? 'items-end' : 'items-start' }} max-w-[82%] sm:max-w-[70%]">

                                @if(! $msg['is_mine'] && ! $sameGroup)
                                <p class="text-xs font-semibold px-1 mb-0.5 text-[#7a3f91]">
                                    {{ $msg['sender_name'] }}
                                    @if($msg['is_coordinator'])
                                        <span class="ml-1 text-[10px] font-semibold bg-[#f3eef8] text-[#7a3f91] px-1.5 py-0.5 rounded">Coordinator</span>
                                    @elseif($roomType === 'college')
                                        @if($msg['sender_course'])
                                            <span class="ml-1 text-[10px] font-semibold bg-[#f3eef8] text-[#7a3f91] px-1.5 py-0.5 rounded">{{ strtoupper($msg['sender_course']) }}</span>
                                        @endif
                                        @if($msg['sender_batch'])
                                            <span class="ml-1 text-[10px] font-semibold bg-[#EDE0F5] text-[#5c2d7a] px-1.5 py-0.5 rounded">Batch {{ $msg['sender_batch'] }}</span>
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
                                <div class="text-sm rounded-lg px-2.5 py-1.5 mb-1 max-w-full border-l-[3px] leading-snug {{ $msg['is_mine'] ? 'bg-purple-200/60 border-white/70 text-purple-900' : 'bg-white border-[#E8E0F0] text-[#666666]' }}">
                                    <span class="font-semibold block truncate text-xs">{{ $msg['reply_to']['name'] }}</span>
                                    <span class="truncate block text-xs">{{ Str::limit($msg['reply_to']['body'], 70) }}</span>
                                </div>
                                @endif

                                <div class="relative">

                                    @if($editingId === $msg['id'])
                                    <div class="flex flex-col gap-1.5 min-w-[200px] sm:min-w-[220px]">
                                        <textarea wire:model="editBody" rows="2"
                                                  class="text-sm rounded-lg border border-[#7A3F91] px-3 py-2 resize-none focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 w-full bg-white shadow-sm"
                                                  wire:keydown.escape="cancelEdit"></textarea>
                                        <div class="flex gap-1.5 justify-end">
                                            <button wire:click="cancelEdit" class="text-xs px-3 py-1.5 rounded-lg border border-[#E8E0F0] text-[#666666] hover:bg-[#f5f5f5] transition-all duration-200 font-semibold">Cancel</button>
                                            <button wire:click="saveEdit" class="text-xs px-3 py-1.5 rounded-lg text-white font-semibold hover:opacity-90 transition-all duration-200 bg-[#7a3f91]">Save</button>
                                        </div>
                                    </div>

                                    @else

                                    @php
                                        $safe = htmlspecialchars($msg['body'], ENT_QUOTES, 'UTF-8');
                                        $mentionClass = $msg['is_mine']
                                            ? 'font-semibold text-yellow-200 bg-yellow-400/20 px-0.5 rounded'
                                            : 'font-semibold text-[#7a3f91] bg-[#f3eef8] px-0.5 rounded';
                                        $formatted = preg_replace('/@(everyone|\w+(?:\s\w+)?)/u', '<span class="'.$mentionClass.'">@$1</span>', $safe);
                                    @endphp

                                    <button
                                        wire:click.stop="toggleToolbar({{ $msg['id'] }})"
                                        class="msgr-bubble text-left px-3.5 py-2.5 rounded-2xl text-sm leading-relaxed break-words w-full
                                               {{ $msg['is_mine']
                                                   ? 'text-white rounded-br-none bg-[#7a3f91]'
                                                   : ($msg['is_coordinator']
                                                       ? 'text-white rounded-bl-none bg-[#7a3f91]'
                                                       : 'bg-white border border-[#E8E0F0] text-[#333333] rounded-bl-none') }}
                                               {{ $toolbarOpen ? 'ring-2 ring-[#7a3f91]/25' : '' }}">
                                        {!! $formatted !!}
                                        @if($msg['edited'])
                                            <span class="text-xs opacity-50 ml-1 italic">(edited)</span>
                                        @endif
                                    </button>

                                    @if($toolbarOpen)
                                    <div class="msgr-reaction-toolbar absolute bottom-full mb-2 {{ $msg['is_mine'] ? 'right-0' : 'left-0' }}
                                                flex items-center gap-0.5 bg-white rounded-2xl
                                                px-2 py-1.5 shadow-xl whitespace-nowrap animate-[msgrPop_.18s_ease-out]"
                                         x-data @click.stop>

                                        @foreach(['heart'=>'❤️','purple'=>'💜','like'=>'👍','dislike'=>'👎','happy'=>'😄','sad'=>'😢'] as $rk => $re)
                                        <div class="relative msgr-tooltip-wrap" x-data>
                                            <button wire:click.stop="react({{ $msg['id'] }}, '{{ $rk }}')"
                                                    class="w-9 h-9 flex items-center justify-center rounded-xl text-xl leading-none transition-all duration-150
                                                           hover:scale-125 active:scale-110
                                                           {{ $msg['my_reaction'] === $rk ? 'bg-[#f3eef8] ring-2 ring-[#7a3f91]' : 'hover:bg-[#f9f5fd]' }}">{{ $re }}</button>
                                            <span class="msgr-tooltip top-full left-1/2 -translate-x-1/2 mt-2 px-2.5 py-1.5 rounded-lg">
                                                {{ ucfirst($rk) }}
                                            </span>
                                        </div>
                                        @endforeach

                                        <span class="w-px h-5 bg-[#E8E0F0] mx-0.5 flex-shrink-0"></span>

                                        <div class="relative msgr-tooltip-wrap" x-data>
                                            <button wire:click.stop="setReply({{ $msg['id'] }})"
                                                    class="w-8 h-8 flex items-center justify-center rounded-xl text-[#555]
                                                           hover:bg-[#f3eef8] hover:text-[#7a3f91] transition-all duration-150">
                                                <i class="fa-solid fa-reply text-xs"></i>
                                            </button>
                                            <span class="msgr-tooltip top-full left-1/2 -translate-x-1/2 mt-2 px-2.5 py-1.5 rounded-lg">Reply</span>
                                        </div>

                                        <div class="relative msgr-tooltip-wrap" x-data>
                                            <button wire:click.stop="togglePin({{ $msg['id'] }})"
                                                    class="w-8 h-8 flex items-center justify-center rounded-xl transition-all duration-150
                                                           {{ $msg['is_pinned'] ? 'text-amber-600 bg-amber-50 hover:bg-amber-100' : 'text-[#555] hover:bg-amber-50 hover:text-amber-600' }}">
                                                <i class="fa-solid fa-thumbtack text-xs"></i>
                                            </button>
                                            <span class="msgr-tooltip top-full left-1/2 -translate-x-1/2 mt-2 px-2.5 py-1.5 rounded-lg">
                                                {{ $msg['is_pinned'] ? 'Unpin' : 'Pin' }}
                                            </span>
                                        </div>

                                        @if($msg['is_mine'])
                                        <span class="w-px h-5 bg-[#E8E0F0] mx-0.5 flex-shrink-0"></span>

                                        <div class="relative msgr-tooltip-wrap" x-data>
                                            <button wire:click.stop="startEdit({{ $msg['id'] }})"
                                                    class="w-8 h-8 flex items-center justify-center rounded-xl text-[#555]
                                                           hover:bg-[#f3eef8] hover:text-[#7a3f91] transition-all duration-150">
                                                <i class="fa-solid fa-pen text-xs"></i>
                                            </button>
                                            <span class="msgr-tooltip top-full left-1/2 -translate-x-1/2 mt-2 px-2.5 py-1.5 rounded-lg">Edit</span>
                                        </div>

                                        <div x-data="{ confirmUnsend: false }" class="relative msgr-tooltip-wrap flex items-center">
                                            <button x-show="!confirmUnsend"
                                                    @click.stop="confirmUnsend = true"
                                                    class="w-8 h-8 flex items-center justify-center rounded-xl text-[#555]
                                                           hover:bg-red-50 hover:text-red-600 transition-all duration-150">
                                                <i class="fa-solid fa-trash-can text-xs"></i>
                                            </button>
                                            <span x-show="!confirmUnsend" class="msgr-tooltip top-full left-1/2 -translate-x-1/2 mt-2 px-2.5 py-1.5 rounded-lg">Delete</span>
                                            <div x-show="confirmUnsend"
                                                 x-transition:enter="transition ease-out duration-150"
                                                 x-transition:enter-start="opacity-0 scale-90"
                                                 x-transition:enter-end="opacity-100 scale-100"
                                                 class="flex items-center gap-1" @click.stop>
                                                <span class="text-xs text-red-600 font-semibold px-1">Delete?</span>
                                                <button wire:click.stop="unsend({{ $msg['id'] }})"
                                                        class="text-xs px-2 py-1 rounded-lg bg-red-500 text-white font-semibold hover:bg-red-600 transition-all duration-150">Yes</button>
                                                <button @click.stop="confirmUnsend = false"
                                                        class="text-xs px-2 py-1 rounded-lg bg-[#f5f5f5] text-[#444] font-semibold hover:bg-[#E8E0F0] transition-all duration-150">No</button>
                                            </div>
                                        </div>
                                        @endif

                                    </div>
                                    @endif

                                    @endif

                                    @if($reactionsPopupMsgId === $msg['id'] && ! empty($reactionsPopupData))
                                    <div class="absolute bottom-full mb-2 {{ $msg['is_mine'] ? 'right-0' : 'left-0' }} z-[300]
                                                bg-white border border-[#D0C0E0] rounded-2xl shadow-xl w-64 max-w-[80vw] overflow-hidden animate-[msgrPop_.18s_ease-out]"
                                         wire:click.stop>
                                        <div class="flex items-center justify-between px-3.5 py-2.5 border-b border-[#E8E0F0] bg-[#f9f7fc]">
                                            <p class="text-xs font-semibold text-[#333333] uppercase tracking-widest">
                                                <i class="fa-solid fa-face-smile text-[#7a3f91] mr-1.5"></i>Reactions
                                            </p>
                                            <button wire:click="closeReactionsPopup"
                                                    class="w-6 h-6 flex items-center justify-center rounded-full text-[#999999] hover:text-[#333333] hover:bg-[#f5f5f5] transition-all duration-150">
                                                <i class="fa-solid fa-xmark text-xs"></i>
                                            </button>
                                        </div>
                                        <div class="max-h-52 overflow-y-auto">
                                            @php $emojiMap = ['heart'=>'❤️','purple'=>'💜','like'=>'👍','dislike'=>'👎','happy'=>'😄','sad'=>'😢']; @endphp
                                            @foreach($reactionsPopupData as $rKey => $rGroup)
                                            <div class="px-3.5 py-2 border-b border-[#E8E0F0] last:border-0">
                                                <div class="flex items-center gap-1.5 mb-1.5">
                                                    <span class="text-base">{{ $emojiMap[$rKey] ?? '👍' }}</span>
                                                    <span class="text-xs font-semibold text-[#666666]">{{ count($rGroup) }} {{ count($rGroup) === 1 ? 'person' : 'people' }}</span>
                                                </div>
                                                @foreach($rGroup as $reactor)
                                                <div class="flex items-center gap-2 py-1">
                                                    <div class="w-7 h-7 rounded-full flex-shrink-0 overflow-hidden bg-[#7a3f91]">
                                                        <img src="{{ $reactor['photo'] ?? $defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="">
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <p class="text-xs font-semibold text-[#333333] truncate">
                                                            {{ $reactor['name'] }}
                                                            @if($reactor['is_me'])<span class="text-[#7a3f91]"> (You)</span>@endif
                                                        </p>
                                                        <p class="text-[10px] font-medium text-[#7a3f91]">{{ ucfirst($reactor['type']) }}</p>
                                                    </div>
                                                </div>
                                                @endforeach
                                            </div>
                                            @endforeach
                                        </div>
                                    </div>
                                    @endif

                                </div>

                                @if(! empty($msg['reactions']))
                                <div class="flex gap-1 mt-1 flex-wrap {{ $msg['is_mine'] ? 'justify-end' : 'justify-start' }}">
                                    @foreach($msg['reactions'] as $rk => $cnt)
                                    @php $emoji = match($rk) { 'heart'=>'❤️','purple'=>'💜','like'=>'👍','dislike'=>'👎','happy'=>'😄','sad'=>'😢', default=>'👍' }; @endphp
                                    <button wire:click.stop="openReactionsPopup({{ $msg['id'] }})"
                                            class="inline-flex items-center gap-0.5 text-xs px-1.5 py-0.5 rounded-full border transition-all duration-150
                                                   {{ $msg['my_reaction'] === $rk
                                                       ? 'bg-[#f3eef8] border-[#c4a8d4] text-[#7a3f91] font-semibold ring-1 ring-[#7a3f91]/30'
                                                       : 'bg-white border-[#E8E0F0] text-[#555555] hover:border-[#d9c9e8] hover:bg-[#fdf9ff]' }}">
                                        {{ $emoji }}<span class="font-semibold ml-0.5">{{ $cnt }}</span>
                                    </button>
                                    @endforeach
                                </div>
                                @endif

                                <p class="text-xs text-[#999999] mt-0.5 px-1">{{ $msg['time'] }}</p>
                            </div>

                            @if($msg['is_mine'])
                            <div class="w-8 h-8 rounded-full flex-shrink-0 overflow-hidden mb-1 self-end bg-[#7a3f91]">
                                <img src="{{ $alumniPhoto ?: $defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="{{ $alumniFirstName }}">
                            </div>
                            @endif
                        </div>

                    @empty
                    <div class="flex flex-col items-center justify-center h-full py-20 text-[#999999] select-none">
                        <div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-4 bg-[#f3eef8]">
                            <i class="fa-solid {{ $roomType==='college' ? 'fa-school' : 'fa-comments' }} text-4xl text-[#7a3f91]"></i>
                        </div>
                        <p class="text-base font-semibold text-[#666666]">No messages yet</p>
                        <p class="text-sm text-[#999999] mt-1">{{ $roomType==='college' ? 'Start the '.$alumniCollege.' conversation! 👋' : 'Be the first to say hi to your batchmates! 👋' }}</p>
                    </div>
                    @endforelse

                    <div class="h-10"></div>
                </div>

                {{-- Typing indicator --}}
                <div class="flex-shrink-0">
                    @if(! empty($typingUsers))
                    <div class="flex items-center gap-2.5 px-4 py-2 bg-[#F4ECFB] border-t border-[#E8E0F0]">
                        <div class="flex items-end gap-0.5 h-4">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#7a3f91] animate-bounce" style="animation-delay:0ms;animation-duration:900ms;"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-[#7a3f91] animate-bounce" style="animation-delay:180ms;animation-duration:900ms;"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-[#7a3f91] animate-bounce" style="animation-delay:360ms;animation-duration:900ms;"></span>
                        </div>
                        <p class="text-xs text-[#666666] font-medium">
                            @php $visible = array_slice($typingUsers, 0, 3); $extra = count($typingUsers) - count($visible); @endphp
                            <span class="font-semibold text-[#7a3f91]">{{ implode(', ', $visible) }}{{ $extra > 0 ? " +{$extra}" : '' }}</span>
                            {{ count($typingUsers) === 1 ? 'is' : 'are' }} typing…
                        </p>
                    </div>
                    @endif
                </div>

                @if($replyTo)
                <div class="flex items-center gap-3 px-4 py-2.5 border-t border-[#E8E0F0] bg-[#f3eef8] flex-shrink-0 animate-[msgrPop_.18s_ease-out]">
                    <div class="w-1 h-10 rounded-full flex-shrink-0 bg-[#7a3f91]"></div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-[#7a3f91] truncate uppercase tracking-widest">Replying to {{ $replyTo['name'] }}</p>
                        <p class="text-xs text-[#666666] truncate">{{ Str::limit($replyTo['body'], 90) }}</p>
                    </div>
                    <button wire:click="clearReply" class="w-7 h-7 flex items-center justify-center rounded-full text-[#999999] hover:text-red-600 hover:bg-red-50 transition-all duration-150 flex-shrink-0">
                        <i class="fa-solid fa-xmark text-base"></i>
                    </button>
                </div>
                @endif

                <div class="px-3 sm:px-4 py-3 border-t border-[#E8E0F0] bg-white flex-shrink-0" x-data>
                    @if($showMentions && ! empty($mentionSuggestions))
                    <div class="mb-2 bg-white border border-[#E8E0F0] rounded-2xl shadow-md overflow-hidden animate-[msgrPop_.18s_ease-out]">
                        @foreach($mentionSuggestions as $sug)
                        <button wire:click="selectMention('{{ addslashes($sug['name']) }}')"
                                class="flex items-center gap-2.5 w-full px-3 py-2.5 hover:bg-[#f3eef8] transition-colors duration-150 text-left">
                            <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-black text-white overflow-hidden bg-[#7a3f91]">
                                @if($sug['name'] === 'everyone')
                                    <i class="fa-solid fa-users text-xs"></i>
                                @else
                                    {{ strtoupper(substr($sug['name'], 0, 1)) }}
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-[#333333] truncate">&#64;{{ $sug['name'] }}</p>
                                @if($sug['name'] === 'everyone')
                                    <p class="text-xs font-medium text-[#7a3f91]">Notify all members</p>
                                @elseif($sug['type'] === 'coordinator')
                                    <p class="text-xs font-medium text-[#7a3f91]">Coordinator</p>
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
                                placeholder="{{ $roomType==='college' ? 'Message '.$alumniCollege.'…' : 'Message '.($room['name']??'group').'…' }}"
                                rows="1"
                                @keydown.enter="if (!$event.shiftKey){$event.preventDefault();$wire.sendMessage();}"
                                @focus-input.window="$el.focus()"
                                x-init="$el.addEventListener('input',function(){this.style.height='auto';this.style.height=Math.min(this.scrollHeight,120)+'px';});"
                                class="w-full resize-none rounded-lg border border-[#E8E0F0] bg-[#fafafa] px-4 py-2.5 text-sm leading-relaxed text-[#333333] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/20 transition-all duration-150 placeholder-[#999999]"
                                style="max-height:120px;overflow-y:auto;"></textarea>
                        </div>
                        <button wire:click="sendMessage" wire:loading.attr="disabled" wire:target="sendMessage"
                                class="w-10 h-10 rounded-full flex items-center justify-center text-white flex-shrink-0 transition-all duration-150 hover:opacity-90 active:scale-90 shadow-sm bg-[#7a3f91] disabled:opacity-60">
                            <i class="fa-solid fa-paper-plane text-base" wire:loading.class="hidden" wire:target="sendMessage"></i>
                            <span class="hidden w-4 h-4" wire:loading.class.remove="hidden" wire:target="sendMessage">
                                <span class="block w-4 h-4 rounded-full border-2 border-white/40 border-t-white animate-spin"></span>
                            </span>
                        </button>
                    </div>
                </div>

            </div>

            {{-- ══ Members / Pins side panel ══
                 FIX: this is now a plain @if-controlled DOM node (no
                 Alpine x-show racing against the Blade class toggle). It
                 either exists, fully visible and interactive, or it
                 doesn't exist at all — so clicks reliably open/close it
                 every time. Slides over the chat on mobile (fixed overlay,
                 z-[150] so it's above the message list and header), sits
                 side-by-side as a static 288px column on desktop. ── --}}
            @if($showBatchmates || $showPins)
            <div wire:key="side-panel-{{ $showPins ? 'pins' : 'members' }}"
                 class="msgr-panel w-full md:w-72 flex flex-col flex-shrink-0 bg-white border-l border-[#E8E0F0]
                        fixed md:static inset-0 z-[150] md:z-auto"
                 style="animation: msgrPanelIn .2s ease-out;">
                <div class="flex items-center gap-2.5 px-4 py-3 border-b border-[#E8E0F0] flex-shrink-0 bg-[#F9F7FC]">
                    @if($showPins)
                        <i class="fa-solid fa-thumbtack text-amber-600"></i>
                        <p class="text-sm font-semibold text-[#333333] flex-1 uppercase tracking-wide">Pinned Messages</p>
                    @else
                        <i class="fa-solid {{ $roomType==='college' ? 'fa-school' : 'fa-user-group' }} text-[#7a3f91]"></i>
                        <p class="text-sm font-semibold text-[#333333] flex-1 uppercase tracking-wide">
                            Members <span class="text-xs font-semibold text-[#999999] ml-1">({{ count($batchmates) + count($coordinators) }})</span>
                            @if($onlineCount > 0)
                                <span class="ml-1 text-xs font-bold text-emerald-600">· {{ $onlineCount }} online</span>
                            @endif
                        </p>
                    @endif
                    <div class="relative msgr-tooltip-wrap">
                        <button type="button" wire:click="closeSidePanel"
                                class="w-7 h-7 flex items-center justify-center rounded-lg text-[#999999] hover:text-[#333333] hover:bg-[#f5f5f5] transition-all duration-150">
                            <i class="fa-solid fa-xmark text-sm"></i>
                        </button>
                        <span class="msgr-tooltip top-full right-0 mt-2 px-2.5 py-1.5 rounded-lg">Close</span>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto flex flex-col">
                    @if($showBatchmates)
                        @if($roomType === 'college')
                        <div class="px-4 py-2 bg-[#f3eef8] border-b border-[#E8E0F0] flex-shrink-0">
                            <p class="text-xs font-semibold text-[#7a3f91] flex items-center gap-1.5">
                                <i class="fa-solid fa-school text-xs"></i>All courses & batches — {{ $alumniCollege }}
                            </p>
                        </div>
                        @endif
                        <div class="px-3 py-2.5 border-b border-[#E8E0F0] flex-shrink-0">
                            <div class="relative" x-data="{ term: @entangle('batchSearch').live }">
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[#999999] text-xs pointer-events-none"
                                   wire:loading.class="opacity-0" wire:target="batchSearch"></i>
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 w-3 h-3 hidden"
                                      wire:loading.class.remove="hidden" wire:target="batchSearch">
                                    <span class="block w-3 h-3 rounded-full border-2 border-[#7a3f91]/30 border-t-[#7a3f91] animate-spin"></span>
                                </span>
                                <input type="text"
                                       x-model.debounce.300ms="term"
                                       placeholder="{{ $roomType==='college' ? 'Search all alumni…' : 'Search members…' }}"
                                       class="w-full pl-8 pr-3 py-2 text-sm rounded-lg border border-[#E8E0F0] bg-[#fafafa] focus:outline-none focus:border-[#7a3f91] focus:ring-1 focus:ring-[#7a3f91]/20 transition-all duration-150 placeholder-[#999999]"/>
                            </div>
                        </div>

                        @if(! empty($coordinators) && $batchSearch === '')
                        <div class="px-3 pt-3 pb-1 flex-shrink-0">
                            <p class="text-xs font-semibold uppercase tracking-widest mb-2 px-1 text-[#7A3F91]">
                                <i class="fa-solid fa-shield-halved text-xs mr-1"></i>Coordinators
                            </p>
                            @foreach($coordinators as $coord)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2 mb-1 border transition-all duration-150 {{ $coord['is_online'] ? 'bg-[#f0faf4] border-emerald-200' : 'bg-[#F9F7FC] border-[#E8E0F0]' }}">
                                <div class="relative flex-shrink-0">
                                    <div class="w-8 h-8 rounded-full overflow-hidden bg-[#7a3f91]">
                                        <img src="{{ $coord['photo'] ?? $defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="{{ $coord['name'] }}">
                                    </div>
                                    @if($coord['is_online'])
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-400 border-2 border-white"></span>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#333333] truncate">{{ $coord['name'] }}</p>
                                    <p class="text-xs font-medium {{ $coord['is_online'] ? 'text-emerald-600' : 'text-[#999999]' }}">
                                        {{ $coord['is_online'] ? 'Online · Coordinator' : $coord['last_seen_fmt'] . ' · Coordinator' }}
                                    </p>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        <div class="px-3 pb-1 flex-shrink-0">
                            <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest mb-2 px-1">
                                <i class="fa-solid fa-users text-xs mr-1"></i>{{ $roomType==='college' ? 'All Alumni' : 'Batchmates' }}
                            </p>
                        </div>
                        @endif

                        @php
                            $onlineBm  = collect($batchmates)->where('is_online', true)->values();
                            $offlineBm = collect($batchmates)->where('is_online', false)->values();
                        @endphp

                        <div class="flex-1 overflow-y-auto px-3 pb-3 space-y-1">
                            @if(count($onlineBm) > 0)
                            <p class="text-xs font-semibold text-emerald-600 uppercase tracking-widest px-1 pb-1 pt-0.5">
                                <i class="fa-solid fa-circle text-[9px] mr-1"></i>Online — {{ count($onlineBm) }}
                            </p>
                            @foreach($onlineBm as $bm)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 border border-[#E8E0F0] hover:border-[#d9c9e8] hover:bg-[#f3eef8] transition-all duration-150 {{ $bm['is_me'] ? 'bg-[#f3eef8] border-[#d9c9e8]' : '' }}">
                                <div class="relative flex-shrink-0">
                                    <div class="w-9 h-9 rounded-full overflow-hidden bg-[#7a3f91]">
                                        <img src="{{ $bm['photo'] ?? $defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="{{ $bm['name'] }}">
                                    </div>
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-400 border-2 border-white"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#333333] truncate">
                                        {{ $bm['name'] }}
                                        @if($bm['is_me'])<span class="text-xs font-medium text-[#7a3f91]"> (You)</span>@endif
                                    </p>
                                    <p class="text-xs font-medium text-emerald-600">
                                        Online
                                        @if($roomType === 'college')
                                            @if($bm['course_code'])<span class="text-[#7a3f91] ml-1">· {{ strtoupper($bm['course_code']) }}</span>@endif
                                            @if($bm['batch'])<span class="text-[#999999] ml-1">Batch {{ $bm['batch'] }}</span>@endif
                                        @endif
                                    </p>
                                </div>
                            </div>
                            @endforeach
                            @endif

                            @if(count($offlineBm) > 0)
                                @if(count($onlineBm) > 0 && $batchSearch === '')
                                <div class="pt-2.5 pb-1 px-1">
                                    <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest">
                                        <i class="fa-solid fa-circle text-[9px] mr-1 opacity-40"></i>Offline — {{ count($offlineBm) }}
                                    </p>
                                </div>
                                @endif
                                @foreach($offlineBm as $bm)
                                <div class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 border border-[#E8E0F0] hover:bg-[#fafafa] transition-all duration-150 {{ $bm['is_me'] ? 'bg-[#f3eef8] border-[#d9c9e8]' : '' }}">
                                    <div class="w-9 h-9 rounded-full flex-shrink-0 overflow-hidden bg-[#c8a0e0]">
                                        <img src="{{ $bm['photo'] ?? $defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="{{ $bm['name'] }}">
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs font-semibold text-[#666666] truncate">
                                            {{ $bm['name'] }}
                                            @if($bm['is_me'])<span class="text-xs font-medium text-[#7a3f91]"> (You)</span>@endif
                                        </p>
                                        <p class="text-xs font-medium text-[#999999]">
                                            {{ $bm['last_seen_fmt'] }}
                                            @if($roomType === 'college')
                                                @if($bm['course_code'])<span class="ml-1">· {{ strtoupper($bm['course_code']) }}</span>@endif
                                                @if($bm['batch'])<span class="ml-1">Batch {{ $bm['batch'] }}</span>@endif
                                            @endif
                                        </p>
                                    </div>
                                </div>
                                @endforeach
                            @endif

                            @if(empty($batchmates))
                            <div class="flex flex-col items-center justify-center py-10 text-[#999999]">
                                <i class="fa-solid fa-user-slash text-3xl text-[#E8E0F0] mb-2"></i>
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
                                <button wire:click="togglePin({{ $pin['id'] }})"
                                        class="w-5 h-5 flex items-center justify-center rounded-full text-[#999999] hover:text-red-600 hover:bg-red-50 transition-all duration-150 flex-shrink-0">
                                    <i class="fa-solid fa-xmark text-xs"></i>
                                </button>
                            </div>
                            <p class="text-sm text-[#333333] leading-snug break-words">{{ Str::limit($pin['body'], 140) }}</p>
                            <p class="text-xs text-[#999999] mt-1.5">{{ $pin['pinned_at'] }}</p>
                        </div>
                        @empty
                        <div class="flex flex-col items-center justify-center py-12 text-[#999999]">
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
    <div class="hidden md:flex flex-1 items-center justify-center bg-[#F4ECFB]">
        <div class="flex flex-col items-center text-center px-8">
            <div class="w-20 h-20 rounded-2xl flex items-center justify-center mb-5 bg-[#f3eef8]">
                <i class="fa-solid fa-hand-pointer text-4xl text-[#7a3f91]"></i>
            </div>
            <p class="text-lg font-semibold text-[#333333]">Click a message to view</p>
            <p class="text-sm text-[#999999] mt-2 max-w-xs leading-relaxed">
                Select a chat from the left to start messaging your batchmates or college group.
            </p>
            <div class="mt-5 flex items-center gap-2 text-xs text-[#999999]">
                <span class="w-5 h-5 flex items-center justify-center rounded-lg bg-[#f3eef8]"><i class="fa-solid fa-users text-[#7a3f91]" style="font-size:10px;"></i></span>
                <span>Batch chat</span>
                <span class="mx-2 text-[#E8E0F0]">·</span>
                <span class="w-5 h-5 flex items-center justify-center rounded-lg bg-[#f3eef8]"><i class="fa-solid fa-school text-[#7a3f91]" style="font-size:10px;"></i></span>
                <span>College chat</span>
            </div>
        </div>
    </div>
    @endif

</div>