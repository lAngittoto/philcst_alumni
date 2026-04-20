{{-- resources/views/livewire/alumni/messenger.blade.php --}}

<?php

use Livewire\Volt\Component;
use App\Models\Alumni;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new class extends Component {

    // ── Room ──────────────────────────────────────────────────────────────
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
    public bool  $showBatchmates = false;
    public bool  $showPins       = false;
    public array $batchmates     = [];
    public array $coordinators   = [];
    public array $pinnedMessages = [];

    // ── Batchmate search ──────────────────────────────────────────────────
    public string $batchSearch = '';

    // ── Online presence ───────────────────────────────────────────────────
    public int $onlineCount = 0;
    public int $totalCount  = 0;

    // ── @mention autocomplete ─────────────────────────────────────────────
    public array $mentionSuggestions = [];
    public bool  $showMentions       = false;

    // ── Typing indicator ──────────────────────────────────────────────────
    public array $typingUsers = [];

    // ── Current alumni ────────────────────────────────────────────────────
    public int    $alumniId        = 0;
    public string $alumniName      = '';
    public string $alumniFirstName = '';

    // ─────────────────────────────────────────────────────────────────────
    // Boot
    // ─────────────────────────────────────────────────────────────────────
    public function mount(): void
    {
        $user = Auth::user();

        if (! $user || $user->role !== 'alumni') {
            $this->redirect(route('login'));
            return;
        }

        $alumni = Alumni::where('user_id', $user->id)->first();

        if (! $alumni) {
            $this->redirect(route('login'));
            return;
        }

        $this->alumniId        = $alumni->id;
        $this->alumniName      = trim(($alumni->first_name ?? '') . ' ' . ($alumni->last_name ?? ''));
        $this->alumniFirstName = $alumni->first_name ?? '';

        // Find or auto-create this alumni's chat room
        $row = DB::table('chat_rooms')
            ->where('course_code', $alumni->course_code)
            ->where('batch', $alumni->batch)
            ->first();

        if (! $row) {
            $college = DB::table('courses')
                ->where('code', $alumni->course_code)
                ->value('college') ?? '';

            $id = DB::table('chat_rooms')->insertGetId([
                'name'        => ($alumni->course_code ?? 'Course') . ' · Batch ' . ($alumni->batch ?? ''),
                'course_code' => $alumni->course_code ?? '',
                'batch'       => (int) ($alumni->batch ?? 0),
                'department'  => $college,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $row = DB::table('chat_rooms')->find($id);
        }

        $this->roomId = $row->id;
        $this->room   = (array) $row;

        $this->pingPresence();
        $this->refreshOnlineCount();
        $this->loadMessages();
        $this->loadBatchmates();
        $this->loadCoordinators();
        $this->loadTypingIndicators();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Polling hooks
    // ─────────────────────────────────────────────────────────────────────

    public function refreshAll(): void
    {
        $this->pingPresence();
        $this->refreshOnlineCount();
        $this->loadMessages();
        $this->loadTypingIndicators();
    }

    public function refreshTyping(): void
    {
        $this->loadTypingIndicators();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Presence
    // ─────────────────────────────────────────────────────────────────────

    public function pingPresence(): void
    {
        try {
            DB::table('alumni')
                ->where('id', $this->alumniId)
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
            $this->totalCount  = count($this->batchmates);
            $this->onlineCount = 0;
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
                    'sender_type' => 'alumni',
                    'sender_id'   => $this->alumniId,
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
                ->where('sender_type', 'alumni')
                ->where('sender_id', $this->alumniId)
                ->delete();
        } catch (\Throwable) {}
    }

    public function loadTypingIndicators(): void
    {
        try {
            $rows = DB::table('chat_typing')
                ->where('room_id', $this->roomId)
                ->where('typed_at', '>=', now()->subSeconds(6))
                ->where(function ($q) {
                    $q->where('sender_type', '!=', 'alumni')
                      ->orWhere('sender_id', '!=', $this->alumniId);
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
    // ─────────────────────────────────────────────────────────────────────

    public function loadMessages(): void
    {
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
        $oIds = collect($rows)->where('sender_type', 'coordinator')->pluck('sender_id')->unique();

        $aMap = DB::table('alumni')
            ->whereIn('id', $aIds)
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
            ->whereNull('deleted_at')
            ->get(['id', 'sender_type', 'sender_id', 'body'])
            ->keyBy('id');

        $this->messages = collect($rows)->map(function ($m) use ($aMap, $oMap, $rxns, $pins, $rplyMap) {

            $isCoord = $m->sender_type === 'coordinator';
            $s       = $isCoord ? $oMap->get($m->sender_id) : $aMap->get($m->sender_id);
            $sName   = $s ? trim(($s->first_name ?? '') . ' ' . ($s->last_name ?? '')) : 'Unknown';

            $msgRxns = $rxns->get($m->id, collect());
            $rxnGrps = $msgRxns->groupBy('reaction')->map(fn ($g) => $g->count())->toArray();
            $myRxn   = $msgRxns->first(
                fn ($r) => $r->reactor_type === 'alumni' && $r->reactor_id === $this->alumniId
            );

            $reply = null;
            if ($m->reply_to_id && $rplyMap->has($m->reply_to_id)) {
                $r  = $rplyMap->get($m->reply_to_id);
                $rs = $r->sender_type === 'coordinator'
                    ? $oMap->get($r->sender_id)
                    : $aMap->get($r->sender_id);

                $reply = [
                    'id'   => $r->id,
                    'body' => $r->body,
                    'name' => $rs
                        ? trim(($rs->first_name ?? '') . ' ' . ($rs->last_name ?? ''))
                        : 'Unknown',
                ];
            }

            return [
                'id'             => $m->id,
                'sender_type'    => $m->sender_type,
                'sender_id'      => $m->sender_id,
                'sender_name'    => $sName,
                'sender_photo'   => $s->profile_photo ?? null,
                'body'           => $m->body,
                'edited'         => ! is_null($m->edited_at),
                'is_mine'        => $m->sender_type === 'alumni' && $m->sender_id === $this->alumniId,
                'is_coordinator' => $isCoord,
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
    // ─────────────────────────────────────────────────────────────────────

    public function sendMessage(): void
    {
        $body = trim($this->body);
        if ($body === '') return;

        $college = $this->room['department'] ?? '';

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
                    DB::table('chat_mentions')->insert([
                        'message_id'   => $msgId,
                        'mention_type' => 'everyone',
                        'mentioned_id' => null,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                    continue;
                }

                // Search alumni
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

                // Search coordinator
                if ($college) {
                    $foundCoord = DB::table('organizer')
                        ->where('department', $college)
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
                }
            }
        }

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
        if (! $msg || ! $msg['is_mine']) return;

        $this->editingId = $id;
        $this->editBody  = $msg['body'];
    }

    public function saveEdit(): void
    {
        if (! $this->editingId || trim($this->editBody) === '') return;

        DB::table('chat_messages')
            ->where('id', $this->editingId)
            ->where('sender_type', 'alumni')
            ->where('sender_id', $this->alumniId)
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
    // ─────────────────────────────────────────────────────────────────────

    public function unsend(int $id): void
    {
        DB::table('chat_messages')
            ->where('id', $id)
            ->where('sender_type', 'alumni')
            ->where('sender_id', $this->alumniId)
            ->update(['deleted_at' => now()]);

        DB::table('chat_pins')->where('message_id', $id)->delete();

        $this->loadMessages();
        if ($this->showPins) $this->loadPins();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reactions
    // ─────────────────────────────────────────────────────────────────────

    public function react(int $msgId, string $reaction): void
    {
        if (! in_array($reaction, ['heart', 'purple', 'like', 'dislike'], true)) return;

        $existing = DB::table('chat_reactions')
            ->where('message_id', $msgId)
            ->where('reactor_type', 'alumni')
            ->where('reactor_id', $this->alumniId)
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
                'reactor_type' => 'alumni',
                'reactor_id'   => $this->alumniId,
                'reaction'     => $reaction,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        $this->loadMessages();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pin
    // ─────────────────────────────────────────────────────────────────────

    public function togglePin(int $msgId): void
    {
        if (DB::table('chat_pins')->where('message_id', $msgId)->exists()) {
            DB::table('chat_pins')->where('message_id', $msgId)->delete();
        } else {
            DB::table('chat_pins')->insert([
                'room_id'        => $this->roomId,
                'message_id'     => $msgId,
                'pinned_by_type' => 'alumni',
                'pinned_by_id'   => $this->alumniId,
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

    public function toggleBatchmates(): void
    {
        $this->showBatchmates = ! $this->showBatchmates;
        $this->showPins       = false;
        $this->batchSearch    = '';
    }

    public function togglePins(): void
    {
        $this->showPins       = ! $this->showPins;
        $this->showBatchmates = false;
        if ($this->showPins) $this->loadPins();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Batchmates
    // ─────────────────────────────────────────────────────────────────────

    public function loadBatchmates(): void
    {
        $q = trim($this->batchSearch);

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

        $this->batchmates = $query
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'profile_photo', 'last_seen_at'])
            ->map(fn ($a) => [
                'id'        => $a->id,
                'name'      => trim($a->first_name . ' ' . $a->last_name),
                'photo'     => $a->profile_photo ?? null,
                'is_me'     => $a->id === $this->alumniId,
                'is_online' => isset($a->last_seen_at)
                                && Carbon::parse($a->last_seen_at)->gte(now()->subMinutes(5)),
            ])->toArray();
    }

    public function updatedBatchSearch(): void
    {
        $this->loadBatchmates();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Coordinators
    // ─────────────────────────────────────────────────────────────────────

    public function loadCoordinators(): void
    {
        $college = $this->room['department'] ?? '';

        if (! $college) {
            $college = DB::table('courses')
                ->where('code', $this->room['course_code'])
                ->value('college') ?? '';
        }

        $this->coordinators = DB::table('organizer')
            ->where('department', $college)
            ->where('status', 'ACTIVE')
            ->whereNull('deleted_at')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'profile_photo', 'department'])
            ->map(fn ($o) => [
                'id'    => $o->id,
                'name'  => trim($o->first_name . ' ' . $o->last_name),
                'photo' => $o->profile_photo ?? null,
                'dept'  => $o->department,
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
        $oIds = collect($rows)->where('sender_type', 'coordinator')->pluck('sender_id')->unique();

        $aMap = DB::table('alumni')
            ->whereIn('id', $aIds)
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        $oMap = DB::table('organizer')
            ->whereIn('id', $oIds)
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        $this->pinnedMessages = collect($rows)->map(function ($p) use ($aMap, $oMap) {
            $s = $p->sender_type === 'coordinator'
                ? $oMap->get($p->sender_id)
                : $aMap->get($p->sender_id);

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
    // @mention autocomplete (alumni + coordinators)
    // ─────────────────────────────────────────────────────────────────────

    public function updatedBody(string $value): void
    {
        if (preg_match('/@(\w*)$/', $value, $m)) {
            $q       = $m[1];
            $course  = $this->room['course_code'] ?? '';
            $batch   = $this->room['batch']        ?? 0;
            $college = $this->room['department']   ?? '';

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

            $coordinators = $college
                ? DB::table('organizer')
                    ->where('department', $college)
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
                    ])->toArray()
                : [];

            $this->mentionSuggestions = array_merge(
                [['id' => 0, 'name' => 'everyone', 'type' => 'everyone']],
                $alumni,
                $coordinators
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
     MESSENGER UI
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="flex flex-col rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden"
     style="height: calc(100vh - 90px);"
     wire:poll.8000ms="refreshAll">

    {{-- ── HEADER ──────────────────────────────────────────────────────── --}}
    <div class="flex items-center gap-3 px-4 py-3 flex-shrink-0 border-b border-purple-900"
         style="background:#7a3f91;">

        <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
             style="background:rgba(255,255,255,.18); border:1.5px solid rgba(255,255,255,.28);">
            <i class="fa-solid fa-users text-white text-base"></i>
        </div>

        <div class="flex-1 min-w-0">
            <p class="text-white font-black text-sm sm:text-base leading-tight truncate">
                {{ $room['name'] ?? 'Group Chat' }}
            </p>
            <div class="flex items-center gap-2 flex-wrap">
                @if($onlineCount > 0)
                <div class="flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                    <span class="text-white/80 text-[11px] font-bold">
                        {{ $onlineCount }}/{{ $totalCount }} online
                    </span>
                </div>
                <span class="text-white/30 text-[11px]">·</span>
                @endif
                <span class="text-white/55 text-[11px] font-semibold">
                    {{ count($batchmates) }} members · Batch Group Chat
                </span>
            </div>
        </div>

        <div class="flex items-center gap-2 flex-shrink-0">
            <button wire:click="togglePins"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold border transition"
                    style="{{ $showPins
                        ? 'background:rgba(255,255,255,.30);color:#fff;border-color:rgba(255,255,255,.40);'
                        : 'background:rgba(255,255,255,.12);color:rgba(255,255,255,.80);border-color:rgba(255,255,255,.18);' }}">
                <i class="fa-solid fa-thumbtack text-[10px]"></i>
                <span class="hidden sm:inline">Pins</span>
            </button>
            <button wire:click="toggleBatchmates"
                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold border transition"
                    style="{{ $showBatchmates
                        ? 'background:rgba(255,255,255,.30);color:#fff;border-color:rgba(255,255,255,.40);'
                        : 'background:rgba(255,255,255,.12);color:rgba(255,255,255,.80);border-color:rgba(255,255,255,.18);' }}">
                <i class="fa-solid fa-user-group text-[10px]"></i>
                <span class="hidden sm:inline">Members</span>
            </button>
        </div>
    </div>

    {{-- ── BODY ─────────────────────────────────────────────────────────── --}}
    <div class="flex flex-1 min-h-0">

        {{-- ── MESSAGE COLUMN ─────────────────────────────────────────── --}}
        <div class="flex flex-col flex-1 min-w-0">

            {{-- ── Message list ──────────────────────────────────────── --}}
            <div id="msg-list"
                 class="flex-1 overflow-y-auto px-4 py-4 space-y-0.5 bg-gray-50"
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
                        <div class="flex-1 h-px bg-gray-200"></div>
                        <span class="text-[10px] font-bold text-gray-400 tracking-wider uppercase px-2 whitespace-nowrap">
                            {{ $msg['date_label'] }}
                        </span>
                        <div class="flex-1 h-px bg-gray-200"></div>
                    </div>
                    @endif

                    {{-- Message row --}}
                    <div class="flex {{ $msg['is_mine'] ? 'justify-end' : 'justify-start' }} items-end gap-2 {{ $sameGroup ? 'mt-0.5' : 'mt-3' }}"
                         x-data="{ open: false, confirmUnsend: false }"
                         @click.outside="open = false; confirmUnsend = false">

                        {{-- Avatar – others --}}
                        @if(! $msg['is_mine'])
                        <div class="w-7 h-7 rounded-full flex-shrink-0 flex items-center justify-center
                                    text-[11px] font-black text-white overflow-hidden mb-1 self-end"
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
                            <p class="text-[11px] font-bold px-1 mb-0.5 {{ $msg['is_coordinator'] ? 'text-blue-600' : 'text-purple-700' }}">
                                {{ $msg['sender_name'] }}
                                @if($msg['is_coordinator'])
                                    <span class="ml-1 text-[9px] font-semibold bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded">
                                        Alumni Coordinator
                                    </span>
                                @endif
                            </p>
                            @endif

                            {{-- Pinned indicator --}}
                            @if($msg['is_pinned'])
                            <div class="flex items-center gap-1 text-[10px] text-amber-600 font-bold mb-0.5 px-1">
                                <i class="fa-solid fa-thumbtack text-[8px]"></i> Pinned
                            </div>
                            @endif

                            {{-- Reply quote --}}
                            @if($msg['reply_to'])
                            <div class="text-[11px] rounded-xl px-2.5 py-1.5 mb-1 max-w-full border-l-[3px] leading-snug
                                {{ $msg['is_mine']
                                    ? 'bg-purple-200/60 border-white/70 text-purple-900'
                                    : 'bg-white border-gray-400 text-gray-600' }}">
                                <span class="font-bold block truncate">{{ $msg['reply_to']['name'] }}</span>
                                <span class="truncate block">{{ Str::limit($msg['reply_to']['body'], 70) }}</span>
                            </div>
                            @endif

                            {{-- Edit mode --}}
                            @if($editingId === $msg['id'])
                            <div class="flex flex-col gap-1.5 min-w-[220px]">
                                <textarea wire:model="editBody"
                                          rows="2"
                                          class="text-sm rounded-xl border border-purple-400 px-3 py-2 resize-none
                                                 focus:outline-none focus:ring-2 focus:ring-purple-300 w-full bg-white shadow-sm"
                                          wire:keydown.escape="cancelEdit"></textarea>
                                <div class="flex gap-1.5 justify-end">
                                    <button wire:click="cancelEdit"
                                            class="text-xs px-3 py-1.5 rounded-lg border border-gray-300 text-gray-600
                                                   hover:bg-gray-100 transition font-semibold">
                                        Cancel
                                    </button>
                                    <button wire:click="saveEdit"
                                            class="text-xs px-3 py-1.5 rounded-lg text-white font-bold hover:opacity-90 transition"
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
                                    ? 'font-bold text-yellow-200 bg-yellow-400/20 px-0.5 rounded'
                                    : 'font-bold text-purple-700 bg-purple-100 px-0.5 rounded';
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
                                                ? 'bg-blue-600 text-white rounded-bl-none'
                                                : 'bg-white border border-gray-200 text-gray-900 rounded-bl-none') }}"
                                 style="{{ $msg['is_mine'] ? 'background:#7a3f91;' : '' }}">
                                {!! $formatted !!}
                                @if($msg['edited'])
                                    <span class="text-[10px] opacity-50 ml-1 italic">(edited)</span>
                                @endif
                            </div>
                            @endif

                            {{-- Inline action bar --}}
                            <div x-show="open"
                                 x-transition:enter="transition ease-out duration-150"
                                 x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                                 x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                 x-cloak
                                 class="flex flex-wrap items-center gap-1.5 mt-2 bg-white border border-gray-200
                                        rounded-2xl px-3 py-2 shadow-xl z-10 w-auto">

                                @foreach(['heart' => '❤️', 'purple' => '💜', 'like' => '👍', 'dislike' => '👎'] as $rk => $re)
                                <button wire:click="react({{ $msg['id'] }}, '{{ $rk }}')"
                                        @click.stop
                                        class="text-[1.2rem] leading-none transition-transform hover:scale-125 active:scale-110
                                               {{ $msg['my_reaction'] === $rk ? 'opacity-100 scale-110' : 'opacity-50 hover:opacity-100' }}"
                                        title="{{ ucfirst($rk) }}">{{ $re }}</button>
                                @endforeach

                                <span class="w-px h-5 bg-gray-200 block"></span>

                                <button wire:click="setReply({{ $msg['id'] }})"
                                        @click.stop="open = false"
                                        class="flex items-center gap-1 px-2 py-1 rounded-lg text-gray-500
                                               hover:text-purple-700 hover:bg-purple-50 transition text-xs font-semibold">
                                    <i class="fa-solid fa-reply text-xs"></i>
                                    <span class="hidden sm:inline">Reply</span>
                                </button>

                                <button wire:click="togglePin({{ $msg['id'] }})"
                                        @click.stop
                                        class="flex items-center gap-1 px-2 py-1 rounded-lg transition text-xs font-semibold
                                               {{ $msg['is_pinned']
                                                    ? 'text-amber-500 bg-amber-50 hover:bg-amber-100'
                                                    : 'text-gray-500 hover:text-amber-500 hover:bg-amber-50' }}">
                                    <i class="fa-solid fa-thumbtack text-xs"></i>
                                    <span class="hidden sm:inline">{{ $msg['is_pinned'] ? 'Unpin' : 'Pin' }}</span>
                                </button>

                                @if($msg['is_mine'])
                                <span class="w-px h-5 bg-gray-200 block"></span>

                                <button wire:click="startEdit({{ $msg['id'] }})"
                                        @click.stop="open = false"
                                        class="flex items-center gap-1 px-2 py-1 rounded-lg text-gray-500
                                               hover:text-blue-600 hover:bg-blue-50 transition text-xs font-semibold">
                                    <i class="fa-solid fa-pen text-xs"></i>
                                    <span class="hidden sm:inline">Edit</span>
                                </button>

                                <div x-show="!confirmUnsend">
                                    <button @click.stop="confirmUnsend = true"
                                            class="flex items-center gap-1 px-2 py-1 rounded-lg text-gray-500
                                                   hover:text-red-500 hover:bg-red-50 transition text-xs font-semibold">
                                        <i class="fa-solid fa-trash-can text-xs"></i>
                                        <span class="hidden sm:inline">Unsend</span>
                                    </button>
                                </div>
                                <div x-show="confirmUnsend" class="flex items-center gap-1">
                                    <span class="text-[11px] text-red-600 font-bold">Delete?</span>
                                    <button wire:click="unsend({{ $msg['id'] }})"
                                            @click.stop
                                            class="text-[11px] px-2 py-1 rounded-lg bg-red-500 text-white font-bold hover:bg-red-600 transition">
                                        Yes
                                    </button>
                                    <button @click.stop="confirmUnsend = false"
                                            class="text-[11px] px-2 py-1 rounded-lg bg-gray-100 text-gray-600 font-bold hover:bg-gray-200 transition">
                                        No
                                    </button>
                                </div>
                                @endif
                            </div>

                            {{-- Reaction pills --}}
                            @if(! empty($msg['reactions']))
                            <div class="flex gap-1 mt-1 flex-wrap {{ $msg['is_mine'] ? 'justify-end' : 'justify-start' }}">
                                @foreach($msg['reactions'] as $rk => $cnt)
                                @php $emoji = match($rk) { 'heart'=>'❤️','purple'=>'💜','like'=>'👍','dislike'=>'👎',default=>'👍' }; @endphp
                                <button wire:click="react({{ $msg['id'] }}, '{{ $rk }}')"
                                        class="inline-flex items-center gap-0.5 text-xs px-1.5 py-0.5 rounded-full border transition-all
                                               {{ $msg['my_reaction'] === $rk
                                                    ? 'bg-purple-100 border-purple-300 text-purple-800 font-bold'
                                                    : 'bg-white border-gray-200 text-gray-600 hover:border-purple-200' }}">
                                    {{ $emoji }}<span class="font-semibold ml-0.5">{{ $cnt }}</span>
                                </button>
                                @endforeach
                            </div>
                            @endif

                            {{-- Timestamp --}}
                            <p class="text-[10px] text-gray-400 mt-0.5 px-1">{{ $msg['time'] }}</p>
                        </div>

                        {{-- Avatar – mine --}}
                        @if($msg['is_mine'])
                        <div class="w-7 h-7 rounded-full flex-shrink-0 flex items-center justify-center
                                    text-[11px] font-black text-white overflow-hidden mb-1 self-end"
                             style="background:#7a3f91;">
                            {{ strtoupper(substr($alumniFirstName, 0, 1)) ?: '?' }}
                        </div>
                        @endif
                    </div>

                @empty
                    <div class="flex flex-col items-center justify-center h-full py-20 text-gray-400 select-none">
                        <div class="w-16 h-16 rounded-2xl flex items-center justify-center mb-4"
                             style="background:#f3eef8;">
                            <i class="fa-solid fa-comments text-3xl" style="color:#7a3f91;"></i>
                        </div>
                        <p class="text-base font-bold text-gray-500">No messages yet</p>
                        <p class="text-sm text-gray-400 mt-1">Be the first to say hi to your batchmates! 👋</p>
                    </div>
                @endforelse
            </div>

            {{-- ── Typing indicator ────────────────────────────────────── --}}
            <div wire:poll.3000ms="refreshTyping" class="flex-shrink-0">
                @if(! empty($typingUsers))
                <div class="flex items-center gap-2.5 px-4 py-2 bg-gray-50 border-t border-gray-100">
                    <div class="flex items-end gap-0.5 h-4">
                        <span class="w-1.5 h-1.5 rounded-full bg-purple-400 animate-bounce"
                              style="animation-delay:0ms; animation-duration:900ms;"></span>
                        <span class="w-1.5 h-1.5 rounded-full bg-purple-400 animate-bounce"
                              style="animation-delay:180ms; animation-duration:900ms;"></span>
                        <span class="w-1.5 h-1.5 rounded-full bg-purple-400 animate-bounce"
                              style="animation-delay:360ms; animation-duration:900ms;"></span>
                    </div>
                    <p class="text-[11px] text-gray-500 font-medium">
                        @php
                            $visible = array_slice($typingUsers, 0, 3);
                            $extra   = count($typingUsers) - count($visible);
                        @endphp
                        <span class="font-bold text-purple-700">
                            {{ implode(', ', $visible) }}{{ $extra > 0 ? " +{$extra}" : '' }}
                        </span>
                        {{ count($typingUsers) === 1 ? 'is' : 'are' }} typing…
                    </p>
                </div>
                @endif
            </div>

            {{-- ── Reply preview bar ──────────────────────────────────── --}}
            @if($replyTo)
            <div class="flex items-center gap-3 px-4 py-2.5 border-t border-purple-200 bg-purple-50 flex-shrink-0">
                <div class="w-1 h-10 rounded-full flex-shrink-0" style="background:#7a3f91;"></div>
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-bold text-purple-700 truncate">Replying to {{ $replyTo['name'] }}</p>
                    <p class="text-xs text-gray-600 truncate">{{ Str::limit($replyTo['body'], 90) }}</p>
                </div>
                <button wire:click="clearReply"
                        class="w-7 h-7 flex items-center justify-center rounded-full text-gray-400
                               hover:text-red-500 hover:bg-red-50 transition flex-shrink-0">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>
            @endif

            {{-- ── Input area ─────────────────────────────────────────── --}}
            <div class="px-4 py-3 border-t border-gray-200 bg-white flex-shrink-0" x-data>

                {{-- @mention dropdown --}}
                @if($showMentions && ! empty($mentionSuggestions))
                <div class="mb-2 bg-white border border-gray-200 rounded-2xl shadow-xl overflow-hidden">
                    @foreach($mentionSuggestions as $sug)
                    <button wire:click="selectMention('{{ addslashes($sug['name']) }}')"
                            class="flex items-center gap-2.5 w-full px-3 py-2.5 hover:bg-purple-50 transition-colors text-left">
                        <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center text-xs font-black text-white"
                             style="background:{{ $sug['type'] === 'coordinator' ? '#2563eb' : '#7a3f91' }};">
                            @if($sug['name'] === 'everyone')
                                <i class="fa-solid fa-users text-[10px]"></i>
                            @else
                                {{ strtoupper(substr($sug['name'], 0, 1)) }}
                            @endif
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-gray-900 truncate">&#64;{{ $sug['name'] }}</p>
                            @if($sug['name'] === 'everyone')
                                <p class="text-[10px] text-purple-600 font-semibold">Notify all members</p>
                            @elseif($sug['type'] === 'coordinator')
                                <p class="text-[10px] text-blue-600 font-semibold">Alumni Coordinator</p>
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
                            class="w-full resize-none rounded-2xl border border-gray-300 bg-gray-50
                                   px-4 py-2.5 text-sm leading-relaxed
                                   focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100
                                   transition"
                            style="max-height:120px; overflow-y:auto;"></textarea>
                    </div>

                    <button wire:click="sendMessage"
                            class="w-10 h-10 rounded-full flex items-center justify-center text-white flex-shrink-0
                                   transition hover:opacity-90 active:scale-95 shadow-sm"
                            style="background:#7a3f91;">
                        <i class="fa-solid fa-paper-plane text-sm"></i>
                    </button>
                </div>

                <p class="text-[10px] text-gray-400 text-center mt-1.5">
                    <kbd class="bg-gray-100 border border-gray-200 rounded px-1 py-0.5 text-[10px]">Enter</kbd> send &nbsp;·&nbsp;
                    <kbd class="bg-gray-100 border border-gray-200 rounded px-1 py-0.5 text-[10px]">Shift+Enter</kbd> new line &nbsp;·&nbsp;
                    <kbd class="bg-gray-100 border border-gray-200 rounded px-1 py-0.5 text-[10px]">@</kbd> mention &nbsp;·&nbsp;
                    <span class="text-gray-300">tap message for actions</span>
                </p>
            </div>
        </div>

        {{-- ── SIDE PANEL ──────────────────────────────────────────────── --}}
        @if($showBatchmates || $showPins)
        <div class="w-72 border-l border-gray-200 flex flex-col flex-shrink-0 bg-white">

            <div class="flex items-center gap-2.5 px-4 py-3 border-b border-gray-200 flex-shrink-0 bg-gray-50">
                @if($showPins)
                    <i class="fa-solid fa-thumbtack text-amber-500"></i>
                    <p class="text-sm font-black text-gray-800 flex-1">Pinned Messages</p>
                @else
                    <i class="fa-solid fa-user-group text-purple-700"></i>
                    <p class="text-sm font-black text-gray-800 flex-1">
                        Members
                        <span class="text-xs font-semibold text-gray-400 ml-1">({{ count($batchmates) }})</span>
                        @if($onlineCount > 0)
                        <span class="ml-1 text-[10px] font-bold text-emerald-600">· {{ $onlineCount }} online</span>
                        @endif
                    </p>
                @endif
                <button wire:click="{{ $showPins ? 'togglePins' : 'toggleBatchmates' }}"
                        class="w-7 h-7 flex items-center justify-center rounded-full text-gray-400
                               hover:text-gray-600 hover:bg-gray-200 transition">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>

            <div class="flex-1 overflow-y-auto flex flex-col">

                @if($showBatchmates)

                    <div class="px-3 py-2.5 border-b border-gray-100 flex-shrink-0">
                        <div class="relative">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2
                                      text-gray-400 text-xs pointer-events-none"></i>
                            <input wire:model.live.debounce.300ms="batchSearch"
                                   type="text"
                                   placeholder="Search members…"
                                   class="w-full pl-8 pr-3 py-2 text-sm rounded-xl border border-gray-200
                                          bg-gray-50 focus:outline-none focus:border-purple-400
                                          focus:ring-1 focus:ring-purple-100 transition"/>
                        </div>
                    </div>

                    {{-- Coordinators section --}}
                    @if(! empty($coordinators) && $batchSearch === '')
                    <div class="px-3 pt-3 pb-1 flex-shrink-0">
                        <p class="text-[10px] font-black text-blue-600 uppercase tracking-widest mb-2 px-1">
                            <i class="fa-solid fa-shield-halved mr-1"></i>Alumni Coordinators
                        </p>
                        @foreach($coordinators as $coord)
                        <div class="flex items-center gap-2.5 rounded-xl px-3 py-2 mb-1 bg-blue-50 border border-blue-100">
                            <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center
                                        text-xs font-black text-white overflow-hidden"
                                 style="background:#2563eb;">
                                @if($coord['photo'])
                                    <img src="{{ asset('storage/' . $coord['photo']) }}"
                                         class="w-full h-full object-cover" alt="">
                                @else
                                    {{ strtoupper(substr($coord['name'], 0, 1)) }}
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-gray-900 truncate">{{ $coord['name'] }}</p>
                                <p class="text-[10px] text-blue-600 font-semibold">Alumni Coordinator</p>
                            </div>
                        </div>
                        @endforeach
                    </div>

                    <div class="px-3 pb-1 flex-shrink-0">
                        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2 px-1">
                            <i class="fa-solid fa-users mr-1"></i>Batchmates
                        </p>
                    </div>
                    @endif

                    {{-- Batchmates list --}}
                    <div class="flex-1 overflow-y-auto px-3 pb-3 space-y-1">
                        @forelse($batchmates as $bm)
                        <div class="flex items-center gap-2.5 rounded-xl px-3 py-2.5 border border-gray-100
                                    hover:border-purple-200 hover:bg-purple-50/50 transition-all">
                            <div class="relative flex-shrink-0">
                                <div class="w-9 h-9 rounded-full flex items-center justify-center
                                            text-xs font-black text-white overflow-hidden"
                                     style="background:#7a3f91;">
                                    @if($bm['photo'])
                                        <img src="{{ asset('storage/' . $bm['photo']) }}"
                                             class="w-full h-full object-cover" alt="">
                                    @else
                                        {{ strtoupper(substr($bm['name'], 0, 1)) }}
                                    @endif
                                </div>
                                @if($bm['is_online'] || $bm['is_me'])
                                <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full
                                             bg-emerald-400 border-2 border-white"></span>
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-gray-900 truncate">
                                    {{ $bm['name'] }}
                                    @if($bm['is_me'])
                                        <span class="text-[10px] text-purple-500 font-semibold">(You)</span>
                                    @endif
                                </p>
                                {{-- Online / Offline only — no VERIFIED --}}
                                <p class="text-[10px] font-semibold
                                    {{ ($bm['is_online'] || $bm['is_me']) ? 'text-emerald-600' : 'text-gray-400' }}">
                                    {{ ($bm['is_online'] || $bm['is_me']) ? 'Online' : 'Offline' }}
                                </p>
                            </div>
                        </div>
                        @empty
                        <div class="flex flex-col items-center justify-center py-10 text-gray-400">
                            <i class="fa-solid fa-user-slash text-2xl text-gray-200 mb-2"></i>
                            <p class="text-sm font-semibold">No results</p>
                            <p class="text-xs mt-1">Try a different name</p>
                        </div>
                        @endforelse
                    </div>

                @elseif($showPins)
                <div class="flex-1 overflow-y-auto p-3 space-y-2">
                    @forelse($pinnedMessages as $pin)
                    <div class="rounded-xl border border-amber-200 bg-amber-50/50 p-3">
                        <div class="flex items-start justify-between gap-2 mb-1.5">
                            <div class="flex items-center gap-1.5 min-w-0">
                                <i class="fa-solid fa-thumbtack text-amber-500 text-[10px] flex-shrink-0"></i>
                                <p class="text-xs font-bold text-amber-700 truncate">{{ $pin['from'] }}</p>
                            </div>
                            <button wire:click="togglePin({{ $pin['id'] }})"
                                    class="w-5 h-5 flex items-center justify-center rounded-full text-gray-400
                                           hover:text-red-500 hover:bg-red-50 transition flex-shrink-0">
                                <i class="fa-solid fa-xmark text-[10px]"></i>
                            </button>
                        </div>
                        <p class="text-sm text-gray-800 leading-snug break-words">
                            {{ Str::limit($pin['body'], 140) }}
                        </p>
                        <p class="text-[10px] text-gray-400 mt-1.5">{{ $pin['pinned_at'] }}</p>
                    </div>
                    @empty
                    <div class="flex flex-col items-center justify-center py-12 text-gray-400">
                        <i class="fa-solid fa-thumbtack text-3xl text-gray-200 mb-3"></i>
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