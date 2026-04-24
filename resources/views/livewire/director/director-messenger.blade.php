{{-- resources/views/livewire/director/director-messenger.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        $this->ensureRoomExists();
        $this->pingPresence();
        $this->loadRoom();
        $this->loadMessages();
        $this->loadMembers();
        $this->refreshOnlineCount();
        $this->loadTypingIndicators();

        $this->dispatch('chat-scroll-bottom');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ensure the one global director room exists
    // ─────────────────────────────────────────────────────────────────────
    protected function ensureRoomExists(): void
    {
        try {
            $exists = DB::table('chat_rooms')
                ->where('course_code', '__director__')
                ->exists();

            if (! $exists) {
                DB::table('chat_rooms')->insert([
                    'name'        => 'Directors & Coordinators',
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
    // Polling hooks
    // ─────────────────────────────────────────────────────────────────────
    public function refreshAll(): void
    {
        $this->pingPresence();
        $this->loadMessages();
        $this->loadTypingIndicators();
        $this->refreshOnlineCount();
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
            DB::table('director')
                ->where('id', $this->directorId)
                ->update(['last_seen_at' => now()]);
        } catch (\Throwable) {}
    }

    public function refreshOnlineCount(): void
    {
        try {
            $onlineDirs = DB::table('director')
                ->whereNull('deleted_at')
                ->where('last_seen_at', '>=', now()->subMinutes(5))
                ->count();

            $onlineCoords = DB::table('organizer')
                ->where('status', 'ACTIVE')
                ->whereNull('deleted_at')
                ->where('last_seen_at', '>=', now()->subMinutes(5))
                ->count();

            $totalDirs = DB::table('director')
                ->whereNull('deleted_at')
                ->count();

            $totalCoords = DB::table('organizer')
                ->where('status', 'ACTIVE')
                ->whereNull('deleted_at')
                ->count();

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
                if ($row->sender_type === 'coordinator') {
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
            ->whereNull('m.deleted_at')
            ->orderBy('m.created_at')
            ->get([
                'm.id', 'm.sender_type', 'm.sender_id', 'm.body',
                'm.reply_to_id', 'm.edited_at', 'm.created_at',
            ])
            ->toArray();

        $dIds = collect($rows)->where('sender_type', 'director')->pluck('sender_id')->unique();
        $oIds = collect($rows)->where('sender_type', 'coordinator')->pluck('sender_id')->unique();

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
            ->whereNull('deleted_at')
            ->get(['id', 'sender_type', 'sender_id', 'body'])
            ->keyBy('id');

        $this->messages = collect($rows)->map(function ($m) use ($dMap, $oMap, $rxns, $pins, $rplyMap) {

            $isDir   = $m->sender_type === 'director';
            $isCoord = $m->sender_type === 'coordinator';
            $s       = $isDir ? $dMap->get($m->sender_id) : $oMap->get($m->sender_id);
            $sName   = $s ? trim(($s->first_name ?? '') . ' ' . ($s->last_name ?? '')) : 'Unknown';

            $msgRxns = $rxns->get($m->id, collect());
            $rxnGrps = $msgRxns->groupBy('reaction')->map(fn ($g) => $g->count())->toArray();
            $myRxn   = $msgRxns->first(
                fn ($r) => $r->reactor_type === 'director' && $r->reactor_id === $this->directorId
            );

            $reply = null;
            if ($m->reply_to_id && $rplyMap->has($m->reply_to_id)) {
                $r  = $rplyMap->get($m->reply_to_id);
                $rs = $r->sender_type === 'director'
                    ? $dMap->get($r->sender_id)
                    : $oMap->get($r->sender_id);

                $reply = [
                    'id'   => $r->id,
                    'body' => $r->body,
                    'name' => $rs ? trim(($rs->first_name ?? '') . ' ' . ($rs->last_name ?? '')) : 'Unknown',
                ];
            }

            $isMe = $m->sender_type === 'director' && $m->sender_id === $this->directorId;

            return [
                'id'             => $m->id,
                'sender_type'    => $m->sender_type,
                'sender_id'      => $m->sender_id,
                'sender_name'    => $sName,
                'sender_photo'   => $s->profile_photo ?? null,
                'body'           => $m->body,
                'edited'         => ! is_null($m->edited_at),
                'is_mine'        => $isMe,
                'is_director'    => $isDir,
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
            ->where('sender_type', 'director')
            ->where('sender_id', $this->directorId)
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
            ->where('sender_type', 'director')
            ->where('sender_id', $this->directorId)
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
            ->where('reactor_type', 'director')
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
                'reactor_type' => 'director',
                'reactor_id'   => $this->directorId,
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
                'pinned_by_type' => 'director',
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

        $this->directors = $dirQuery
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'profile_photo', 'last_seen_at'])
            ->map(fn ($d) => [
                'id'        => $d->id,
                'name'      => trim($d->first_name . ' ' . $d->last_name),
                'photo'     => $d->profile_photo ?? null,
                'is_me'     => $d->id === $this->directorId,
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
                'photo'      => $o->profile_photo ?? null,
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
        $oIds = collect($rows)->where('sender_type', 'coordinator')->pluck('sender_id')->unique();

        $dMap = DB::table('director')
            ->whereIn('id', $dIds)
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        $oMap = DB::table('organizer')
            ->whereIn('id', $oIds)
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        $this->pinnedMessages = collect($rows)->map(function ($p) use ($dMap, $oMap) {
            $s = $p->sender_type === 'director'
                ? $dMap->get($p->sender_id)
                : $oMap->get($p->sender_id);

            return [
                'id'          => $p->id,
                'body'        => $p->body,
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
     DIRECTOR MESSENGER UI  —  Single global staff channel
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="flex rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden"
     style="height: calc(100vh - 90px);"
     wire:poll.8000ms="refreshAll">

    @if($room)
    <div class="flex flex-1 min-w-0 flex-col">

        {{-- ── HEADER ──────────────────────────────────────────────────── --}}
        <div class="flex items-center gap-3 px-5 py-3.5 flex-shrink-0 border-b border-purple-900"
             style="background:#7a3f91;">

            {{-- Room icon --}}
            <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0"
                 style="background:rgba(255,255,255,.18); border:1.5px solid rgba(255,255,255,.28);">
                <i class="fa-solid fa-shield-halved text-white text-lg"></i>
            </div>

            {{-- Room info --}}
            <div class="flex-1 min-w-0">
                <p class="text-white font-black text-base sm:text-lg leading-tight">
                    Directors &amp; Coordinators
                </p>
                <div class="flex items-center gap-2 flex-wrap">
                    @if($onlineCount > 0)
                    <div class="flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                        <span class="text-white/80 text-xs font-bold">
                            {{ $onlineCount }}/{{ $totalCount }} online
                        </span>
                    </div>
                    <span class="text-white/30 text-xs">·</span>
                    @endif
                    <span class="text-white/55 text-xs font-semibold">
                        <i class="fa-solid fa-lock text-[10px] mr-0.5"></i>Internal Staff Channel
                    </span>
                </div>
            </div>

            {{-- My badge (top-right of header) --}}
            <div class="hidden sm:flex items-center gap-2 mr-2">
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-xl"
                     style="background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.22);">
                    <div class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-black text-white flex-shrink-0"
                         style="background:rgba(255,255,255,.25);">
                        {{ strtoupper(substr($directorFirstName, 0, 1)) ?: 'D' }}
                    </div>
                    <div class="leading-none">
                        <p class="text-white text-xs font-black truncate max-w-[100px]">{{ $directorFirstName }}</p>
                        <p class="text-white/50 text-[10px] font-semibold">Director</p>
                    </div>
                    <span class="w-2 h-2 rounded-full bg-emerald-400 flex-shrink-0"></span>
                </div>
            </div>

            {{-- Action buttons --}}
            <div class="flex items-center gap-2 flex-shrink-0">
                <button wire:click="togglePins"
                        class="flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-sm font-bold border transition"
                        style="{{ $showPins
                            ? 'background:rgba(255,255,255,.30);color:#fff;border-color:rgba(255,255,255,.40);'
                            : 'background:rgba(255,255,255,.12);color:rgba(255,255,255,.80);border-color:rgba(255,255,255,.18);' }}">
                    <i class="fa-solid fa-thumbtack text-xs"></i>
                    <span class="hidden sm:inline">Pins</span>
                </button>
                <button wire:click="toggleMembers"
                        class="flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-sm font-bold border transition"
                        style="{{ $showMembers
                            ? 'background:rgba(255,255,255,.30);color:#fff;border-color:rgba(255,255,255,.40);'
                            : 'background:rgba(255,255,255,.12);color:rgba(255,255,255,.80);border-color:rgba(255,255,255,.18);' }}">
                    <i class="fa-solid fa-user-group text-xs"></i>
                    <span class="hidden sm:inline">Members</span>
                </button>
            </div>
        </div>

        {{-- ── BODY ─────────────────────────────────────────────────────── --}}
        <div class="flex flex-1 min-h-0">

            {{-- ── MESSAGE COLUMN ──────────────────────────────────────── --}}
            <div class="flex flex-col flex-1 min-w-0">

                {{-- Message list --}}
                <div id="msg-list"
                     class="flex-1 overflow-y-auto px-5 py-5 space-y-0.5 bg-gray-50"
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
                        <div class="flex items-center gap-3 my-5">
                            <div class="flex-1 h-px bg-gray-200"></div>
                            <span class="text-xs font-bold text-gray-400 tracking-wider uppercase px-2 whitespace-nowrap">
                                {{ $msg['date_label'] }}
                            </span>
                            <div class="flex-1 h-px bg-gray-200"></div>
                        </div>
                        @endif

                        {{-- Message row --}}
                        <div class="flex {{ $msg['is_mine'] ? 'justify-end' : 'justify-start' }} items-end gap-2 {{ $sameGroup ? 'mt-1' : 'mt-4' }}"
                             x-data="{ open: false, confirmUnsend: false }"
                             @click.outside="open = false; confirmUnsend = false">

                            {{-- Avatar – others --}}
                            @if(! $msg['is_mine'])
                            <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center
                                        text-xs font-black text-white overflow-hidden mb-1 self-end"
                                 style="background:#7a3f91;"
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
                            <div class="flex flex-col {{ $msg['is_mine'] ? 'items-end' : 'items-start' }} max-w-[75%] sm:max-w-[68%]">

                                {{-- Sender name --}}
                                @if(! $msg['is_mine'] && ! $sameGroup)
                                <p class="text-xs font-bold px-1 mb-1 text-gray-600">
                                    {{ $msg['sender_name'] }}
                                    @if($msg['is_director'])
                                        <span class="ml-1 text-[10px] font-semibold bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded">
                                            <i class="fa-solid fa-shield-halved text-[9px] mr-0.5"></i>Director
                                        </span>
                                    @else
                                        <span class="ml-1 text-[10px] font-semibold bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded">
                                            Coordinator
                                        </span>
                                    @endif
                                </p>
                                @endif

                                {{-- Pinned indicator --}}
                                @if($msg['is_pinned'])
                                <div class="flex items-center gap-1 text-xs text-amber-600 font-bold mb-0.5 px-1">
                                    <i class="fa-solid fa-thumbtack text-[10px]"></i> Pinned
                                </div>
                                @endif

                                {{-- Reply quote --}}
                                @if($msg['reply_to'])
                                <div class="text-xs rounded-xl px-3 py-2 mb-1.5 max-w-full border-l-[3px] leading-snug
                                    {{ $msg['is_mine']
                                        ? 'bg-purple-200/60 border-white/70 text-purple-900'
                                        : 'bg-white border-gray-400 text-gray-600' }}">
                                    <span class="font-bold block truncate">{{ $msg['reply_to']['name'] }}</span>
                                    <span class="truncate block">{{ Str::limit($msg['reply_to']['body'], 70) }}</span>
                                </div>
                                @endif

                                {{-- Edit mode --}}
                                @if($editingId === $msg['id'])
                                <div class="flex flex-col gap-2 min-w-[240px]">
                                    <textarea wire:model="editBody"
                                              rows="2"
                                              class="text-sm rounded-xl border border-purple-400 px-3 py-2 resize-none
                                                     focus:outline-none focus:ring-2 focus:ring-purple-300 w-full bg-white shadow-sm"
                                              wire:keydown.escape="cancelEdit"></textarea>
                                    <div class="flex gap-2 justify-end">
                                        <button wire:click="cancelEdit"
                                                class="text-sm px-4 py-1.5 rounded-lg border border-gray-300
                                                       text-gray-600 hover:bg-gray-100 transition font-semibold">
                                            Cancel
                                        </button>
                                        <button wire:click="saveEdit"
                                                class="text-sm px-4 py-1.5 rounded-lg text-white font-bold
                                                       hover:opacity-90 transition"
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
                                     class="px-4 py-3 rounded-2xl text-sm leading-relaxed break-words
                                            shadow-sm cursor-pointer select-none transition-opacity active:opacity-80
                                            {{ $msg['is_mine']
                                                ? 'text-white rounded-br-none'
                                                : ($msg['is_director']
                                                    ? 'text-white rounded-bl-none'
                                                    : 'bg-white border border-gray-200 text-gray-900 rounded-bl-none') }}"
                                     style="{{ $msg['is_mine']
                                         ? 'background:#7a3f91;'
                                         : ($msg['is_director']
                                             ? 'background:#9333ea;'
                                             : '') }}">
                                    {!! $formatted !!}
                                    @if($msg['edited'])
                                        <span class="text-xs opacity-50 ml-1 italic">(edited)</span>
                                    @endif
                                </div>
                                @endif

                                {{-- Inline action bar --}}
                                <div x-show="open"
                                     x-transition:enter="transition ease-out duration-150"
                                     x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                                     x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                                     x-cloak
                                     class="flex flex-wrap items-center gap-2 mt-2 bg-white border border-gray-200
                                            rounded-2xl px-3.5 py-2.5 shadow-xl z-10 w-auto">

                                    @foreach(['heart' => '❤️', 'purple' => '💜', 'like' => '👍', 'dislike' => '👎'] as $rk => $re)
                                    <button wire:click="react({{ $msg['id'] }}, '{{ $rk }}')"
                                            @click.stop
                                            class="text-xl leading-none transition-transform hover:scale-125 active:scale-110
                                                   {{ $msg['my_reaction'] === $rk ? 'opacity-100 scale-110' : 'opacity-50 hover:opacity-100' }}"
                                            title="{{ ucfirst($rk) }}">{{ $re }}</button>
                                    @endforeach

                                    <span class="w-px h-5 bg-gray-200 block"></span>

                                    <button wire:click="setReply({{ $msg['id'] }})"
                                            @click.stop="open = false"
                                            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-gray-500
                                                   hover:text-purple-700 hover:bg-purple-50 transition text-xs font-semibold">
                                        <i class="fa-solid fa-reply"></i>
                                        <span class="hidden sm:inline">Reply</span>
                                    </button>

                                    <button wire:click="togglePin({{ $msg['id'] }})"
                                            @click.stop
                                            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg transition text-xs font-semibold
                                                   {{ $msg['is_pinned']
                                                        ? 'text-amber-500 bg-amber-50 hover:bg-amber-100'
                                                        : 'text-gray-500 hover:text-amber-500 hover:bg-amber-50' }}">
                                        <i class="fa-solid fa-thumbtack"></i>
                                        <span class="hidden sm:inline">{{ $msg['is_pinned'] ? 'Unpin' : 'Pin' }}</span>
                                    </button>

                                    @if($msg['is_mine'])
                                    <span class="w-px h-5 bg-gray-200 block"></span>

                                    <button wire:click="startEdit({{ $msg['id'] }})"
                                            @click.stop="open = false"
                                            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-gray-500
                                                   hover:text-purple-600 hover:bg-purple-50 transition text-xs font-semibold">
                                        <i class="fa-solid fa-pen"></i>
                                        <span class="hidden sm:inline">Edit</span>
                                    </button>

                                    <div x-show="!confirmUnsend">
                                        <button @click.stop="confirmUnsend = true"
                                                class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-gray-500
                                                       hover:text-red-500 hover:bg-red-50 transition text-xs font-semibold">
                                            <i class="fa-solid fa-trash-can"></i>
                                            <span class="hidden sm:inline">Unsend</span>
                                        </button>
                                    </div>
                                    <div x-show="confirmUnsend" class="flex items-center gap-1.5">
                                        <span class="text-xs text-red-600 font-bold">Delete?</span>
                                        <button wire:click="unsend({{ $msg['id'] }})"
                                                @click.stop
                                                class="text-xs px-2.5 py-1.5 rounded-lg bg-red-500 text-white font-bold hover:bg-red-600 transition">
                                            Yes
                                        </button>
                                        <button @click.stop="confirmUnsend = false"
                                                class="text-xs px-2.5 py-1.5 rounded-lg bg-gray-100 text-gray-600 font-bold hover:bg-gray-200 transition">
                                            No
                                        </button>
                                    </div>
                                    @endif
                                </div>

                                {{-- Reaction pills --}}
                                @if(! empty($msg['reactions']))
                                <div class="flex gap-1 mt-1.5 flex-wrap {{ $msg['is_mine'] ? 'justify-end' : 'justify-start' }}">
                                    @foreach($msg['reactions'] as $rk => $cnt)
                                    @php $emoji = match($rk) { 'heart'=>'❤️','purple'=>'💜','like'=>'👍','dislike'=>'👎',default=>'👍' }; @endphp
                                    <button wire:click="react({{ $msg['id'] }}, '{{ $rk }}')"
                                            class="inline-flex items-center gap-1 text-sm px-2 py-0.5 rounded-full border transition-all
                                                   {{ $msg['my_reaction'] === $rk
                                                        ? 'bg-purple-100 border-purple-300 text-purple-800 font-bold'
                                                        : 'bg-white border-gray-200 text-gray-600 hover:border-purple-200' }}">
                                        {{ $emoji }}<span class="font-semibold">{{ $cnt }}</span>
                                    </button>
                                    @endforeach
                                </div>
                                @endif

                                {{-- Timestamp --}}
                                <p class="text-xs text-gray-400 mt-1 px-1">{{ $msg['time'] }}</p>
                            </div>

                            {{-- Avatar – mine (director) --}}
                            @if($msg['is_mine'])
                            <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center
                                        text-xs font-black text-white overflow-hidden mb-1 self-end"
                                 style="background:#7a3f91;">
                                {{ strtoupper(substr($directorFirstName, 0, 1)) ?: '?' }}
                            </div>
                            @endif
                        </div>

                    @empty
                        <div class="flex flex-col items-center justify-center h-full py-24 text-gray-400 select-none">
                            <div class="w-20 h-20 rounded-2xl flex items-center justify-center mb-5"
                                 style="background:#f3eef8;">
                                <i class="fa-solid fa-shield-halved text-4xl" style="color:#7a3f91;"></i>
                            </div>
                            <p class="text-lg font-bold text-gray-500">No messages yet</p>
                            <p class="text-sm text-gray-400 mt-1">Start the internal staff conversation! 👋</p>
                        </div>
                    @endforelse
                </div>

                {{-- Typing indicator --}}
                <div wire:poll.3000ms="refreshTyping" class="flex-shrink-0">
                    @if(! empty($typingUsers))
                    <div class="flex items-center gap-2.5 px-5 py-2.5 bg-gray-50 border-t border-gray-100">
                        <div class="flex items-end gap-0.5 h-4">
                            <span class="w-1.5 h-1.5 rounded-full bg-purple-400 animate-bounce"
                                  style="animation-delay:0ms; animation-duration:900ms;"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-purple-400 animate-bounce"
                                  style="animation-delay:180ms; animation-duration:900ms;"></span>
                            <span class="w-1.5 h-1.5 rounded-full bg-purple-400 animate-bounce"
                                  style="animation-delay:360ms; animation-duration:900ms;"></span>
                        </div>
                        <p class="text-xs text-gray-500 font-medium">
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

                {{-- Reply preview bar --}}
                @if($replyTo)
                <div class="flex items-center gap-3 px-5 py-3 border-t border-purple-200 bg-purple-50 flex-shrink-0">
                    <div class="w-1 h-10 rounded-full flex-shrink-0" style="background:#7a3f91;"></div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-purple-700 truncate">Replying to {{ $replyTo['name'] }}</p>
                        <p class="text-xs text-gray-600 truncate">{{ Str::limit($replyTo['body'], 90) }}</p>
                    </div>
                    <button wire:click="clearReply"
                            class="w-8 h-8 flex items-center justify-center rounded-full text-gray-400
                                   hover:text-red-500 hover:bg-red-50 transition flex-shrink-0">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>
                @endif

                {{-- Input area --}}
                <div class="px-5 py-4 border-t border-gray-200 bg-white flex-shrink-0" x-data>

                    {{-- @mention dropdown --}}
                    @if($showMentions && ! empty($mentionSuggestions))
                    <div class="mb-3 bg-white border border-gray-200 rounded-2xl shadow-xl overflow-hidden">
                        @foreach($mentionSuggestions as $sug)
                        <button wire:click="selectMention('{{ addslashes($sug['name']) }}')"
                                class="flex items-center gap-3 w-full px-4 py-3 hover:bg-purple-50 transition-colors text-left">
                            <div class="w-9 h-9 rounded-full flex-shrink-0 flex items-center justify-center text-sm font-black text-white"
                                 style="background:#7a3f91;">
                                @if($sug['name'] === 'everyone')
                                    <i class="fa-solid fa-users text-xs"></i>
                                @else
                                    {{ strtoupper(substr($sug['name'], 0, 1)) }}
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-gray-900 truncate">&#64;{{ $sug['name'] }}</p>
                                @if($sug['name'] === 'everyone')
                                    <p class="text-xs text-purple-600 font-semibold">Notify all staff</p>
                                @elseif($sug['type'] === 'director')
                                    <p class="text-xs text-purple-700 font-semibold">
                                        <i class="fa-solid fa-shield-halved text-[10px] mr-0.5"></i>Director
                                    </p>
                                @elseif($sug['type'] === 'coordinator')
                                    <p class="text-xs text-purple-600 font-semibold">Alumni Coordinator</p>
                                @endif
                            </div>
                        </button>
                        @endforeach
                    </div>
                    @endif

                    <div class="flex items-end gap-3">
                        <div class="flex-1 relative">
                            <textarea
                                id="chat-input"
                                wire:model.live.debounce.200ms="body"
                                wire:keyup.debounce.800ms="pingTyping"
                                placeholder="Message Directors &amp; Coordinators… (@ to mention)"
                                rows="1"
                                @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.sendMessage(); }"
                                @focus-input.window="$el.focus()"
                                x-init="
                                    $el.addEventListener('input', function () {
                                        this.style.height = 'auto';
                                        this.style.height = Math.min(this.scrollHeight, 130) + 'px';
                                    });
                                "
                                class="w-full resize-none rounded-2xl border border-gray-300 bg-gray-50
                                       px-4 py-3 text-sm leading-relaxed
                                       focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100
                                       transition"
                                style="max-height:130px; overflow-y:auto;"></textarea>
                        </div>
                        <button wire:click="sendMessage"
                                class="w-11 h-11 rounded-full flex items-center justify-center text-white flex-shrink-0
                                       transition hover:opacity-90 active:scale-95 shadow-sm"
                                style="background:#7a3f91;">
                            <i class="fa-solid fa-paper-plane text-base"></i>
                        </button>
                    </div>

                    <p class="text-xs text-gray-400 text-center mt-2">
                        <kbd class="bg-gray-100 border border-gray-200 rounded px-1.5 py-0.5 text-xs">Enter</kbd> send &nbsp;·&nbsp;
                        <kbd class="bg-gray-100 border border-gray-200 rounded px-1.5 py-0.5 text-xs">Shift+Enter</kbd> new line &nbsp;·&nbsp;
                        <kbd class="bg-gray-100 border border-gray-200 rounded px-1.5 py-0.5 text-xs">@</kbd> mention &nbsp;·&nbsp;
                        <span class="text-gray-300">tap message for actions</span>
                    </p>
                </div>
            </div>

            {{-- ── SIDE PANEL ───────────────────────────────────────────── --}}
            @if($showMembers || $showPins)
            <div class="w-72 border-l border-gray-200 flex flex-col flex-shrink-0 bg-white">

                <div class="flex items-center gap-2.5 px-4 py-3.5 border-b border-gray-200 flex-shrink-0 bg-gray-50">
                    @if($showPins)
                        <i class="fa-solid fa-thumbtack text-amber-500 text-base"></i>
                        <p class="text-sm font-black text-gray-800 flex-1">Pinned Messages</p>
                    @else
                        <i class="fa-solid fa-user-group text-purple-700 text-base"></i>
                        <p class="text-sm font-black text-gray-800 flex-1">
                            Staff Members
                            <span class="text-xs font-semibold text-gray-400 ml-1">
                                ({{ count($directors) + count($coordinators) }})
                            </span>
                            @if($onlineCount > 0)
                            <span class="ml-1 text-xs font-bold text-emerald-600">· {{ $onlineCount }} online</span>
                            @endif
                        </p>
                    @endif
                    <button wire:click="{{ $showPins ? 'togglePins' : 'toggleMembers' }}"
                            class="w-8 h-8 flex items-center justify-center rounded-full text-gray-400
                                   hover:text-gray-600 hover:bg-gray-200 transition">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto flex flex-col">

                    @if($showMembers)

                        {{-- Member search --}}
                        <div class="px-3 py-2.5 border-b border-gray-100 flex-shrink-0">
                            <div class="relative">
                                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2
                                          text-gray-400 text-xs pointer-events-none"></i>
                                <input wire:model.live.debounce.300ms="memberSearch"
                                       type="text"
                                       placeholder="Search staff…"
                                       class="w-full pl-8 pr-3 py-2.5 text-sm rounded-xl border border-gray-200
                                              bg-gray-50 focus:outline-none focus:border-purple-400
                                              focus:ring-1 focus:ring-purple-100 transition"/>
                            </div>
                        </div>

                        <div class="flex-1 overflow-y-auto px-3 pb-3 pt-3 space-y-1">

                            {{-- Directors section --}}
                            @if(! empty($directors))
                            <p class="text-xs font-black uppercase tracking-widest px-1 pb-1.5 text-purple-700">
                                <i class="fa-solid fa-shield-halved text-[10px] mr-1"></i>
                                Directors — {{ count($directors) }}
                            </p>

                            @foreach($directors as $dir)
                            <div class="flex items-center gap-2.5 rounded-xl px-3 py-2.5 mb-1 border transition-all
                                        {{ $dir['is_me']
                                            ? 'border-purple-200'
                                            : 'border-transparent hover:border-purple-100 hover:bg-purple-50/50' }}"
                                 style="{{ $dir['is_me'] ? 'background:#f3eef8;' : '' }}">
                                <div class="relative flex-shrink-0">
                                    <div class="w-9 h-9 rounded-full flex items-center justify-center
                                                text-sm font-black text-white overflow-hidden"
                                         style="background:#7a3f91;">
                                        @if($dir['photo'])
                                            <img src="{{ asset('storage/' . $dir['photo']) }}"
                                                 class="w-full h-full object-cover" alt="">
                                        @else
                                            {{ strtoupper(substr($dir['name'], 0, 1)) }}
                                        @endif
                                    </div>
                                    {{-- Online dot --}}
                                    @if($dir['is_online'] || $dir['is_me'])
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full
                                                 bg-emerald-400 border-2 border-white"></span>
                                    @else
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full
                                                 bg-gray-300 border-2 border-white"></span>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-bold text-gray-900 truncate">
                                        {{ $dir['name'] }}
                                        @if($dir['is_me'])
                                            <span class="text-xs text-purple-500 font-semibold">(You)</span>
                                        @endif
                                    </p>
                                    <p class="text-xs font-semibold {{ ($dir['is_online'] || $dir['is_me']) ? 'text-emerald-600' : 'text-gray-400' }}">
                                        {{ ($dir['is_online'] || $dir['is_me']) ? '🟢 Online' : '⚫ Offline' }}
                                    </p>
                                </div>
                            </div>
                            @endforeach
                            @endif

                            {{-- Coordinators section --}}
                            @if(! empty($coordinators))
                            <div class="pt-3 pb-1.5 px-1">
                                <p class="text-xs font-black uppercase tracking-widest text-purple-600">
                                    <i class="fa-solid fa-users text-[10px] mr-1"></i>
                                    Coordinators — {{ count($coordinators) }}
                                </p>
                            </div>

                            @php
                                $onlineCoords  = collect($coordinators)->where('is_online', true)->values();
                                $offlineCoords = collect($coordinators)->where('is_online', false)->values();
                            @endphp

                            {{-- Online coordinators --}}
                            @if(count($onlineCoords) > 0)
                            <p class="text-xs font-black text-emerald-600 uppercase tracking-widest px-1 pb-1">
                                <i class="fa-solid fa-circle text-[9px] mr-1"></i>Online — {{ count($onlineCoords) }}
                            </p>
                            @foreach($onlineCoords as $coord)
                            <div class="flex items-center gap-2.5 rounded-xl px-3 py-2.5 border border-gray-100
                                        hover:border-purple-200 hover:bg-purple-50/50 transition-all">
                                <div class="relative flex-shrink-0">
                                    <div class="w-9 h-9 rounded-full flex items-center justify-center
                                                text-sm font-black text-white overflow-hidden"
                                         style="background:#9333ea;">
                                        @if($coord['photo'])
                                            <img src="{{ asset('storage/' . $coord['photo']) }}"
                                                 class="w-full h-full object-cover" alt="">
                                        @else
                                            {{ strtoupper(substr($coord['name'], 0, 1)) }}
                                        @endif
                                    </div>
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full
                                                 bg-emerald-400 border-2 border-white"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-bold text-gray-900 truncate">{{ $coord['name'] }}</p>
                                    @if($coord['department'])
                                    <p class="text-xs text-purple-600 font-semibold truncate">{{ $coord['department'] }}</p>
                                    @else
                                    <p class="text-xs text-emerald-600 font-semibold">🟢 Online</p>
                                    @endif
                                </div>
                            </div>
                            @endforeach
                            @endif

                            {{-- Offline label --}}
                            @if(count($offlineCoords) > 0 && count($onlineCoords) > 0 && $memberSearch === '')
                            <div class="pt-2 pb-1 px-1">
                                <p class="text-xs font-black text-gray-400 uppercase tracking-widest">
                                    <i class="fa-solid fa-circle text-[9px] mr-1 opacity-40"></i>Offline — {{ count($offlineCoords) }}
                                </p>
                            </div>
                            @endif

                            {{-- Offline coordinators --}}
                            @foreach($offlineCoords as $coord)
                            <div class="flex items-center gap-2.5 rounded-xl px-3 py-2.5 border border-gray-100
                                        hover:border-gray-200 hover:bg-gray-50 transition-all opacity-70">
                                <div class="relative flex-shrink-0">
                                    <div class="w-9 h-9 rounded-full flex items-center justify-center
                                                text-sm font-black text-white overflow-hidden"
                                         style="background:#c4a8d4;">
                                        @if($coord['photo'])
                                            <img src="{{ asset('storage/' . $coord['photo']) }}"
                                                 class="w-full h-full object-cover" alt="">
                                        @else
                                            {{ strtoupper(substr($coord['name'], 0, 1)) }}
                                        @endif
                                    </div>
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full
                                                 bg-gray-300 border-2 border-white"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-bold text-gray-700 truncate">{{ $coord['name'] }}</p>
                                    @if($coord['department'])
                                    <p class="text-xs text-gray-400 font-semibold truncate">{{ $coord['department'] }}</p>
                                    @else
                                    <p class="text-xs text-gray-400 font-semibold">⚫ Offline</p>
                                    @endif
                                </div>
                            </div>
                            @endforeach
                            @endif

                            @if(empty($directors) && empty($coordinators))
                            <div class="flex flex-col items-center justify-center py-10 text-gray-400">
                                <i class="fa-solid fa-user-slash text-3xl text-gray-200 mb-3"></i>
                                <p class="text-sm font-semibold">No results</p>
                                <p class="text-xs mt-1">Try a different name</p>
                            </div>
                            @endif

                        </div>

                    @elseif($showPins)
                    <div class="flex-1 overflow-y-auto p-3 space-y-2.5">
                        @forelse($pinnedMessages as $pin)
                        <div class="rounded-xl border border-amber-200 bg-amber-50/50 p-3.5">
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <div class="flex items-center gap-1.5 min-w-0">
                                    <i class="fa-solid fa-thumbtack text-amber-500 text-xs flex-shrink-0"></i>
                                    <p class="text-sm font-bold text-amber-700 truncate">{{ $pin['from'] }}</p>
                                    @if(isset($pin['sender_type']))
                                    <span class="text-[10px] font-bold px-1.5 py-0.5 rounded flex-shrink-0
                                        {{ $pin['sender_type'] === 'director'
                                            ? 'bg-purple-100 text-purple-700'
                                            : 'bg-gray-100 text-gray-600' }}">
                                        {{ $pin['sender_type'] === 'director' ? 'Director' : 'Coordinator' }}
                                    </span>
                                    @endif
                                </div>
                                <button wire:click="togglePin({{ $pin['id'] }})"
                                        class="w-6 h-6 flex items-center justify-center rounded-full text-gray-400
                                               hover:text-red-500 hover:bg-red-50 transition flex-shrink-0">
                                    <i class="fa-solid fa-xmark text-xs"></i>
                                </button>
                            </div>
                            <p class="text-sm text-gray-800 leading-snug break-words">
                                {{ Str::limit($pin['body'], 140) }}
                            </p>
                            <p class="text-xs text-gray-400 mt-2">{{ $pin['pinned_at'] }}</p>
                        </div>
                        @empty
                        <div class="flex flex-col items-center justify-center py-14 text-gray-400">
                            <i class="fa-solid fa-thumbtack text-4xl text-gray-200 mb-3"></i>
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
         FALLBACK — room not found (shouldn't happen normally)
         ══════════════════════════════════════════════════════════════════ --}}
    @else
    <div class="flex flex-1 items-center justify-center bg-gray-50">
        <div class="flex flex-col items-center text-center px-8">
            <div class="w-24 h-24 rounded-2xl flex items-center justify-center mb-6"
                 style="background:#f3eef8;">
                <i class="fa-solid fa-shield-halved text-5xl" style="color:#7a3f91;"></i>
            </div>
            <p class="text-xl font-black text-gray-700">Setting up the channel…</p>
            <p class="text-sm text-gray-400 mt-2 max-w-xs leading-relaxed">
                The staff channel is being initialized. Please refresh the page.
            </p>
        </div>
    </div>
    @endif

</div>