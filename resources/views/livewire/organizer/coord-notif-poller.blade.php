{{-- resources/views/livewire/organizer/coord-notif-poller.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

new class extends Component {

    public int    $coordinatorId   = 0;
    public int    $coordinatorUserId = 0;  // ← user_id for coordinator_notifications table
    public string $department      = '';
    public array  $deptCourseCodes = [];
    public array  $lastNotifiedIds = [];
    public int    $pollTick        = 0;

    public int $mountUnreadCount = 0;

    // ─────────────────────────────────────────────────────────────────────
    // Cache key helpers
    // ─────────────────────────────────────────────────────────────────────
    private function lastNotifiedCacheKey(int $roomId): string
    {
        return "chat_notified.organizer.{$this->coordinatorId}.room.{$roomId}";
    }

    // ─────────────────────────────────────────────────────────────────────
    // Mount
    // ─────────────────────────────────────────────────────────────────────
    public function mount(): void
    {
        $user = Auth::user();
        if (! $user || $user->role !== 'organizer') return;

        $organizer = DB::table('organizer')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->first();

        if (! $organizer) return;

        $this->coordinatorId     = (int) $organizer->id;
        $this->coordinatorUserId = (int) $organizer->user_id;  // ← correct user_id
        $this->department        = $organizer->department ?? '';
        $this->deptCourseCodes   = DB::table('courses')
            ->where('college', $this->department)
            ->pluck('code')
            ->toArray();

        $this->seedPointers();
        $this->poll();

        $this->js("
            (function () {
                function tryFetch() {
                    if (window.__safeCoordNotifsStore) {
                        var s = window.__safeCoordNotifsStore();
                        if (s) { s._fetch(); return; }
                    }
                    setTimeout(tryFetch, 50);
                }
                tryFetch();
            })();
        ");
    }

    // ─────────────────────────────────────────────────────────────────────
    // Seed pointers from cache
    // ─────────────────────────────────────────────────────────────────────
    private function seedPointers(): void
    {
        $rooms = $this->getAllRelevantRooms();

        foreach ($rooms as $room) {
            $roomId = (int) $room->id;
            $cached = Cache::get($this->lastNotifiedCacheKey($roomId));

            if ($cached === null) {
                $maxId = (int) (DB::table('chat_messages')
                    ->where('room_id', $roomId)
                    ->whereNull('deleted_at')
                    ->max('id') ?? 0);
                $this->lastNotifiedIds[$roomId] = $maxId;
                Cache::put($this->lastNotifiedCacheKey($roomId), $maxId, now()->addDays(30));
            } else {
                $this->lastNotifiedIds[$roomId] = (int) $cached;
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Get all rooms this coordinator should monitor
    // ─────────────────────────────────────────────────────────────────────
    private function getAllRelevantRooms(): \Illuminate\Support\Collection
    {
        $rooms = collect();

        $staff = DB::table('chat_rooms')->where('course_code', '__director__')->first();
        if ($staff) $rooms->push($staff);

        if ($this->department) {
            $college = DB::table('chat_rooms')
                ->where('department', $this->department)
                ->where('course_code', '')
                ->where('batch', 0)
                ->first();
            if ($college) $rooms->push($college);
        }

        if (! empty($this->deptCourseCodes)) {
            $others = DB::table('chat_rooms')
                ->where('course_code', '!=', '__director__')
                ->where('course_code', '!=', '')
                ->where(function ($q) {
                    $q->where('department', $this->department)
                      ->orWhereIn('course_code', $this->deptCourseCodes);
                })
                ->get();
            $rooms = $rooms->merge($others);
        }

        return $rooms->unique('id');
    }

    private function resolveSenderName(object $msg): string
    {
        if ($msg->sender_type === 'alumni') {
            return DB::table('alumni')->where('id', $msg->sender_id)->value('first_name') ?? 'Alumni';
        } elseif ($msg->sender_type === 'director') {
            $name = DB::table('director')->where('id', $msg->sender_id)->value('first_name');
            return ($name ?? 'Director') . ' (Director)';
        } else {
            $name = DB::table('organizer')->where('id', $msg->sender_id)->value('first_name');
            return ($name ?? 'Coordinator') . ' (Coordinator)';
        }
    }

    private function resolveRoomLabel(object $room): string
    {
        if ($room->course_code === '__director__') return 'Staff Chat';
        if ($room->course_code === '' && (int) $room->batch === 0) {
            return ($this->department ?: 'College') . ' · All Courses & Batches';
        }
        if ((int) $room->batch === 0) {
            return strtoupper($room->course_code) . ' · All Batches GC';
        }
        return $room->name ?? (strtoupper($room->course_code) . ' · Batch ' . $room->batch);
    }

    private function getActiveRoomId(): int
    {
        return (int) Cache::get("chat_active_room.organizer.{$this->coordinatorId}", 0);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Poll — called on mount AND every 3s via wire:poll
    // ─────────────────────────────────────────────────────────────────────
    public function poll(): void
    {
        if (! $this->coordinatorId || ! $this->coordinatorUserId) return;

        $this->pollTick++;

        $rooms        = $this->getAllRelevantRooms();
        $activeRoomId = $this->getActiveRoomId();
        $anyNew       = false;

        foreach ($rooms as $room) {
            $roomId = (int) $room->id;

            if (! isset($this->lastNotifiedIds[$roomId])) {
                $maxId = (int) (DB::table('chat_messages')
                    ->where('room_id', $roomId)
                    ->whereNull('deleted_at')
                    ->max('id') ?? 0);
                $this->lastNotifiedIds[$roomId] = $maxId;
                Cache::put($this->lastNotifiedCacheKey($roomId), $maxId, now()->addDays(30));
                continue;
            }

            $lastKnown = (int) $this->lastNotifiedIds[$roomId];

            $newMessages = DB::table('chat_messages')
                ->where('room_id', $roomId)
                ->whereNull('deleted_at')
                ->where('id', '>', $lastKnown)
                ->where(function ($q) {
                    $q->where('sender_type', '!=', 'organizer')
                      ->orWhere('sender_id', '!=', $this->coordinatorId);
                })
                ->orderBy('id')
                ->get(['id', 'sender_type', 'sender_id', 'body'])
                ->toArray();

            if (empty($newMessages)) {
                $myNewMax = (int) (DB::table('chat_messages')
                    ->where('room_id', $roomId)
                    ->whereNull('deleted_at')
                    ->where('id', '>', $lastKnown)
                    ->max('id') ?? 0);
                if ($myNewMax > $lastKnown) {
                    $this->lastNotifiedIds[$roomId] = $myNewMax;
                    Cache::put($this->lastNotifiedCacheKey($roomId), $myNewMax, now()->addDays(30));
                }
                continue;
            }

            $newMaxId = (int) max(array_column($newMessages, 'id'));
            $this->lastNotifiedIds[$roomId] = $newMaxId;
            Cache::put($this->lastNotifiedCacheKey($roomId), $newMaxId, now()->addDays(30));

            if ($roomId === $activeRoomId) continue;

            $latest     = end($newMessages);
            $count      = count($newMessages);
            $senderName = $this->resolveSenderName($latest);
            $roomLabel  = $this->resolveRoomLabel($room);
            $bodySnip   = mb_substr($latest->body ?? '', 0, 60);
            $preview    = $bodySnip . (mb_strlen($latest->body ?? '') > 60 ? '…' : '');

            $msgText = $count > 1
                ? $senderName . ' and others sent ' . $count . ' new messages in ' . $roomLabel . '.'
                : $senderName . ' sent a message in ' . $roomLabel
                  . ($preview !== '' ? ': "' . $preview . '"' : '.');

            $notifTitle = $count > 1 ? $count . ' New Messages' : 'New Message';
            $dedupKey   = 'message-received::' . $roomId . '::' . floor(time() / 60);

            try {
                $exists = DB::table('coordinator_notifications')
                    ->where('user_id', $this->coordinatorUserId)   // ← FIXED: use user_id
                    ->where('dedup_key', $dedupKey)
                    ->exists();

                if (! $exists) {
                    DB::table('coordinator_notifications')->insert([
                        'user_id'    => $this->coordinatorUserId,  // ← FIXED: use user_id
                        'icon'       => 'comments',
                        'title'      => $notifTitle,
                        'message'    => $msgText,
                        'link_route' => 'organizer.chat/alumni',
                        'link_label' => 'Open Messages',
                        'dedup_key'  => $dedupKey,
                        'read'       => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $anyNew = true;
                }
            } catch (\Throwable) {
                $this->dispatch('coord-message-received', [
                    'sender' => $senderName,
                    'room'   => $roomLabel,
                    'body'   => mb_substr($latest->body ?? '', 0, 60),
                    'count'  => $count,
                ]);
            }
        }

        if ($anyNew) {
            $this->dispatch('coord-notif-refresh');
        }
    }
};
?>

{{-- Invisible — pure background poller, no UI --}}
<div wire:poll.3000ms="poll" class="hidden" aria-hidden="true"></div>