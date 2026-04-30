{{-- resources/views/livewire/coordinator/messenger.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new class extends Component {

    // ── Rooms list ────────────────────────────────────────────────────────
    public array  $rooms          = [];
    public ?array $room           = null;
    public int    $roomId         = 0;

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
    public string $department           = '';

    // ── Course codes belonging to this department ─────────────────────────
    public array $deptCourseCodes = [];

    // ── Room filter + sort controls ───────────────────────────────────────
    public string $courseFilter     = '';
    public string $batchFilter      = '';
    public string $roomSort         = 'newest';
    public array  $availableCourses = [];
    public array  $availableBatches = [];

    // ── View Reactions popup ──────────────────────────────────────────────
    public ?int  $reactionsPopupMsgId = null;
    public array $reactionsPopupData  = [];

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

        $this->deptCourseCodes = DB::table('courses')
            ->where('college', $this->department)
            ->pluck('code')
            ->toArray();

        $this->ensureRoomsExist();
        $this->pingPresence();
        $this->loadRooms();

        if (! empty($this->rooms)) {
            $this->selectRoom($this->rooms[0]['id']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Auto-create missing rooms
    // ─────────────────────────────────────────────────────────────────────
    protected function ensureRoomsExist(): void
    {
        if (empty($this->deptCourseCodes)) return;

        try {
            $batches = DB::table('alumni')
                ->whereIn('course_code', $this->deptCourseCodes)
                ->whereNull('deleted_at')
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
                        'name'        => strtoupper($b->course_code) . ' · Batch ' . $b->batch,
                        'course_code' => $b->course_code,
                        'batch'       => (int) $b->batch,
                        'department'  => $this->department,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }
        } catch (\Throwable) {}
    }

    // ─────────────────────────────────────────────────────────────────────
    // Polling hooks
    // ─────────────────────────────────────────────────────────────────────
    public function refreshAll(): void
    {
        $this->pingPresence();
        $this->ensureRoomsExist();
        $this->loadRooms();

        if ($this->roomId) {
            $this->refreshOnlineCount();
            $this->loadMessages();
            $this->loadTypingIndicators();
        }
    }

    public function refreshTyping(): void
    {
        $this->loadTypingIndicators();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rooms list
    // ─────────────────────────────────────────────────────────────────────
    public function loadRooms(): void
    {
        $query = DB::table('chat_rooms as r')
            ->where(function ($q) {
                $q->where('r.department', $this->department);
                if (! empty($this->deptCourseCodes)) {
                    $q->orWhereIn('r.course_code', $this->deptCourseCodes);
                }
            });

        $rows = $query->get(['r.id', 'r.name', 'r.course_code', 'r.batch', 'r.department'])
            ->toArray();

        $allRooms = collect($rows)->map(function ($r) {
            $latest = DB::table('chat_messages as m')
                ->where('m.room_id', $r->id)
                ->whereNull('m.deleted_at')
                ->orderByDesc('m.created_at')
                ->first(['m.body', 'm.sender_type', 'm.sender_id', 'm.created_at']);

            $latestBody   = null;
            $latestSender = null;
            $latestTime   = null;
            $latestTs     = null;

            if ($latest) {
                $latestBody = $latest->body;
                $latestTs   = Carbon::parse($latest->created_at);
                $latestTime = $latestTs->setTimezone('Asia/Manila')->format('h:i A');

                // FIX: DB stores 'alumni' or 'organizer' — never 'coordinator'
                if ($latest->sender_type === 'alumni') {
                    $a            = DB::table('alumni')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = $a ?? 'Alumni';
                } else {
                    $o            = DB::table('organizer')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = $o ?? 'Coordinator';
                }
            }

            $onlineCount = DB::table('alumni')
                ->where('course_code', $r->course_code)
                ->where('batch', $r->batch)
                ->whereNull('deleted_at')
                ->where('last_seen_at', '>=', now()->subMinutes(5))
                ->count();

            $totalCount = DB::table('alumni')
                ->where('course_code', $r->course_code)
                ->where('batch', $r->batch)
                ->whereNull('deleted_at')
                ->count();

            return [
                'id'            => $r->id,
                'name'          => $r->name,
                'course_code'   => $r->course_code,
                'batch'         => (int) $r->batch,
                'department'    => $r->department ?? $this->department,
                'latest_body'   => $latestBody,
                'latest_sender' => $latestSender,
                'latest_time'   => $latestTime,
                'latest_ts'     => $latestTs ? $latestTs->timestamp : 0,
                'online_count'  => $onlineCount,
                'total_count'   => $totalCount,
                'is_active'     => $r->id === $this->roomId,
            ];
        });

        $this->availableCourses = $allRooms
            ->pluck('course_code')
            ->filter()
            ->map(fn ($c) => strtoupper($c))
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        $this->availableBatches = $allRooms
            ->pluck('batch')
            ->filter()
            ->unique()
            ->sortDesc()
            ->values()
            ->map(fn ($b) => (string) $b)
            ->toArray();

        if ($this->courseFilter !== '') {
            $allRooms = $allRooms->filter(
                fn ($r) => strtoupper($r['course_code']) === strtoupper($this->courseFilter)
            );
        }

        if ($this->batchFilter !== '') {
            $allRooms = $allRooms->filter(fn ($r) => (string) $r['batch'] === $this->batchFilter);
        }

        $allRooms = match ($this->roomSort) {
            'oldest' => $allRooms->sortBy('batch'),
            default  => $allRooms->sortByDesc('batch'),
        };

        $this->rooms = $allRooms->values()->toArray();
    }

    public function updatedCourseFilter(): void { $this->loadRooms(); }
    public function updatedBatchFilter(): void  { $this->loadRooms(); }
    public function updatedRoomSort(): void     { $this->loadRooms(); }

    public function selectRoom(int $id): void
    {
        $row = DB::table('chat_rooms')->find($id);
        if (! $row) return;

        $inDeptByColumn = $row->department === $this->department;
        $inDeptByCourse = in_array($row->course_code, $this->deptCourseCodes, true);
        if (! $inDeptByColumn && ! $inDeptByCourse) return;

        $this->roomId                = $row->id;
        $this->room                  = (array) $row;
        $this->body                  = '';
        $this->replyTo               = null;
        $this->editingId             = null;
        $this->editBody              = '';
        $this->showMembers           = false;
        $this->showPins              = false;
        $this->memberSearch          = '';
        $this->reactionsPopupMsgId   = null;
        $this->reactionsPopupData    = [];

        $this->refreshOnlineCount();
        $this->loadMessages();
        $this->loadAlumni();
        $this->loadCoordinators();
        $this->loadTypingIndicators();

        foreach ($this->rooms as &$r) {
            $r['is_active'] = $r['id'] === $id;
        }

        $this->dispatch('chat-scroll-bottom');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Presence
    // ─────────────────────────────────────────────────────────────────────
    public function pingPresence(): void
    {
        try {
            DB::table('organizer')
                ->where('id', $this->coordinatorId)
                ->update(['last_seen_at' => now()]);
        } catch (\Throwable) {}
    }

    public function refreshOnlineCount(): void
    {
        if (! $this->room) return;

        try {
            $base = DB::table('alumni')
                ->where('course_code', $this->room['course_code'])
                ->where('batch', $this->room['batch'])
                ->whereNull('deleted_at');

            $this->totalCount  = (clone $base)->count();
            $this->onlineCount = (clone $base)
                ->where('last_seen_at', '>=', now()->subMinutes(5))
                ->count();
        } catch (\Throwable) {
            $this->totalCount  = count($this->alumni);
            $this->onlineCount = 0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Typing indicator
    // chat_typing.sender_type is a plain string column (no ENUM),
    // so 'coordinator' is fine here — no change needed.
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
                    'sender_type' => 'coordinator',
                    'sender_id'   => $this->coordinatorId,
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
                } else {
                    $name = DB::table('organizer')->where('id', $row->sender_id)->value('first_name');
                    if ($name) $names[] = $name . ' (Coordinator)';
                }
            }

            $this->typingUsers = $names;
        } catch (\Throwable) {
            $this->typingUsers = [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Messages – Load
    // FIX: sender_type ENUM is 'organizer', not 'coordinator'
    // ─────────────────────────────────────────────────────────────────────
    public function loadMessages(): void
    {
        if (! $this->roomId) return;

        $rows = DB::table('chat_messages as m')
            ->where('m.room_id', $this->roomId)
            ->whereNull('m.deleted_at')
            ->orderBy('m.created_at')
            ->get([
                'm.id', 'm.sender_type', 'm.sender_id', 'm.body',
                'm.reply_to_id', 'm.edited_at', 'm.created_at',
            ])
            ->toArray();

        $aIds = collect($rows)->where('sender_type', 'alumni')->pluck('sender_id')->unique();
        $oIds = collect($rows)->where('sender_type', 'organizer')->pluck('sender_id')->unique(); // FIX

        $aMap = DB::table('alumni')
            ->whereIn('id', $aIds)
            ->get(['id', 'first_name', 'last_name', 'profile_photo'])
            ->keyBy(fn ($a) => (int) $a->id);

        $oMap = DB::table('organizer')
            ->whereIn('id', $oIds)
            ->get(['id', 'first_name', 'last_name', 'profile_photo'])
            ->keyBy(fn ($o) => (int) $o->id);

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
            ->whereNull('deleted_at')
            ->get(['id', 'sender_type', 'sender_id', 'body'])
            ->keyBy(fn ($m) => (int) $m->id);

        $this->messages = collect($rows)->map(function ($m) use ($aMap, $oMap, $rxns, $pins, $rplyMap) {

            // FIX: DB stores 'organizer', not 'coordinator'
            $isCoord = $m->sender_type === 'organizer';
            $sid     = (int) $m->sender_id;
            $s       = $isCoord ? $oMap->get($sid) : $aMap->get($sid);

            // Fallback to current coordinator name if own message not in map yet
            if (! $s && $isCoord && $sid === $this->coordinatorId) {
                $sName = $this->coordinatorName;
            } else {
                $sName = $s ? trim(($s->first_name ?? '') . ' ' . ($s->last_name ?? '')) : 'Unknown';
            }

            $msgRxns = $rxns->get($m->id, collect());
            $rxnGrps = $msgRxns->groupBy('reaction')->map(fn ($g) => $g->count())->toArray();

            // FIX: reactor_type ENUM is 'organizer', not 'coordinator'
            $myRxn = $msgRxns->first(
                fn ($r) => $r->reactor_type === 'organizer' && (int) $r->reactor_id === $this->coordinatorId
            );

            $reply = null;
            if ($m->reply_to_id && $rplyMap->has((int) $m->reply_to_id)) {
                $r  = $rplyMap->get((int) $m->reply_to_id);
                // FIX: check 'organizer'
                $rs = $r->sender_type === 'organizer'
                    ? $oMap->get((int) $r->sender_id)
                    : $aMap->get((int) $r->sender_id);

                $reply = [
                    'id'   => $r->id,
                    'body' => $r->body,
                    'name' => $rs
                        ? trim(($rs->first_name ?? '') . ' ' . ($rs->last_name ?? ''))
                        : 'Unknown',
                ];
            }

            $isMe         = $isCoord && $sid === $this->coordinatorId;
            $isOtherCoord = $isCoord && ! $isMe;

            return [
                'id'             => $m->id,
                'sender_type'    => $m->sender_type,
                'sender_id'      => $m->sender_id,
                'sender_name'    => $sName,
                'sender_photo'   => $s->profile_photo ?? null,
                'body'           => $m->body,
                'edited'         => ! is_null($m->edited_at),
                'is_mine'        => $isMe,
                'is_coordinator' => $isCoord,
                'is_other_coord' => $isOtherCoord,
                'is_pinned'      => isset($pins[$m->id]),
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
    // FIX: sender_type must be 'organizer' to match ENUM
    // ─────────────────────────────────────────────────────────────────────
    public function sendMessage(): void
    {
        $body = trim($this->body);
        if ($body === '' || ! $this->roomId) return;

        $msgId = DB::table('chat_messages')->insertGetId([
            'room_id'     => $this->roomId,
            'sender_type' => 'organizer',         // FIX: was 'coordinator'
            'sender_id'   => $this->coordinatorId,
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

                $foundAlumni = DB::table('alumni')
                    ->where('course_code', $this->room['course_code'])
                    ->where('batch', $this->room['batch'])
                    ->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$mention}%")
                    ->value('id');

                if ($foundAlumni) {
                    DB::table('chat_mentions')->insert([
                        'message_id'   => $msgId,
                        'mention_type' => 'alumni',
                        'mentioned_id' => $foundAlumni,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }

                $foundCoord = DB::table('organizer')
                    ->where('department', $this->department)
                    ->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$mention}%")
                    ->value('id');

                if ($foundCoord) {
                    DB::table('chat_mentions')->insert([
                        'message_id'   => $msgId,
                        'mention_type' => 'organizer',   // FIX: was 'coordinator'
                        'mentioned_id' => $foundCoord,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                }
            }
        }

        $this->body         = '';
        $this->replyTo      = null;
        $this->showMentions = false;

        $this->stopTyping();
        $this->loadMessages();
        $this->loadRooms();
        $this->dispatch('chat-scroll-bottom');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Messages – Edit
    // FIX: where clause must match 'organizer'
    // ─────────────────────────────────────────────────────────────────────
    public function startEdit(int $id): void
    {
        $msg = collect($this->messages)->firstWhere('id', $id);
        if (! $msg || ! $msg['is_mine']) return;

        $this->editingId = $id;
        $this->editBody  = $msg['body'];
    }

    public function saveEdit(): void
    {
        if (! $this->editingId || trim($this->editBody) === '') return;

        DB::table('chat_messages')
            ->where('id', $this->editingId)
            ->where('sender_type', 'organizer')   // FIX: was 'coordinator'
            ->where('sender_id', $this->coordinatorId)
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
    // Messages – Unsend
    // FIX: where clause must match 'organizer'
    // ─────────────────────────────────────────────────────────────────────
    public function unsend(int $id): void
    {
        DB::table('chat_messages')
            ->where('id', $id)
            ->where('sender_type', 'organizer')   // FIX: was 'coordinator'
            ->where('sender_id', $this->coordinatorId)
            ->update(['deleted_at' => now()]);

        DB::table('chat_pins')->where('message_id', $id)->delete();

        $this->loadMessages();
        $this->loadRooms();
        if ($this->showPins) $this->loadPins();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reactions
    // FIX: reactor_type ENUM is 'organizer' — 'coordinator' caused SQLSTATE 01000
    // ─────────────────────────────────────────────────────────────────────
    public function react(int $msgId, string $reaction): void
    {
        if (! in_array($reaction, ['heart', 'purple', 'like', 'dislike'], true)) return;

        $existing = DB::table('chat_reactions')
            ->where('message_id', $msgId)
            ->where('reactor_type', 'organizer')   // FIX: was 'coordinator'
            ->where('reactor_id', $this->coordinatorId)
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
                'reactor_type' => 'organizer',     // FIX: was 'coordinator'
                'reactor_id'   => $this->coordinatorId,
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
    // FIX: reactor_type check uses 'organizer'
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
            // FIX: ENUM stores 'organizer', not 'coordinator'
            if ($r->reactor_type === 'organizer') {
                $coord = DB::table('organizer')
                    ->where('id', $r->reactor_id)
                    ->first(['first_name', 'last_name', 'profile_photo']);
                $name  = $coord
                    ? trim(($coord->first_name ?? '') . ' ' . ($coord->last_name ?? ''))
                    : 'Unknown';
                $photo = $coord->profile_photo ?? null;
            } else {
                $al    = DB::table('alumni')
                    ->where('id', $r->reactor_id)
                    ->first(['first_name', 'last_name', 'profile_photo']);
                $name  = $al
                    ? trim(($al->first_name ?? '') . ' ' . ($al->last_name ?? ''))
                    : 'Unknown';
                $photo = $al->profile_photo ?? null;
            }

            $data[] = [
                'name'     => $name,
                'photo'    => $photo,
                'reaction' => $r->reaction,
                // FIX: check 'organizer'
                'type'     => $r->reactor_type === 'organizer' ? 'coordinator' : 'alumni', // display label
                'is_me'    => $r->reactor_type === 'organizer' && (int) $r->reactor_id === $this->coordinatorId,
            ];
        }

        $grouped = collect($data)->groupBy('reaction')->toArray();
        $this->reactionsPopupData = $grouped;
    }

    public function closeReactionsPopup(): void
    {
        $this->reactionsPopupMsgId = null;
        $this->reactionsPopupData  = [];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pin
    // FIX: pinned_by_type ENUM is 'organizer'
    // ─────────────────────────────────────────────────────────────────────
    public function togglePin(int $msgId): void
    {
        if (DB::table('chat_pins')->where('message_id', $msgId)->exists()) {
            DB::table('chat_pins')->where('message_id', $msgId)->delete();
        } else {
            DB::table('chat_pins')->insert([
                'room_id'        => $this->roomId,
                'message_id'     => $msgId,
                'pinned_by_type' => 'organizer',   // FIX: was 'coordinator'
                'pinned_by_id'   => $this->coordinatorId,
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
        if (! $msg) return;

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
        if ($this->showMembers) {
            $this->loadAlumni();
            $this->loadCoordinators();
        }
    }

    public function togglePins(): void
    {
        $this->showPins    = ! $this->showPins;
        $this->showMembers = false;
        if ($this->showPins) $this->loadPins();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Alumni in current room
    // ─────────────────────────────────────────────────────────────────────
    public function loadAlumni(): void
    {
        if (! $this->room) return;

        $q = trim($this->memberSearch);

        $query = DB::table('alumni')
            ->where('course_code', $this->room['course_code'])
            ->where('batch', $this->room['batch'])
            ->whereNull('deleted_at');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name',  'like', "%{$q}%")
                    ->orWhereRaw("CONCAT(first_name,' ',last_name) LIKE ?", ["%{$q}%"]);
            });
        }

        $this->alumni = $query
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'profile_photo', 'last_seen_at'])
            ->map(fn ($a) => [
                'id'        => $a->id,
                'name'      => trim($a->first_name . ' ' . $a->last_name),
                'photo'     => $a->profile_photo ?? null,
                'is_online' => isset($a->last_seen_at)
                                && Carbon::parse($a->last_seen_at)->gte(now()->subMinutes(5)),
            ])->toArray();
    }

    public function updatedMemberSearch(): void
    {
        $this->loadAlumni();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Other coordinators in same department
    // ─────────────────────────────────────────────────────────────────────
    public function loadCoordinators(): void
    {
        $this->coordinators = DB::table('organizer')
            ->where('department', $this->department)
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'profile_photo', 'last_seen_at'])
            ->map(fn ($o) => [
                'id'        => $o->id,
                'name'      => trim($o->first_name . ' ' . $o->last_name),
                'photo'     => $o->profile_photo ?? null,
                'is_me'     => (int) $o->id === $this->coordinatorId,
                'is_online' => isset($o->last_seen_at)
                                && Carbon::parse($o->last_seen_at)->gte(now()->subMinutes(5)),
            ])->toArray();
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

        $aIds = collect($rows)->where('sender_type', 'alumni')->pluck('sender_id')->unique();
        $oIds = collect($rows)->where('sender_type', 'organizer')->pluck('sender_id')->unique(); // FIX

        $aMap = DB::table('alumni')
            ->whereIn('id', $aIds)
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy(fn ($a) => (int) $a->id);

        $oMap = DB::table('organizer')
            ->whereIn('id', $oIds)
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy(fn ($o) => (int) $o->id);

        $this->pinnedMessages = collect($rows)->map(function ($p) use ($aMap, $oMap) {
            // FIX: check 'organizer'
            $s = $p->sender_type === 'organizer'
                ? $oMap->get((int) $p->sender_id)
                : $aMap->get((int) $p->sender_id);

            return [
                'id'        => $p->id,
                'body'      => $p->body,
                'from'      => $s ? trim($s->first_name . ' ' . $s->last_name) : 'Unknown',
                'pinned_at' => Carbon::parse($p->pinned_at)
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
            $q      = $m[1];
            $course = $this->room['course_code'] ?? '';
            $batch  = $this->room['batch']        ?? 0;

            $alumni = DB::table('alumni')
                ->where('course_code', $course)
                ->where('batch', $batch)
                ->whereNull('deleted_at')
                ->where(fn ($sub) => $sub
                    ->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name',  'like', "%{$q}%"))
                ->limit(5)
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn ($a) => [
                    'id'   => $a->id,
                    'name' => trim($a->first_name . ' ' . $a->last_name),
                    'type' => 'alumni',
                ])->toArray();

            $coordSuggestions = DB::table('organizer')
                ->where('department', $this->department)
                ->where('status', 'ACTIVE')
                ->whereNull('deleted_at')
                ->where(fn ($sub) => $sub
                    ->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name',  'like', "%{$q}%"))
                ->limit(3)
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn ($o) => [
                    'id'   => $o->id,
                    'name' => trim($o->first_name . ' ' . $o->last_name),
                    'type' => 'coordinator',
                ])->toArray();

            $this->mentionSuggestions = array_merge(
                [['id' => 0, 'name' => 'everyone', 'type' => 'everyone']],
                $alumni,
                $coordSuggestions
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
     COORDINATOR MESSENGER
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="flex rounded-2xl border border-[#E8E0F0] bg-white shadow-sm overflow-hidden"
     style="height: calc(100vh - 90px);"
     wire:poll.8000ms="refreshAll">

    {{-- ══════════════════════════════════════════════════════════════════
         LEFT SIDEBAR — Room / GC List
         ══════════════════════════════════════════════════════════════════ --}}
    <div class="w-80 flex-shrink-0 flex flex-col border-r border-[#E8E0F0] bg-white">

        {{-- Sidebar header --}}
        <div class="px-4 py-3.5 border-b border-[#E8E0F0] flex-shrink-0"
             style="background:#7a3f91;">
            <div class="flex items-center gap-2.5 mb-1">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center text-white font-semibold text-base flex-shrink-0"
                     style="background:rgba(255,255,255,.18); border:1.5px solid rgba(255,255,255,.28);">
                    {{ strtoupper(substr($coordinatorFirstName, 0, 1)) ?: 'C' }}
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

        {{-- ── Filter + Sort bar ──────────────────────────────────────── --}}
        <div class="px-3 pt-3 pb-2 border-b border-[#E8E0F0] flex-shrink-0 space-y-2.5 bg-[#fafafa]">

            {{-- Course Code filter --}}
            @if(count($availableCourses) > 0)
            <div>
                <label class="text-[10px] font-semibold text-[#999999] uppercase tracking-widest block mb-1.5">
                    <i class="fa-solid fa-book-open mr-1"></i>Course
                </label>
                <div class="flex flex-wrap gap-1.5">
                    <button wire:click="$set('courseFilter', '')"
                            class="text-xs font-semibold px-2.5 py-1.5 rounded-lg border transition-all
                                   {{ $courseFilter === ''
                                       ? 'border-[#d9c9e8] text-[#7a3f91]'
                                       : 'border-[#E8E0F0] text-[#666666] bg-white hover:border-[#d9c9e8] hover:text-[#7a3f91]' }}"
                            style="{{ $courseFilter === '' ? 'background:#f3eef8;' : '' }}">
                        All
                    </button>
                    @foreach($availableCourses as $code)
                    <button wire:click="$set('courseFilter', '{{ $code }}')"
                            class="text-xs font-semibold px-2.5 py-1.5 rounded-lg border transition-all
                                   {{ strtoupper($courseFilter) === $code
                                       ? 'border-[#d9c9e8] text-[#7a3f91]'
                                       : 'border-[#E8E0F0] text-[#666666] bg-white hover:border-[#d9c9e8] hover:text-[#7a3f91]' }}"
                            style="{{ strtoupper($courseFilter) === $code ? 'background:#f3eef8;' : '' }}">
                        {{ $code }}
                    </button>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Batch Year filter --}}
            @if(count($availableBatches) > 0)
            <div>
                <label class="text-[10px] font-semibold text-[#999999] uppercase tracking-widest block mb-1.5">
                    <i class="fa-solid fa-graduation-cap mr-1"></i>Batch Year
                </label>
                <div class="relative">
                    <select wire:model.live="batchFilter"
                            class="w-full appearance-none text-sm font-semibold text-[#333333] bg-white
                                   border border-[#E8E0F0] rounded-lg pl-3 pr-8 py-2
                                   focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/20
                                   transition cursor-pointer">
                        <option value="">All Batch Years</option>
                        @foreach($availableBatches as $batchYear)
                            <option value="{{ $batchYear }}">Batch {{ $batchYear }}</option>
                        @endforeach
                    </select>
                    <i class="fa-solid fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2
                               text-[#999999] text-xs pointer-events-none"></i>
                </div>
            </div>
            @endif

            {{-- Sort toggle --}}
            <div>
                <label class="text-[10px] font-semibold text-[#999999] uppercase tracking-widest block mb-1.5">
                    <i class="fa-solid fa-arrow-up-wide-short mr-1"></i>Sort
                </label>
                <div class="grid grid-cols-2 gap-1.5">
                    <button wire:click="$set('roomSort', 'newest')"
                            class="flex items-center justify-center gap-1.5 py-2 px-3 rounded-lg border
                                   text-xs font-semibold transition-all
                                   {{ $roomSort === 'newest'
                                       ? 'border-[#d9c9e8] text-[#7a3f91]'
                                       : 'border-[#E8E0F0] text-[#666666] bg-white hover:border-[#d9c9e8] hover:text-[#7a3f91]' }}"
                            style="{{ $roomSort === 'newest' ? 'background:#f3eef8;' : '' }}">
                        <i class="fa-solid fa-arrow-down-9-1 text-xs"></i>
                        Newest
                    </button>
                    <button wire:click="$set('roomSort', 'oldest')"
                            class="flex items-center justify-center gap-1.5 py-2 px-3 rounded-lg border
                                   text-xs font-semibold transition-all
                                   {{ $roomSort === 'oldest'
                                       ? 'border-[#d9c9e8] text-[#7a3f91]'
                                       : 'border-[#E8E0F0] text-[#666666] bg-white hover:border-[#d9c9e8] hover:text-[#7a3f91]' }}"
                            style="{{ $roomSort === 'oldest' ? 'background:#f3eef8;' : '' }}">
                        <i class="fa-solid fa-arrow-up-1-9 text-xs"></i>
                        Oldest
                    </button>
                </div>
            </div>

            {{-- Active filters indicator --}}
            @if($courseFilter !== '' || $batchFilter !== '')
            <div class="flex items-center justify-between pt-0.5">
                <div class="flex items-center gap-1.5 flex-wrap">
                    @if($courseFilter !== '')
                    <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-1 rounded-lg"
                          style="background:#f3eef8; color:#7a3f91;">
                        <i class="fa-solid fa-book-open text-[9px]"></i>
                        {{ strtoupper($courseFilter) }}
                    </span>
                    @endif
                    @if($batchFilter !== '')
                    <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-1 rounded-lg"
                          style="background:#f3eef8; color:#7a3f91;">
                        <i class="fa-solid fa-graduation-cap text-[9px]"></i>
                        Batch {{ $batchFilter }}
                    </span>
                    @endif
                </div>
                <button wire:click="$set('courseFilter', ''); $set('batchFilter', '');"
                        class="text-[10px] font-semibold text-red-400 hover:text-red-600 hover:underline transition whitespace-nowrap ml-2">
                    Clear all
                </button>
            </div>
            @endif
        </div>

        {{-- Room list label --}}
        <div class="px-4 pt-3 pb-1.5 flex-shrink-0 bg-white">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest">
                    <i class="fa-solid fa-comments mr-1"></i>Group Chats
                </p>
                <span class="text-xs font-semibold text-[#999999] bg-[#f5f5f5] px-2 py-0.5 rounded-full border border-[#E8E0F0]">
                    {{ count($rooms) }}
                </span>
            </div>
        </div>

        {{-- Room list --}}
        <div class="flex-1 overflow-y-auto px-2 pb-3 space-y-0.5 bg-white">
            @forelse($rooms as $r)
            <button wire:click="selectRoom({{ $r['id'] }})"
                    class="w-full text-left rounded-xl px-3 py-3 transition-all border
                           {{ $r['is_active']
                               ? 'border-[#d9c9e8]'
                               : 'border-transparent hover:border-[#E8E0F0] hover:bg-[#fafafa]' }}"
                    style="{{ $r['is_active'] ? 'background:#f3eef8;' : '' }}">

                <div class="flex items-start gap-2.5">
                    <div class="w-10 h-10 rounded-xl flex-shrink-0 flex items-center justify-center text-white text-sm"
                         style="{{ $r['is_active'] ? 'background:#7a3f91;' : 'background:#c4a8d4;' }}">
                        <i class="fa-solid fa-users"></i>
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-1">
                            <p class="text-sm font-semibold leading-tight truncate
                                      {{ $r['is_active'] ? 'text-[#7a3f91]' : 'text-[#333333]' }}">
                                {{ $r['name'] }}
                            </p>
                            @if($r['latest_time'])
                            <span class="text-xs text-[#999999] font-semibold flex-shrink-0 mt-0.5">
                                {{ $r['latest_time'] }}
                            </span>
                            @endif
                        </div>

                        <div class="flex items-center gap-1 flex-wrap mt-0.5 mb-0.5">
                            <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md"
                                  style="background:rgba(122,63,145,.10); color:#7a3f91;">
                                <i class="fa-solid fa-graduation-cap text-[9px] mr-0.5"></i>{{ $r['batch'] }}
                            </span>
                            <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md"
                                  style="background:rgba(122,63,145,.06); color:#9b60b2;">
                                {{ strtoupper($r['course_code']) }}
                            </span>
                        </div>

                        @if($r['latest_body'])
                        <p class="text-xs text-[#666666] truncate mt-0.5 leading-tight">
                            @if($r['latest_sender'])
                                <span class="font-semibold">{{ $r['latest_sender'] }}:</span>
                            @endif
                            {{ Str::limit($r['latest_body'], 38) }}
                        </p>
                        @else
                        <p class="text-xs text-[#999999] italic mt-0.5">No messages yet</p>
                        @endif

                        @if($r['online_count'] > 0)
                        <div class="flex items-center gap-1 mt-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block"></span>
                            <span class="text-xs font-semibold text-emerald-600">
                                {{ $r['online_count'] }}/{{ $r['total_count'] }} online
                            </span>
                        </div>
                        @else
                        <p class="text-xs text-[#999999] mt-1">{{ $r['total_count'] }} members</p>
                        @endif
                    </div>
                </div>
            </button>
            @empty
            <div class="flex flex-col items-center justify-center py-16 text-[#999999] text-center px-4">
                <i class="fa-solid fa-comments-slash text-3xl text-[#E8E0F0] mb-3"></i>
                <p class="text-sm font-semibold text-[#666666]">No group chats found</p>
                <p class="text-xs mt-1 text-[#999999] leading-snug">
                    @if($courseFilter !== '' || $batchFilter !== '')
                        No rooms match the selected filters.
                    @else
                        Rooms will appear once alumni with matching courses are added under
                        <span class="font-semibold text-[#7a3f91]">{{ $department }}</span>.
                    @endif
                </p>
                @if($courseFilter !== '' || $batchFilter !== '')
                <button wire:click="$set('courseFilter', ''); $set('batchFilter', '');"
                        class="mt-3 text-xs font-semibold text-[#7a3f91] hover:underline">
                    Clear filters
                </button>
                @endif
            </div>
            @endforelse
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════
         MAIN AREA
         ══════════════════════════════════════════════════════════════════ --}}
    @if($room)
    <div class="flex flex-1 min-w-0 flex-col">

        {{-- ── HEADER ──────────────────────────────────────────────────── --}}
        <div class="flex items-center gap-3 px-5 py-3.5 flex-shrink-0 border-b border-[#E8E0F0]"
             style="background:#7a3f91;">

            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                 style="background:rgba(255,255,255,.18); border:1.5px solid rgba(255,255,255,.28);">
                <i class="fa-solid fa-users text-white text-sm"></i>
            </div>

            <div class="flex-1 min-w-0">
                <p class="text-white font-semibold text-sm leading-tight truncate uppercase tracking-wide">
                    {{ $room['name'] ?? 'Group Chat' }}
                </p>
                <div class="flex items-center gap-2 flex-wrap mt-0.5">
                    @if($onlineCount > 0)
                    <div class="flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                        <span class="text-white/75 text-xs font-semibold">
                            {{ $onlineCount }}/{{ $totalCount }} online
                        </span>
                    </div>
                    <span class="text-white/30 text-xs">·</span>
                    @endif
                    <span class="text-white/60 text-xs font-semibold">
                        {{ strtoupper($room['course_code']) }} · Batch {{ $room['batch'] }}
                    </span>
                </div>
            </div>

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
                     class="flex-1 overflow-y-auto px-4 py-4 space-y-0.5 bg-[#fafafa]"
                     x-data
                     x-init="$nextTick(() => { $el.scrollTop = $el.scrollHeight; })"
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
                        <div class="flex items-center gap-3 my-4">
                            <div class="flex-1 h-px bg-[#E8E0F0]"></div>
                            <span class="text-xs font-semibold text-[#999999] tracking-widest uppercase px-2 whitespace-nowrap">
                                {{ $msg['date_label'] }}
                            </span>
                            <div class="flex-1 h-px bg-[#E8E0F0]"></div>
                        </div>
                        @endif

                        {{-- Message row --}}
                        <div class="flex {{ $msg['is_mine'] ? 'justify-end' : 'justify-start' }} items-end gap-2 {{ $sameGroup ? 'mt-0.5' : 'mt-3' }}"
                             x-data="{ open: false, confirmUnsend: false }"
                             @click.outside="open = false; confirmUnsend = false">

                            {{-- Avatar – others --}}
                            @if(! $msg['is_mine'])
                            <div class="w-7 h-7 rounded-full flex-shrink-0 flex items-center justify-center
                                        text-xs font-semibold text-white overflow-hidden mb-1 self-end"
                                 style="{{ $msg['is_coordinator'] ? 'background:#2563eb;' : 'background:#7a3f91;' }}"
                                 title="{{ $msg['sender_name'] }}">
                                @if($msg['sender_photo'])
                                    <img src="{{ asset('storage/' . $msg['sender_photo']) }}"
                                         class="w-full h-full object-cover" alt="">
                                @else
                                    {{ strtoupper(substr($msg['sender_name'], 0, 1)) }}
                                @endif
                            </div>
                            @endif

                            {{-- Bubble wrapper --}}
                            <div class="flex flex-col {{ $msg['is_mine'] ? 'items-end' : 'items-start' }} max-w-[78%] sm:max-w-[70%]">

                                {{-- Sender name --}}
                                @if(! $msg['is_mine'] && ! $sameGroup)
                                <p class="text-xs font-semibold px-1 mb-0.5
                                    {{ $msg['is_coordinator'] ? 'text-blue-600' : 'text-[#7a3f91]' }}">
                                    {{ $msg['sender_name'] }}
                                    @if($msg['is_coordinator'])
                                        <span class="ml-1 text-xs font-semibold bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded">
                                            Coordinator
                                        </span>
                                    @endif
                                </p>
                                @endif

                                {{-- Pinned indicator --}}
                                @if($msg['is_pinned'])
                                <div class="flex items-center gap-1 text-xs text-amber-600 font-semibold mb-0.5 px-1">
                                    <i class="fa-solid fa-thumbtack text-xs"></i> Pinned
                                </div>
                                @endif

                                {{-- Reply quote --}}
                                @if($msg['reply_to'])
                                <div class="text-sm rounded-lg px-2.5 py-1.5 mb-1 max-w-full border-l-[3px] leading-snug
                                    {{ $msg['is_mine']
                                        ? 'bg-purple-200/60 border-white/70 text-purple-900'
                                        : ($msg['is_coordinator']
                                            ? 'bg-blue-100/60 border-blue-400 text-blue-900'
                                            : 'bg-white border-[#E8E0F0] text-[#666666]') }}">
                                    <span class="font-semibold block truncate text-xs">{{ $msg['reply_to']['name'] }}</span>
                                    <span class="truncate block text-xs">{{ Str::limit($msg['reply_to']['body'], 70) }}</span>
                                </div>
                                @endif

                                {{-- Edit mode --}}
                                @if($editingId === $msg['id'])
                                <div class="flex flex-col gap-1.5 min-w-[220px]">
                                    <textarea wire:model="editBody"
                                              rows="2"
                                              class="text-sm rounded-lg border border-[#7a3f91] px-3 py-2 resize-none
                                                     focus:outline-none focus:ring-2 focus:ring-[#7a3f91]/30 w-full bg-white shadow-sm"
                                              wire:keydown.escape="cancelEdit"></textarea>
                                    <div class="flex gap-1.5 justify-end">
                                        <button wire:click="cancelEdit"
                                                class="text-xs px-3 py-1.5 rounded-lg border border-[#E8E0F0] text-[#666666]
                                                       hover:bg-[#f5f5f5] transition font-semibold">
                                            Cancel
                                        </button>
                                        <button wire:click="saveEdit"
                                                class="text-xs px-3 py-1.5 rounded-lg text-white font-semibold hover:opacity-90 transition"
                                                style="background:#7a3f91;">
                                            Save
                                        </button>
                                    </div>
                                </div>

                                {{-- Normal bubble --}}
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
                                <div @click.stop="open = !open; confirmUnsend = false"
                                     class="px-3.5 py-2.5 rounded-2xl text-sm leading-relaxed break-words
                                            shadow-sm cursor-pointer select-none transition-opacity active:opacity-80
                                            {{ $msg['is_mine']
                                                ? 'text-white rounded-br-none'
                                                : ($msg['is_coordinator']
                                                    ? 'text-white rounded-bl-none'
                                                    : 'bg-white border border-[#E8E0F0] text-[#333333] rounded-bl-none') }}"
                                     style="{{ $msg['is_mine'] ? 'background:#7a3f91;' : ($msg['is_coordinator'] ? 'background:#2563eb;' : '') }}">
                                    {!! $formatted !!}
                                    @if($msg['edited'])
                                        <span class="text-xs opacity-50 ml-1 italic">(edited)</span>
                                    @endif
                                </div>
                                @endif

                                {{-- ── Inline action bar ──────────────────────────────── --}}
                                <div x-show="open"
                                     x-transition:enter="transition ease-out duration-150"
                                     x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                                     x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                     x-cloak
                                     class="flex flex-wrap items-center gap-1.5 mt-2 bg-white border border-[#E8E0F0]
                                            rounded-2xl px-3 py-2 shadow-lg z-10 w-auto">

                                    @foreach(['heart' => '❤️', 'purple' => '💜', 'like' => '👍', 'dislike' => '👎'] as $rk => $re)
                                    <button wire:click="react({{ $msg['id'] }}, '{{ $rk }}')"
                                            @click.stop
                                            class="text-[1.3rem] leading-none transition-transform hover:scale-125 active:scale-110
                                                   {{ $msg['my_reaction'] === $rk ? 'opacity-100 scale-110' : 'opacity-50 hover:opacity-100' }}"
                                            title="{{ ucfirst($rk) }}">{{ $re }}</button>
                                    @endforeach

                                    <span class="w-px h-5 bg-[#E8E0F0] block"></span>

                                    <button wire:click="setReply({{ $msg['id'] }})"
                                            @click.stop="open = false"
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

                                    {{-- View Reactions button --}}
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
                                            @click.stop="open = false"
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
                                        <span class="text-xs text-red-600 font-semibold">Delete?</span>
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

                                {{-- ── View Reactions Popup ────────────────────────────── --}}
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
                                        @php
                                            $emojiMap = ['heart'=>'❤️','purple'=>'💜','like'=>'👍','dislike'=>'👎'];
                                        @endphp
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
                                                <div class="w-6 h-6 rounded-full flex-shrink-0 flex items-center justify-center
                                                            text-xs font-semibold text-white overflow-hidden"
                                                     style="{{ $reactor['type'] === 'coordinator' ? 'background:#2563eb;' : 'background:#7a3f91;' }}">
                                                    @if($reactor['photo'] ?? null)
                                                        <img src="{{ asset('storage/' . $reactor['photo']) }}"
                                                             class="w-full h-full object-cover" alt="">
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
                                                    <p class="text-[10px] font-medium
                                                        {{ $reactor['type'] === 'coordinator' ? 'text-blue-600' : 'text-[#999999]' }}">
                                                        {{ $reactor['type'] === 'coordinator' ? 'Coordinator' : 'Alumni' }}
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
                                @if(! empty($msg['reactions']))
                                <div class="flex gap-1 mt-1 flex-wrap {{ $msg['is_mine'] ? 'justify-end' : 'justify-start' }}">
                                    @foreach($msg['reactions'] as $rk => $cnt)
                                    @php $emoji = match($rk) { 'heart'=>'❤️','purple'=>'💜','like'=>'👍','dislike'=>'👎',default=>'👍' }; @endphp
                                    <button wire:click="react({{ $msg['id'] }}, '{{ $rk }}')"
                                            class="inline-flex items-center gap-0.5 text-xs px-1.5 py-0.5 rounded-full border transition-all
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

                            {{-- Avatar – mine --}}
                            @if($msg['is_mine'])
                            <div class="w-7 h-7 rounded-full flex-shrink-0 flex items-center justify-center
                                        text-xs font-semibold text-white overflow-hidden mb-1 self-end"
                                 style="background:#7a3f91;">
                                {{ strtoupper(substr($coordinatorFirstName, 0, 1)) ?: '?' }}
                            </div>
                            @endif
                        </div>

                    @empty
                        <div class="flex flex-col items-center justify-center h-full py-20 text-[#999999] select-none">
                            <div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-4"
                                 style="background:#f3eef8;">
                                <i class="fa-solid fa-comments text-4xl" style="color:#7a3f91;"></i>
                            </div>
                            <p class="text-base font-semibold text-[#666666]">No messages yet</p>
                            <p class="text-sm text-[#999999] mt-1">Start the conversation with this batch! 👋</p>
                        </div>
                    @endforelse
                </div>

                {{-- Typing indicator --}}
                <div wire:poll.3000ms="refreshTyping" class="flex-shrink-0">
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

                    {{-- @mention dropdown --}}
                    @if($showMentions && ! empty($mentionSuggestions))
                    <div class="mb-2 bg-white border border-[#E8E0F0] rounded-2xl shadow-md overflow-hidden">
                        @foreach($mentionSuggestions as $sug)
                        <button wire:click="selectMention('{{ addslashes($sug['name']) }}')"
                                class="flex items-center gap-2.5 w-full px-3 py-2.5 hover:bg-[#f3eef8] transition-colors text-left">
                            <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-semibold text-white"
                                 style="background:{{ $sug['type'] === 'coordinator' ? '#2563eb' : '#7a3f91' }};">
                                @if($sug['name'] === 'everyone')
                                    <i class="fa-solid fa-users text-xs"></i>
                                @else
                                    {{ strtoupper(substr($sug['name'], 0, 1)) }}
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-[#333333] truncate">&#64;{{ $sug['name'] }}</p>
                                @if($sug['name'] === 'everyone')
                                    <p class="text-xs text-[#7a3f91] font-medium">Notify all members</p>
                                @elseif($sug['type'] === 'coordinator')
                                    <p class="text-xs text-blue-600 font-medium">Alumni Coordinator</p>
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
                                placeholder="Message {{ $room['name'] ?? 'group' }}… (@ to mention)"
                                rows="1"
                                @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.sendMessage(); }"
                                @focus-input.window="$el.focus()"
                                x-init="
                                    $el.addEventListener('input', function () {
                                        this.style.height = 'auto';
                                        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
                                    });
                                "
                                class="w-full resize-none rounded-lg border border-[#E8E0F0] bg-[#fafafa]
                                       px-4 py-2.5 text-sm leading-relaxed text-[#333333]
                                       focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/20
                                       transition placeholder-[#999999]"
                                style="max-height:120px; overflow-y:auto;"></textarea>
                        </div>
                        <button wire:click="sendMessage"
                                class="w-10 h-10 rounded-full flex items-center justify-center text-white flex-shrink-0
                                       transition hover:opacity-90 active:scale-95 shadow-sm"
                                style="background:#7a3f91;">
                            <i class="fa-solid fa-paper-plane text-base"></i>
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

            {{-- ── SIDE PANEL ───────────────────────────────────────────── --}}
            @if($showMembers || $showPins)
            <div class="w-72 border-l border-[#E8E0F0] flex flex-col flex-shrink-0 bg-white">

                <div class="flex items-center gap-2.5 px-4 py-3 border-b border-[#E8E0F0] flex-shrink-0"
                     style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                    @if($showPins)
                        <i class="fa-solid fa-thumbtack text-amber-600"></i>
                        <p class="text-sm font-semibold text-[#333333] flex-1 uppercase tracking-wide">Pinned Messages</p>
                    @else
                        <i class="fa-solid fa-user-group text-[#7a3f91]"></i>
                        <p class="text-sm font-semibold text-[#333333] flex-1 uppercase tracking-wide">
                            Members
                            <span class="text-xs font-semibold text-[#999999] ml-1">({{ count($alumni) }})</span>
                            @if($onlineCount > 0)
                            <span class="ml-1 text-xs font-semibold text-emerald-600">· {{ $onlineCount }} online</span>
                            @endif
                        </p>
                    @endif
                    <button wire:click="{{ $showPins ? 'togglePins' : 'toggleMembers' }}"
                            class="w-7 h-7 flex items-center justify-center rounded-lg text-[#999999]
                                   hover:text-[#333333] hover:bg-[#f5f5f5] transition">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto flex flex-col">

                    @if($showMembers)

                        {{-- Coordinators section --}}
                        @if(! empty($coordinators) && $memberSearch === '')
                        <div class="px-3 pt-3 pb-1 flex-shrink-0">
                            <p class="text-xs font-semibold text-blue-600 uppercase tracking-widest mb-2 px-1">
                                <i class="fa-solid fa-shield-halved text-xs mr-1"></i>Coordinators
                            </p>
                            @foreach($coordinators as $coord)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2 mb-1 bg-blue-50 border border-blue-100">
                                <div class="relative flex-shrink-0">
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center
                                                text-xs font-semibold text-white overflow-hidden"
                                         style="background:#2563eb;">
                                        @if($coord['photo'])
                                            <img src="{{ asset('storage/' . $coord['photo']) }}"
                                                 class="w-full h-full object-cover" alt="">
                                        @else
                                            {{ strtoupper(substr($coord['name'], 0, 1)) }}
                                        @endif
                                    </div>
                                    @if($coord['is_online'] || $coord['is_me'])
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full
                                                 bg-emerald-400 border-2 border-white"></span>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#333333] truncate">
                                        {{ $coord['name'] }}
                                        @if($coord['is_me'])
                                            <span class="text-xs text-blue-500 font-semibold">(You)</span>
                                        @endif
                                    </p>
                                    <p class="text-xs text-blue-600 font-medium">
                                        {{ ($coord['is_online'] || $coord['is_me']) ? '🟢 Online' : 'Coordinator' }}
                                    </p>
                                </div>
                            </div>
                            @endforeach
                        </div>

                        <div class="px-3 pb-1 flex-shrink-0">
                            <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest mb-2 px-1">
                                <i class="fa-solid fa-users text-xs mr-1"></i>Alumni
                            </p>
                        </div>
                        @endif

                        {{-- Member search --}}
                        <div class="px-3 py-2.5 border-b border-[#E8E0F0] flex-shrink-0">
                            <div class="relative">
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2
                                          text-[#999999] text-xs pointer-events-none"></i>
                                <input wire:model.live.debounce.300ms="memberSearch"
                                       type="text"
                                       placeholder="Search alumni…"
                                       class="w-full pl-8 pr-3 py-2 text-sm rounded-lg border border-[#E8E0F0]
                                              bg-[#fafafa] focus:outline-none focus:border-[#7a3f91]
                                              focus:ring-1 focus:ring-[#7a3f91]/20 transition placeholder-[#999999]"/>
                            </div>
                        </div>

                        <div class="flex-1 overflow-y-auto px-3 pb-3 space-y-1 pt-2.5">
                            @php
                                $onlineAlumni  = collect($alumni)->where('is_online', true)->values();
                                $offlineAlumni = collect($alumni)->where('is_online', false)->values();
                            @endphp

                            @if(count($onlineAlumni) > 0)
                            <p class="text-xs font-semibold text-emerald-600 uppercase tracking-widest px-1 pb-1">
                                <i class="fa-solid fa-circle text-[9px] mr-1"></i>Online — {{ count($onlineAlumni) }}
                            </p>
                            @foreach($onlineAlumni as $al)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 border border-[#E8E0F0]
                                        hover:border-[#d9c9e8] hover:bg-[#f3eef8] transition-all">
                                <div class="relative flex-shrink-0">
                                    <div class="w-9 h-9 rounded-full flex items-center justify-center
                                                text-xs font-semibold text-white overflow-hidden"
                                         style="background:#7a3f91;">
                                        @if($al['photo'])
                                            <img src="{{ asset('storage/' . $al['photo']) }}"
                                                 class="w-full h-full object-cover" alt="">
                                        @else
                                            {{ strtoupper(substr($al['name'], 0, 1)) }}
                                        @endif
                                    </div>
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full
                                                 bg-emerald-400 border-2 border-white"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#333333] truncate">{{ $al['name'] }}</p>
                                    <p class="text-xs font-medium text-emerald-600">Online</p>
                                </div>
                            </div>
                            @endforeach
                            @endif

                            @if(count($offlineAlumni) > 0 && count($onlineAlumni) > 0 && $memberSearch === '')
                            <div class="pt-2.5 pb-1 px-1">
                                <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest">
                                    <i class="fa-solid fa-circle text-[9px] mr-1 opacity-40"></i>Offline — {{ count($offlineAlumni) }}
                                </p>
                            </div>
                            @endif

                            @foreach($offlineAlumni as $al)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 border border-[#E8E0F0]
                                        hover:border-[#E8E0F0] hover:bg-[#fafafa] transition-all opacity-70">
                                <div class="w-9 h-9 rounded-full flex-shrink-0 flex items-center justify-center
                                            text-xs font-semibold text-white overflow-hidden"
                                     style="background:#c4a8d4;">
                                    @if($al['photo'])
                                        <img src="{{ asset('storage/' . $al['photo']) }}"
                                             class="w-full h-full object-cover" alt="">
                                    @else
                                        {{ strtoupper(substr($al['name'], 0, 1)) }}
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#666666] truncate">{{ $al['name'] }}</p>
                                    <p class="text-xs font-medium text-[#999999]">Offline</p>
                                </div>
                            </div>
                            @endforeach

                            @if(empty($alumni))
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

    {{-- ══════════════════════════════════════════════════════════════════
         EMPTY STATE — No room selected
         ══════════════════════════════════════════════════════════════════ --}}
    @else
    <div class="flex flex-1 items-center justify-center bg-[#fafafa]">
        <div class="flex flex-col items-center text-center px-8">
            <div class="w-20 h-20 rounded-2xl flex items-center justify-center mb-5"
                 style="background:#f3eef8;">
                <i class="fa-solid fa-comments text-5xl" style="color:#7a3f91;"></i>
            </div>
            <p class="text-lg font-semibold text-[#333333]">Select a Group Chat</p>
            <p class="text-sm text-[#999999] mt-2 max-w-xs leading-relaxed">
                Choose a batch group chat from the left panel to start messaging your alumni.
            </p>
        </div>
    </div>
    @endif

</div>