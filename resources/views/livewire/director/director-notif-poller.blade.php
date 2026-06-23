{{-- resources/views/livewire/director/director-notif-poller.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

new class extends Component {

    public int    $directorId     = 0;
    public int    $directorUserId = 0;
    public int    $roomId         = 0;
    public int    $lastNotifiedId = 0;
    public int    $pollTick       = 0;

    // ─────────────────────────────────────────────────────────────────────
    // Cache key — MUST match director-messenger.blade.php
    // ─────────────────────────────────────────────────────────────────────
    private function cacheKey(): string
    {
        // Unified key: same as messenger so they share the watermark
        return "chat_notified.director.{$this->directorId}.room.{$this->roomId}";
    }

    // ─────────────────────────────────────────────────────────────────────
    // Mount
    // ─────────────────────────────────────────────────────────────────────
    public function mount(): void
    {
        $user = Auth::user();
        if (! $user || $user->role !== 'director') return;

        $director = DB::table('director')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->first();

        if (! $director) return;

        $this->directorId     = (int) $director->id;
        $this->directorUserId = (int) $director->user_id;

        // Resolve __director__ room
        $room = DB::table('chat_rooms')
            ->where('course_code', '__director__')
            ->first();

        if (! $room) return;

        $this->roomId = (int) $room->id;

        $this->seedPointer();
        $this->poll();

        // Trigger JS store re-fetch on mount
        $this->js("
            (function () {
                function tryFetch() {
                    if (window.__safeDirNotifsStore) {
                        var s = window.__safeDirNotifsStore();
                        if (s) { s._fetch(); return; }
                    }
                    setTimeout(tryFetch, 50);
                }
                tryFetch();
            })();
        ");
    }

    // ─────────────────────────────────────────────────────────────────────
    // Seed watermark pointer from cache
    // Only seeds if nothing cached yet — never overwrites an existing pointer
    // ─────────────────────────────────────────────────────────────────────
    private function seedPointer(): void
    {
        if (! $this->roomId) return;

        $cached = Cache::get($this->cacheKey());

        if ($cached === null) {
            // First visit ever — set pointer to current max so we don't
            // flood old messages as "new"
            $maxId = (int) (DB::table('chat_messages')
                ->where('room_id', $this->roomId)
                ->whereNull('deleted_at')
                ->max('id') ?? 0);
            $this->lastNotifiedId = $maxId;
            Cache::put($this->cacheKey(), $maxId, now()->addDays(30));
        } else {
            $this->lastNotifiedId = (int) $cached;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Poll — called on mount AND every 3s via wire:poll
    // ─────────────────────────────────────────────────────────────────────
    public function poll(): void
    {
        if (! $this->directorId || ! $this->directorUserId || ! $this->roomId) return;

        $this->pollTick++;

        // Re-sync pointer from cache — messenger may have advanced it
        $cached = Cache::get($this->cacheKey());
        if ($cached !== null) {
            $this->lastNotifiedId = max($this->lastNotifiedId, (int) $cached);
        }

        $lastKnown = $this->lastNotifiedId;

        // Fetch new messages NOT sent by this director
        $newMessages = DB::table('chat_messages')
            ->where('room_id', $this->roomId)
            ->whereNull('deleted_at')
            ->where('id', '>', $lastKnown)
            ->where(function ($q) {
                $q->where('sender_type', '!=', 'director')
                  ->orWhere('sender_id', '!=', $this->directorId);
            })
            ->orderBy('id')
            ->get(['id', 'sender_type', 'sender_id', 'body'])
            ->toArray();

        // Advance watermark for ALL new messages (including own) so pointer
        // stays current even when director is sending
        $globalMax = (int) (DB::table('chat_messages')
            ->where('room_id', $this->roomId)
            ->whereNull('deleted_at')
            ->where('id', '>', $lastKnown)
            ->max('id') ?? 0);

        if ($globalMax > $this->lastNotifiedId) {
            $this->lastNotifiedId = $globalMax;
            Cache::put($this->cacheKey(), $globalMax, now()->addDays(30));
        }

        if (empty($newMessages)) return;

        // Advance watermark to latest new OTHER-person message too
        $newMaxId = (int) max(array_column($newMessages, 'id'));
        if ($newMaxId > $this->lastNotifiedId) {
            $this->lastNotifiedId = $newMaxId;
            Cache::put($this->cacheKey(), $newMaxId, now()->addDays(30));
        }

        $count  = count($newMessages);
        $latest = end($newMessages);

        // Resolve ALL unique senders for a richer message
        $senderNames = $this->resolveUniqueSenderNames($newMessages);
        $senderLabel = $this->formatSenderLabel($senderNames, $count);

        // Build preview from latest message body
        $bodyRaw  = $latest->body ?? '';
        $bodySnip = mb_substr($bodyRaw, 0, 60) . (mb_strlen($bodyRaw) > 60 ? '…' : '');

        $msgText = $count > 1
            ? $senderLabel . ' sent ' . $count . ' new messages in Internal Staff Chat.'
            : $senderLabel . ' sent a message in Internal Staff Chat'
              . ($bodySnip !== '' ? ': "' . $bodySnip . '"' : '.');

        $notifTitle = $count > 1 ? $count . ' New Messages' : 'New Message';

        // Dedup key: per-sender per-room per-minute so multiple rapid messages
        // from the same person in the same minute merge into one notif row,
        // but a NEW minute (or different sender) always creates a fresh row.
        $senderKey = $latest->sender_type . '_' . $latest->sender_id;
        $dedupKey  = 'message-received::director.' . $this->directorId
                   . '::room.' . $this->roomId
                   . '::' . $senderKey
                   . '::' . floor(time() / 60);

        try {
            $exists = DB::table('director_notifications')
                ->where('user_id', $this->directorUserId)
                ->where('dedup_key', $dedupKey)
                ->exists();

            if ($exists) {
                // Update the existing row with the latest count + message
                DB::table('director_notifications')
                    ->where('user_id', $this->directorUserId)
                    ->where('dedup_key', $dedupKey)
                    ->update([
                        'title'      => $notifTitle,
                        'message'    => $msgText,
                        'read'       => 0,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('director_notifications')->insert([
                    'user_id'    => $this->directorUserId,
                    'icon'       => 'comments',
                    'title'      => $notifTitle,
                    'message'    => $msgText,
                    'link_route' => 'director.director/messenger',
                    'link_label' => 'Open Chat',
                    'dedup_key'  => $dedupKey,
                    'read'       => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->dispatch('dir-notif-refresh');

        } catch (\Throwable) {
            // Fallback: dispatch JS event so layout can handle it
            $this->dispatch('dir-message-received', [
                'sender' => $senderLabel,
                'room'   => 'Internal Staff Chat',
                'body'   => $bodySnip,
                'count'  => $count,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Resolve unique sender display names from a list of message rows
    // ─────────────────────────────────────────────────────────────────────
    private function resolveUniqueSenderNames(array $messages): array
    {
        $seen  = [];
        $names = [];

        foreach ($messages as $msg) {
            $key = $msg->sender_type . '_' . $msg->sender_id;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $names[]    = $this->resolveSenderName($msg);
        }

        return $names;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Format sender label: "Ana, Ben, and 3 others" style
    // ─────────────────────────────────────────────────────────────────────
    private function formatSenderLabel(array $names, int $msgCount): string
    {
        if (empty($names)) return 'Someone';

        $uniqueCount = count($names);

        if ($uniqueCount === 1) return $names[0];
        if ($uniqueCount === 2) return $names[0] . ' and ' . $names[1];

        $extra = $uniqueCount - 2;
        return $names[0] . ', ' . $names[1] . ', and ' . $extra . ' other' . ($extra > 1 ? 's' : '');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Resolve single sender display name
    // ─────────────────────────────────────────────────────────────────────
    private function resolveSenderName(object $msg): string
    {
        if (in_array($msg->sender_type, ['coordinator', 'organizer'])) {
            $row = DB::table('organizer')
                ->where('id', $msg->sender_id)
                ->first(['first_name', 'last_name']);
            if ($row) {
                $name = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));
                return $name ?: 'Coordinator';
            }
            return 'Coordinator';
        }

        if ($msg->sender_type === 'director') {
            $row = DB::table('director')
                ->where('id', $msg->sender_id)
                ->first(['first_name', 'last_name']);
            if ($row) {
                $name = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));
                return $name ?: 'Director';
            }
            return 'Director';
        }

        return 'Someone';
    }
};
?>

{{-- Invisible — pure background poller, no UI --}}
<div wire:poll.3000ms="poll" class="hidden" aria-hidden="true"></div>