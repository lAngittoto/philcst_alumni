{{-- resources/views/livewire/alumni/messenger.blade.php --}}

<?php

use Livewire\Volt\Component;
use App\Models\Alumni;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

new class extends Component {

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

    // ── Tracks last seen message IDs per room (for read/unread UI) ────────────
    public array $lastSeenMessageIds = [];

    // ── Tracks last NOTIFIED message IDs per room (for bell notifications) ───
    // Completely separate from lastSeenMessageIds.
    // Only advanced by: sendMessage() and checkAndDispatchNewMessageNotifications().
    // NOT advanced by selectRoom() — so notifications fire even for the active room.
    public array $lastNotifiedMessageIds = [];

    private function lastReadCacheKey(int $roomId): string
    {
        return "chat_read.alumni.{$this->alumniId}.room.{$roomId}";
    }

    private function getLastReadAt(int $roomId): ?string
    {
        return Cache::get($this->lastReadCacheKey($roomId));
    }

    public function markRoomAsRead(int $roomId): void
    {
        Cache::put($this->lastReadCacheKey($roomId), now()->toDateTimeString(), now()->addDays(30));
    }

    private function getUnreadCount(int $roomId): int
    {
        $lastRead = $this->getLastReadAt($roomId);
        $query = DB::table('chat_messages')
            ->where('room_id', $roomId)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('sender_type', '!=', 'alumni')
                  ->orWhere('sender_id', '!=', $this->alumniId);
            });
        if ($lastRead) {
            $query->where('created_at', '>', $lastRead);
        }
        return $query->count();
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

    private function formatLastSeen(?string $lastSeenAt): string
    {
        if (! $lastSeenAt) return 'Offline';
        $ts  = Carbon::parse($lastSeenAt)->setTimezone('Asia/Manila');
        $now = Carbon::now('Asia/Manila');
        $diff = $now->diffInSeconds($ts);
        if ($diff < 60)                 return 'Online';
        if ($diff < 3600)               return 'Active ' . floor($diff / 60) . 'm ago';
        if ($ts->isToday())             return 'Active today at ' . $ts->format('h:i A');
        if ($ts->isYesterday())         return 'Active yesterday';
        if ($now->diffInDays($ts) < 7) return 'Active ' . $now->diffInDays($ts) . 'd ago';
        return 'Active ' . $ts->format('M d');
    }

    private function isOnline(?string $lastSeenAt): bool
    {
        if (! $lastSeenAt) return false;
        return Carbon::parse($lastSeenAt)->gte(Carbon::now()->subMinutes(5));
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

        $this->ensureRoomsExist();
        $this->pingPresence();
        $this->loadRooms();
        $this->seedLastSeenMessageIds();

        $batchRoom = collect($this->rooms)->firstWhere('type', 'batch');
        if ($batchRoom) $this->selectRoom($batchRoom['id']);
        elseif (! empty($this->rooms)) $this->selectRoom($this->rooms[0]['id']);
    }

    private function seedLastSeenMessageIds(): void
    {
        foreach ($this->rooms as $r) {
            $maxId = DB::table('chat_messages')
                ->where('room_id', $r['id'])
                ->whereNull('deleted_at')
                ->max('id') ?? 0;
            $this->lastSeenMessageIds[$r['id']]     = (int) $maxId;
            // Initialize lastNotifiedMessageIds to current max so we only
            // notify about messages that arrive AFTER the page loads.
            $this->lastNotifiedMessageIds[$r['id']] = (int) $maxId;
        }
    }

    protected function ensureRoomsExist(): void
    {
        $college = $this->alumniCollege;
        $batchExists = DB::table('chat_rooms')
            ->where('course_code', $this->alumniCourse)
            ->where('batch', (int) $this->alumniBatch)
            ->exists();
        if (! $batchExists) {
            DB::table('chat_rooms')->insert([
                'name'        => strtoupper($this->alumniCourse) . ' · Batch ' . $this->alumniBatch,
                'course_code' => $this->alumniCourse,
                'batch'       => (int) $this->alumniBatch,
                'department'  => $college,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
        if ($college) {
            $collegeExists = DB::table('chat_rooms')
                ->where('department', $college)
                ->where('course_code', '')
                ->where('batch', 0)
                ->exists();
            if (! $collegeExists) {
                DB::table('chat_rooms')->insert([
                    'name'        => $college . ' · All Courses & Batches',
                    'course_code' => '',
                    'batch'       => 0,
                    'department'  => $college,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }
        }
    }

    private function isCollegeRoom($row): bool
    {
        return ($row->course_code ?? '') === '' && (int)($row->batch ?? 0) === 0;
    }

    public function loadRooms(): void
    {
        $college = $this->alumniCollege;
        $rows = DB::table('chat_rooms')
            ->where(function ($q) use ($college) {
                $q->where(function ($sub) {
                    $sub->where('course_code', $this->alumniCourse)
                        ->where('batch', (int) $this->alumniBatch);
                });
                if ($college) {
                    $q->orWhere(function ($sub) use ($college) {
                        $sub->where('department', $college)
                            ->where('course_code', '')
                            ->where('batch', 0);
                    });
                }
            })
            ->get()->toArray();

        $self = $this;
        $this->rooms = collect($rows)->map(function ($r) use ($self, $college) {
            $isCollege = $self->isCollegeRoom($r);
            $latest = DB::table('chat_messages as m')
                ->where('m.room_id', $r->id)
                ->whereNull('m.deleted_at')
                ->orderByDesc('m.created_at')
                ->first(['m.body', 'm.sender_type', 'm.sender_id', 'm.created_at']);

            $latestBody = $latestSender = $latestTime = null;
            if ($latest) {
                $latestBody = $latest->body;
                $latestTime = Carbon::parse($latest->created_at)->setTimezone('Asia/Manila')->format('h:i A');
                if ($latest->sender_type === 'alumni') {
                    $name = DB::table('alumni')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = $name ?? 'Alumni';
                } else {
                    $name = DB::table('organizer')->where('id', $latest->sender_id)->value('first_name');
                    $latestSender = ($name ?? 'Coordinator') . ' (Coordinator)';
                }
            }

            if ($isCollege && $college) {
                $collegeCourses = DB::table('courses')->where('college', $college)->pluck('code');
                $baseQ = DB::table('alumni')->whereIn('course_code', $collegeCourses)->whereNull('deleted_at');
            } else {
                $baseQ = DB::table('alumni')
                    ->where('course_code', $r->course_code)
                    ->where('batch', $r->batch)
                    ->whereNull('deleted_at');
            }
            $total  = (clone $baseQ)->count();
            $online = (clone $baseQ)->where('last_seen_at', '>=', now()->subMinutes(5))->count();

            return [
                'id'            => $r->id,
                'name'          => $r->name,
                'course_code'   => $r->course_code,
                'batch'         => (int) $r->batch,
                'department'    => $r->department,
                'type'          => $isCollege ? 'college' : 'batch',
                'latest_body'   => $latestBody,
                'latest_sender' => $latestSender,
                'latest_time'   => $latestTime,
                'total_count'   => $total,
                'online_count'  => $online,
                'is_active'     => $r->id === $self->roomId,
            ];
        })
        ->sortByDesc(fn ($r) => $r['type'] === 'college' ? 1 : 0)
        ->values()->toArray();
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

        foreach ($this->rooms as &$r) {
            $r['is_active'] = $r['id'] === $id;
        }

        $maxId = DB::table('chat_messages')
            ->where('room_id', $id)
            ->whereNull('deleted_at')
            ->max('id') ?? 0;

        // Only advance lastSeenMessageIds (for unread badge UI).
        // Do NOT touch lastNotifiedMessageIds here — that would suppress
        // bell notifications for messages arriving while in this room.
        $this->lastSeenMessageIds[$id] = (int) $maxId;

        $this->markRoomAsRead($id);
        $this->refreshOnlineCount();
        $this->loadMessages();
        $this->loadBatchmates();
        $this->loadCoordinators();
        $this->loadTypingIndicators();
        $this->dispatch('chat-scroll-bottom');
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

    public function refreshAll(): void
    {
        $this->pingPresence();
        $this->refreshOnlineCount();
        $this->loadRooms();
        $this->loadMessages();
        $this->loadTypingIndicators();
        if ($this->roomId) $this->markRoomAsRead($this->roomId);

        $this->checkAndDispatchNewMessageNotifications();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BUG FIX: Check ALL rooms INCLUDING the currently active room for new
    // messages from others, then dispatch bell notifications.
    //
    // Key changes vs original:
    //   1. We do NOT skip the active room — coordinator messages in your
    //      current chat should still trigger the bell.
    //   2. lastNotifiedMessageIds is NEVER reset by selectRoom(), so the
    //      pointer only moves forward here and in sendMessage().
    //   3. lastSeenMessageIds for the active room is still advanced (no
    //      unread badge), but that does not affect notifications.
    // ─────────────────────────────────────────────────────────────────────────
    private function checkAndDispatchNewMessageNotifications(): void
    {
        foreach ($this->rooms as $room) {
            $roomId = (int) $room['id'];

            // Ensure the notification pointer is initialized for this room.
            if (! isset($this->lastNotifiedMessageIds[$roomId])) {
                $this->lastNotifiedMessageIds[$roomId] = (int) (
                    DB::table('chat_messages')
                        ->where('room_id', $roomId)
                        ->whereNull('deleted_at')
                        ->max('id') ?? 0
                );
                // Nothing new to notify on first initialization.
                continue;
            }

            $lastKnown = (int) $this->lastNotifiedMessageIds[$roomId];

            // Fetch new messages from others that arrived after our last check.
            // This runs for ALL rooms, including the currently open one.
            $newMessages = DB::table('chat_messages as m')
                ->where('m.room_id', $roomId)
                ->whereNull('m.deleted_at')
                ->where('m.id', '>', $lastKnown)
                ->where(function ($q) {
                    // Exclude messages sent by this alumni themselves.
                    $q->where('m.sender_type', '!=', 'alumni')
                      ->orWhere('m.sender_id', '!=', $this->alumniId);
                })
                ->orderBy('m.id')
                ->get(['m.id', 'm.sender_type', 'm.sender_id', 'm.body'])
                ->toArray();

            if (empty($newMessages)) continue;

            // Advance the notification pointer BEFORE dispatching so a second
            // rapid poll doesn't re-fire the same notification.
            $newMaxId = max(array_column($newMessages, 'id'));
            $this->lastNotifiedMessageIds[$roomId] = (int) $newMaxId;

            // Also advance lastSeenMessageIds for the CURRENT active room
            // (user is looking at it, so mark as seen too).
            if ($roomId === $this->roomId) {
                $this->lastSeenMessageIds[$roomId] = (int) $newMaxId;
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

            $roomName = $room['name'] ?? 'Group Chat';
            $bodySnip = mb_substr($latest->body ?? '', 0, 60);
            $count    = count($newMessages);

            // Dispatch with named params → Livewire v3 puts them directly in
            // e.detail (plain object), not wrapped in an array.
            $this->dispatch('message-received',
                sender: $senderName,
                room:   $roomName,
                body:   $bodySnip,
                count:  $count,
            );
        }
    }

    public function refreshTyping(): void { $this->loadTypingIndicators(); }

    public function pingPresence(): void
    {
        try { DB::table('alumni')->where('id', $this->alumniId)->update(['last_seen_at' => now()]); } catch (\Throwable) {}
    }

    public function refreshOnlineCount(): void
    {
        if (! $this->room) return;
        try {
            if ($this->roomType === 'college' && $this->alumniCollege) {
                $collegeCourses = DB::table('courses')->where('college', $this->alumniCollege)->pluck('code');
                $base = DB::table('alumni')->whereIn('course_code', $collegeCourses)->whereNull('deleted_at');
            } else {
                $base = DB::table('alumni')
                    ->where('course_code', $this->room['course_code'])
                    ->where('batch', $this->room['batch'])
                    ->whereNull('deleted_at');
            }
            $this->totalCount  = (clone $base)->count();
            $this->onlineCount = (clone $base)->where('last_seen_at', '>=', now()->subMinutes(5))->count();
        } catch (\Throwable) {
            $this->totalCount  = count($this->batchmates);
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

        // Advance BOTH pointers for the alumni's own sent message so we
        // never generate a "New Message" notification for our own sends.
        $this->lastSeenMessageIds[$this->roomId]     = (int) $msgId;
        $this->lastNotifiedMessageIds[$this->roomId] = (int) $msgId;

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

    public function toggleBatchmates(): void
    {
        $this->showBatchmates = ! $this->showBatchmates;
        $this->showPins = false; $this->batchSearch = '';
        if ($this->showBatchmates) $this->loadBatchmates();
    }

    public function togglePins(): void
    {
        $this->showPins = ! $this->showPins;
        $this->showBatchmates = false;
        if ($this->showPins) $this->loadPins();
    }

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
            ->get(['id','first_name','last_name','profile_photo','department'])
            ->map(fn ($o) => ['id'=>$o->id,'name'=>trim($o->first_name.' '.$o->last_name),'photo'=>$self->resolvePhotoUrl($o->profile_photo??null),'dept'=>$o->department])
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

<div class="flex rounded-2xl border border-[#E8E0F0] bg-white shadow-sm overflow-hidden"
     style="height: calc(100vh - 90px);"
     wire:poll.8000ms="refreshAll">

    @php $defaultAv = asset('storage/alumni-photos/default.png'); @endphp

    {{-- ══ LEFT SIDEBAR ══ --}}
    <div class="w-72 flex-shrink-0 flex flex-col border-r border-[#E8E0F0] bg-white">
        <div class="px-4 py-3.5 border-b border-[#5c2778] flex-shrink-0 bg-[#7A3F91]">
            <div class="flex items-center gap-2.5 mb-1">
                <div class="w-9 h-9 rounded-xl flex-shrink-0 overflow-hidden ring-2 ring-white/30 bg-white/18">
                    <img src="{{ $alumniPhoto ?: $defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="{{ $alumniFirstName }}">
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-white font-semibold text-sm leading-tight truncate">{{ $alumniName }}</p>
                    <div class="flex items-center gap-1 mt-0.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                        <span class="text-xs text-white/70 font-semibold">Online · Alumni</span>
                    </div>
                </div>
            </div>
            <p class="text-xs text-white/50 font-semibold truncate mt-0.5">
                <i class="fa-solid fa-graduation-cap mr-1"></i>{{ strtoupper($alumniCourse) }} · Batch {{ $alumniBatch }}
            </p>
            @if($alumniCollege)
            <p class="text-xs text-white/40 font-semibold truncate mt-0.5">
                <i class="fa-solid fa-school mr-1"></i>{{ $alumniCollege }}
            </p>
            @endif
        </div>

        <div class="px-4 pt-3 pb-1.5 flex-shrink-0 bg-white border-b border-[#E8E0F0]">
            <p class="text-xs font-semibold text-[#999999] uppercase tracking-widest flex items-center gap-1.5">
                <i class="fa-solid fa-comments"></i> My Chats
                <span class="text-xs font-semibold text-[#999999] bg-[#f5f5f5] px-2 py-0.5 rounded-full border border-[#E8E0F0] ml-auto">{{ count($rooms) }}</span>
            </p>
        </div>

        <div class="flex-1 overflow-y-auto px-2 py-2 space-y-1 bg-white">
            @forelse($rooms as $r)
            <button wire:click="selectRoom({{ $r['id'] }})"
                    class="w-full text-left rounded-xl px-3 py-3 transition-all border
                           {{ $r['is_active']
                               ? 'border-[#d9c9e8] bg-[#f3eef8]'
                               : 'border-transparent hover:border-[#E8E0F0] hover:bg-[#fafafa]' }}">
                <div class="flex items-start gap-2.5">
                    <div class="w-10 h-10 flex-shrink-0 rounded-xl flex items-center justify-center text-white text-sm
                                {{ $r['type']==='college' ? 'bg-[#7a3f91]' : ($r['is_active'] ? 'bg-[#7a3f91]' : 'bg-[#c4a8d4]') }}">
                        <i class="fa-solid {{ $r['type']==='college' ? 'fa-school' : 'fa-users' }}"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-1">
                            <p class="text-sm leading-tight truncate
                                      {{ $r['is_active'] ? 'font-semibold text-[#7a3f91]' : 'font-semibold text-[#333333]' }}">
                                {{ $r['type']==='college' ? $r['department'] : $r['name'] }}
                            </p>
                            @if($r['latest_time'])
                            <span class="text-xs flex-shrink-0 mt-0.5 font-semibold text-[#999999]">{{ $r['latest_time'] }}</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-1 flex-wrap mt-0.5 mb-0.5">
                            @if($r['type']==='college')
                            <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#f3eef8] text-[#7a3f91]"><i class="fa-solid fa-users-between-lines text-[9px] mr-0.5"></i>All Courses</span>
                            <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#f3eef8] text-[#7a3f91]">{{ $r['total_count'] }} alumni</span>
                            @else
                            <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#f3eef8] text-[#7a3f91]"><i class="fa-solid fa-graduation-cap text-[9px] mr-0.5"></i>Batch {{ $r['batch'] }}</span>
                            <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-md bg-[#f3eef8] text-[#7a3f91]">{{ strtoupper($r['course_code']) }}</span>
                            @endif
                        </div>
                        @if($r['latest_body'])
                        <p class="text-xs truncate leading-tight text-[#666666]">
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
                        <p class="text-xs text-[#999999] mt-1">{{ $r['type']==='college' ? $r['total_count'].' total alumni' : $r['total_count'].' members' }}</p>
                        @endif
                    </div>
                </div>
            </button>
            @empty
            <div class="flex flex-col items-center justify-center py-16 text-center px-4">
                <i class="fa-solid fa-comments-slash text-3xl text-[#E8E0F0] mb-3"></i>
                <p class="text-sm font-semibold text-[#666666]">No chats found</p>
            </div>
            @endforelse
        </div>
    </div>

    {{-- ══ MAIN AREA ══ --}}
    @if($room)
    <div class="flex flex-col flex-1 min-w-0">

        {{-- HEADER --}}
        <div class="flex items-center gap-3 px-5 py-3.5 flex-shrink-0 border-b border-[#5c2778] bg-[#7A3F91]">
            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 bg-white/18 border border-white/28">
                <i class="fa-solid {{ $roomType === 'college' ? 'fa-school' : 'fa-users' }} text-white text-sm"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-white font-semibold text-sm leading-tight truncate uppercase tracking-wide">
                    {{ $roomType === 'college' ? $alumniCollege : ($room['name'] ?? 'Group Chat') }}
                </p>
                <div class="flex items-center gap-2 flex-wrap mt-0.5">
                    @if($onlineCount > 0)
                    <div class="flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                        <span class="text-white/75 text-xs font-semibold">{{ $onlineCount }} online</span>
                    </div>
                    <span class="text-white/30 text-xs">·</span>
                    @endif
                    @if($roomType === 'college')
                    <span class="text-white/60 text-xs font-semibold flex items-center gap-1">
                        <i class="fa-solid fa-users-between-lines text-[10px]"></i>All Courses & Batches · {{ $totalCount }} alumni
                    </span>
                    @else
                    <span class="text-white/60 text-xs font-semibold">{{ $totalCount }} members</span>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-1.5 flex-shrink-0">
                <button wire:click="togglePins"
                        class="flex items-center gap-1 px-2 py-1.5 rounded-lg text-xs font-bold border transition
                               {{ $showPins ? 'bg-white/25 text-white border-white/35' : 'bg-white/12 text-white/75 border-white/18 hover:bg-white/20' }}">
                    <i class="fa-solid fa-thumbtack text-xs"></i><span class="hidden sm:inline ml-1">Pins</span>
                </button>
                <button wire:click="toggleBatchmates"
                        class="flex items-center gap-1 px-2 py-1.5 rounded-lg text-xs font-bold border transition
                               {{ $showBatchmates ? 'bg-white/25 text-white border-white/35' : 'bg-white/12 text-white/75 border-white/18 hover:bg-white/20' }}">
                    <i class="fa-solid fa-user-group text-xs"></i><span class="hidden sm:inline ml-1">Members</span>
                </button>
            </div>
        </div>

        {{-- BODY --}}
        <div class="flex flex-1 min-h-0">
            <div class="flex flex-col flex-1 min-w-0">

                {{-- Message list --}}
                <div id="msg-list"
                     class="flex-1 overflow-y-auto px-4 py-4 bg-[#fafafa]"
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

                        <div class="flex {{ $msg['is_mine'] ? 'justify-end' : 'justify-start' }} items-end gap-2 {{ $sameGroup ? 'mt-0.5' : 'mt-3' }}">

                            @if(! $msg['is_mine'])
                            <div class="w-8 h-8 rounded-full flex-shrink-0 overflow-hidden mb-1 self-end bg-[#7a3f91]" title="{{ $msg['sender_name'] }}">
                                <img src="{{ $msg['sender_photo'] ?? $defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="{{ $msg['sender_name'] }}">
                            </div>
                            @endif

                            <div class="flex flex-col {{ $msg['is_mine'] ? 'items-end' : 'items-start' }} max-w-[78%] sm:max-w-[70%]">

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

                                {{-- Bubble + toolbar wrapper --}}
                                <div class="relative">

                                    @if($editingId === $msg['id'])
                                    <div class="flex flex-col gap-1.5 min-w-[220px]">
                                        <textarea wire:model="editBody" rows="2"
                                                  class="text-sm rounded-lg border border-[#7A3F91] px-3 py-2 resize-none focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 w-full bg-white shadow-sm"
                                                  wire:keydown.escape="cancelEdit"></textarea>
                                        <div class="flex gap-1.5 justify-end">
                                            <button wire:click="cancelEdit" class="text-xs px-3 py-1.5 rounded-lg border border-[#E8E0F0] text-[#666666] hover:bg-[#f5f5f5] transition font-semibold">Cancel</button>
                                            <button wire:click="saveEdit" class="text-xs px-3 py-1.5 rounded-lg text-white font-semibold hover:opacity-90 transition bg-[#7a3f91]">Save</button>
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
                                        class="text-left px-3.5 py-2.5 rounded-2xl text-sm leading-relaxed break-words w-full
                                               {{ $msg['is_mine']
                                                   ? 'text-white rounded-br-none bg-[#7a3f91]'
                                                   : ($msg['is_coordinator']
                                                       ? 'text-white rounded-bl-none bg-[#7a3f91]'
                                                       : 'bg-white border border-[#E8E0F0] text-[#333333] rounded-bl-none') }}
                                               {{ $toolbarOpen ? 'ring-2 ring-offset-1 ring-[#7a3f91]/40' : '' }}">
                                        {!! $formatted !!}
                                        @if($msg['edited'])
                                            <span class="text-xs opacity-50 ml-1 italic">(edited)</span>
                                        @endif
                                    </button>

                                    @if($toolbarOpen)
                                    <div class="absolute bottom-full mb-2 {{ $msg['is_mine'] ? 'right-0' : 'left-0' }} z-50
                                                flex items-center gap-0.5 bg-white border border-[#D8CCE8] rounded-2xl
                                                px-2 py-1.5 shadow-xl whitespace-nowrap"
                                         wire:click.stop>

                                        @foreach(['heart'=>'❤️','purple'=>'💜','like'=>'👍','dislike'=>'👎','happy'=>'😄','sad'=>'😢'] as $rk => $re)
                                        <div class="relative group/tip" x-data>
                                            <button wire:click="react({{ $msg['id'] }}, '{{ $rk }}')"
                                                    class="w-9 h-9 flex items-center justify-center rounded-xl text-xl leading-none transition
                                                           hover:scale-125 active:scale-110
                                                           {{ $msg['my_reaction'] === $rk ? 'bg-[#f3eef8] ring-2 ring-[#7a3f91]' : 'hover:bg-[#f9f5fd]' }}">{{ $re }}</button>
                                            <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5
                                                         bg-black text-white text-[10px] font-semibold px-2 py-1 rounded-lg whitespace-nowrap
                                                         opacity-0 group-hover/tip:opacity-100 transition-opacity duration-150 z-50">
                                                {{ ucfirst($rk) }}
                                            </span>
                                        </div>
                                        @endforeach

                                        <span class="w-px h-5 bg-[#E8E0F0] mx-0.5 flex-shrink-0"></span>

                                        <div class="relative group/tip" x-data>
                                            <button wire:click="setReply({{ $msg['id'] }})"
                                                    class="w-8 h-8 flex items-center justify-center rounded-xl text-[#555]
                                                           hover:bg-[#f3eef8] hover:text-[#7a3f91] transition">
                                                <i class="fa-solid fa-reply text-xs"></i>
                                            </button>
                                            <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5
                                                         bg-black text-white text-[10px] font-semibold px-2 py-1 rounded-lg whitespace-nowrap
                                                         opacity-0 group-hover/tip:opacity-100 transition-opacity duration-150 z-50">Reply</span>
                                        </div>

                                        <div class="relative group/tip" x-data>
                                            <button wire:click="togglePin({{ $msg['id'] }})"
                                                    class="w-8 h-8 flex items-center justify-center rounded-xl transition
                                                           {{ $msg['is_pinned'] ? 'text-amber-600 bg-amber-50 hover:bg-amber-100' : 'text-[#555] hover:bg-amber-50 hover:text-amber-600' }}">
                                                <i class="fa-solid fa-thumbtack text-xs"></i>
                                            </button>
                                            <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5
                                                         bg-black text-white text-[10px] font-semibold px-2 py-1 rounded-lg whitespace-nowrap
                                                         opacity-0 group-hover/tip:opacity-100 transition-opacity duration-150 z-50">
                                                {{ $msg['is_pinned'] ? 'Unpin' : 'Pin' }}
                                            </span>
                                        </div>

                                        @if($msg['is_mine'])
                                        <span class="w-px h-5 bg-[#E8E0F0] mx-0.5 flex-shrink-0"></span>

                                        <div class="relative group/tip" x-data>
                                            <button wire:click="startEdit({{ $msg['id'] }})"
                                                    class="w-8 h-8 flex items-center justify-center rounded-xl text-[#555]
                                                           hover:bg-[#f3eef8] hover:text-[#7a3f91] transition">
                                                <i class="fa-solid fa-pen text-xs"></i>
                                            </button>
                                            <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5
                                                         bg-black text-white text-[10px] font-semibold px-2 py-1 rounded-lg whitespace-nowrap
                                                         opacity-0 group-hover/tip:opacity-100 transition-opacity duration-150 z-50">Edit</span>
                                        </div>

                                        <div x-data="{ confirmUnsend: false }" class="relative group/tip flex items-center">
                                            <button x-show="!confirmUnsend"
                                                    @click.stop="confirmUnsend = true"
                                                    class="w-8 h-8 flex items-center justify-center rounded-xl text-[#555]
                                                           hover:bg-red-50 hover:text-red-600 transition">
                                                <i class="fa-solid fa-trash-can text-xs"></i>
                                            </button>
                                            <span x-show="!confirmUnsend"
                                                  class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5
                                                         bg-black text-white text-[10px] font-semibold px-2 py-1 rounded-lg whitespace-nowrap
                                                         opacity-0 group-hover/tip:opacity-100 transition-opacity duration-150 z-50">Delete</span>
                                            <div x-show="confirmUnsend" class="flex items-center gap-1" @click.stop>
                                                <span class="text-xs text-red-600 font-semibold px-1">Delete?</span>
                                                <button wire:click="unsend({{ $msg['id'] }})"
                                                        class="text-xs px-2 py-1 rounded-lg bg-red-500 text-white font-semibold hover:bg-red-600 transition">Yes</button>
                                                <button @click.stop="confirmUnsend = false"
                                                        class="text-xs px-2 py-1 rounded-lg bg-[#f5f5f5] text-[#444] font-semibold hover:bg-[#E8E0F0] transition">No</button>
                                            </div>
                                        </div>
                                        @endif
                                    </div>
                                    @endif

                                    @endif

                                    {{-- Reactions popup --}}
                                    @if($reactionsPopupMsgId === $msg['id'] && ! empty($reactionsPopupData))
                                    <div class="absolute bottom-full mb-2 {{ $msg['is_mine'] ? 'right-0' : 'left-0' }} z-50
                                                bg-white border border-[#D0C0E0] rounded-2xl shadow-xl w-64 overflow-hidden"
                                         wire:click.stop>
                                        <div class="flex items-center justify-between px-3.5 py-2.5 border-b border-[#E8E0F0] bg-[#f9f7fc]">
                                            <p class="text-xs font-semibold text-[#333333] uppercase tracking-widest">
                                                <i class="fa-solid fa-face-smile text-[#7a3f91] mr-1.5"></i>Reactions
                                            </p>
                                            <button wire:click="closeReactionsPopup"
                                                    class="w-6 h-6 flex items-center justify-center rounded-full text-[#999999] hover:text-[#333333] hover:bg-[#f5f5f5] transition">
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

                                {{-- Reaction pills --}}
                                @if(! empty($msg['reactions']))
                                <div class="flex gap-1 mt-1 flex-wrap {{ $msg['is_mine'] ? 'justify-end' : 'justify-start' }}">
                                    @foreach($msg['reactions'] as $rk => $cnt)
                                    @php $emoji = match($rk) { 'heart'=>'❤️','purple'=>'💜','like'=>'👍','dislike'=>'👎','happy'=>'😄','sad'=>'😢', default=>'👍' }; @endphp
                                    <button wire:click.stop="openReactionsPopup({{ $msg['id'] }})"
                                            class="inline-flex items-center gap-0.5 text-xs px-1.5 py-0.5 rounded-full border transition-all
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
                <div wire:poll.3000ms="refreshTyping" class="flex-shrink-0">
                    @if(! empty($typingUsers))
                    <div class="flex items-center gap-2.5 px-4 py-2 bg-[#fafafa] border-t border-[#E8E0F0]">
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

                {{-- Reply preview --}}
                @if($replyTo)
                <div class="flex items-center gap-3 px-4 py-2.5 border-t border-[#E8E0F0] bg-[#f3eef8] flex-shrink-0">
                    <div class="w-1 h-10 rounded-full flex-shrink-0 bg-[#7a3f91]"></div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-[#7a3f91] truncate uppercase tracking-widest">Replying to {{ $replyTo['name'] }}</p>
                        <p class="text-xs text-[#666666] truncate">{{ Str::limit($replyTo['body'], 90) }}</p>
                    </div>
                    <button wire:click="clearReply" class="w-7 h-7 flex items-center justify-center rounded-full text-[#999999] hover:text-red-600 hover:bg-red-50 transition flex-shrink-0">
                        <i class="fa-solid fa-xmark text-base"></i>
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
                                class="w-full resize-none rounded-lg border border-[#E8E0F0] bg-[#fafafa] px-4 py-2.5 text-sm leading-relaxed text-[#333333] focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/20 transition placeholder-[#999999]"
                                style="max-height:120px;overflow-y:auto;"></textarea>
                        </div>
                        <button wire:click="sendMessage"
                                class="w-10 h-10 rounded-full flex items-center justify-center text-white flex-shrink-0 transition hover:opacity-90 active:scale-95 shadow-sm bg-[#7a3f91]">
                            <i class="fa-solid fa-paper-plane text-base"></i>
                        </button>
                    </div>
                </div>
            </div>

            {{-- SIDE PANEL --}}
            @if($showBatchmates || $showPins)
            <div class="w-72 border-l border-[#E8E0F0] flex flex-col flex-shrink-0 bg-white">
                <div class="flex items-center gap-2.5 px-4 py-3 border-b border-[#E8E0F0] flex-shrink-0 bg-[#F9F7FC]">
                    @if($showPins)
                        <i class="fa-solid fa-thumbtack text-amber-600"></i>
                        <p class="text-sm font-semibold text-[#333333] flex-1 uppercase tracking-wide">Pinned Messages</p>
                    @else
                        <i class="fa-solid {{ $roomType==='college' ? 'fa-school' : 'fa-user-group' }} text-[#7a3f91]"></i>
                        <p class="text-sm font-semibold text-[#333333] flex-1 uppercase tracking-wide">
                            Members <span class="text-xs font-semibold text-[#999999] ml-1">({{ count($batchmates) }})</span>
                            @if($onlineCount > 0)
                                <span class="ml-1 text-xs font-bold text-emerald-600">· {{ $onlineCount }} online</span>
                            @endif
                        </p>
                    @endif
                    <button wire:click="{{ $showPins ? 'togglePins' : 'toggleBatchmates' }}"
                            class="w-7 h-7 flex items-center justify-center rounded-lg text-[#999999] hover:text-[#333333] hover:bg-[#f5f5f5] transition">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
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
                            <div class="relative">
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[#999999] text-xs pointer-events-none"></i>
                                <input wire:model.live.debounce.300ms="batchSearch" type="text"
                                       placeholder="{{ $roomType==='college' ? 'Search all alumni…' : 'Search members…' }}"
                                       class="w-full pl-8 pr-3 py-2 text-sm rounded-lg border border-[#E8E0F0] bg-[#fafafa] focus:outline-none focus:border-[#7a3f91] focus:ring-1 focus:ring-[#7a3f91]/20 transition placeholder-[#999999]"/>
                            </div>
                        </div>
                        @if(! empty($coordinators) && $batchSearch === '')
                        <div class="px-3 pt-3 pb-1 flex-shrink-0">
                            <p class="text-xs font-semibold uppercase tracking-widest mb-2 px-1 text-[#7A3F91]">
                                <i class="fa-solid fa-shield-halved text-xs mr-1"></i>Coordinators
                            </p>
                            @foreach($coordinators as $coord)
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2 mb-1 border bg-[#F9F7FC] border-[#E8E0F0]">
                                <div class="w-8 h-8 rounded-full flex-shrink-0 overflow-hidden bg-[#7a3f91]">
                                    <img src="{{ $coord['photo'] ?? $defaultAv }}" class="w-full h-full object-cover" onerror="this.src='{{ $defaultAv }}'" alt="{{ $coord['name'] }}">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-[#333333] truncate">{{ $coord['name'] }}</p>
                                    <p class="text-xs font-medium text-[#7a3f91]">Coordinator</p>
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
                            <div class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 border border-[#E8E0F0] hover:border-[#d9c9e8] hover:bg-[#f3eef8] transition-all {{ $bm['is_me'] ? 'bg-[#f3eef8] border-[#d9c9e8]' : '' }}">
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
                                <div class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 border border-[#E8E0F0] hover:bg-[#fafafa] transition-all {{ $bm['is_me'] ? 'bg-[#f3eef8] border-[#d9c9e8]' : '' }}">
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
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3">
                            <div class="flex items-start justify-between gap-2 mb-1.5">
                                <div class="flex items-center gap-1.5 min-w-0">
                                    <i class="fa-solid fa-thumbtack text-amber-600 text-xs flex-shrink-0"></i>
                                    <p class="text-xs font-semibold text-amber-800 truncate">{{ $pin['from'] }}</p>
                                </div>
                                <button wire:click="togglePin({{ $pin['id'] }})"
                                        class="w-5 h-5 flex items-center justify-center rounded-full text-[#999999] hover:text-red-600 hover:bg-red-50 transition flex-shrink-0">
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
    <div class="flex flex-1 items-center justify-center bg-[#fafafa]">
        <div class="flex flex-col items-center text-center px-8">
            <div class="w-20 h-20 rounded-2xl flex items-center justify-center mb-5 bg-[#f3eef8]">
                <i class="fa-solid fa-comments text-5xl text-[#7a3f91]"></i>
            </div>
            <p class="text-lg font-semibold text-[#333333]">Loading your chats…</p>
            <p class="text-sm text-[#999999] mt-2 max-w-xs leading-relaxed">Please wait while we set up your rooms.</p>
        </div>
    </div>
    @endif

</div>