<?php

namespace App\Http\Controllers;

use App\Models\AdminEvent;
use App\Models\Director;
use App\Models\DirectorNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DirectorNotificationController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────────
    //  Helper — resolve the director_id to use for queries
    //
    //  Strategy (in order):
    //  1. Auth user has a loaded director() relationship  → use director->id
    //  2. director_notifications uses user_id directly    → use user->id
    //
    //  We try option 1 first; if it returns null we fall back to option 2
    //  so the controller works whether or not a directors table/profile exists.
    // ──────────────────────────────────────────────────────────────────────────

    private function resolveDirectorId(): int
    {
        $user = Auth::user();

        abort_unless($user && $user->isDirector(), 403, 'Access denied.');

        // Option 1 — separate directors table with user_id FK
        if (method_exists($user, 'director') && $user->director) {
            return $user->director->id;
        }

        // Option 2 — no directors table, use user id directly
        return $user->id;
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  GET /director/notifications
    // ──────────────────────────────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $directorId = $this->resolveDirectorId();

        $notifications = DirectorNotification::forDirector($directorId)
            ->orderBy('created_at', 'desc')
            ->limit(80)
            ->get([
                'id', 'icon', 'title', 'message',
                'link_route', 'link_label', 'dedup_key',
                'event_id', 'count', 'read', 'created_at',
            ]);

        // ── Attach live_status per row ───────────────────────────────────
        // The sidebar bell (__dirEventReviewTitle in sidebar-director_blade.php)
        // rebuilds the "Event Submitted → <status>" title from THIS field,
        // not from whatever the stored `title` column says — that's what
        // makes the bell immune to stale/out-of-sync title text. But this
        // endpoint was never actually sending it, so every event review
        // notif fell through to the JS fallback, which defaults to
        // "Pending" whenever the stored title has no "→ <status>" suffix
        // yet. Result: an event could be APPROVED/COMPLETED in the events
        // table while its bell notif still displayed "→ Pending" forever.
        //
        // Pull the real current status for every distinct event_id on this
        // page in ONE query (mirrors the same AdminEvent::withTrashed()
        // ->whereIn(...)->pluck('status','id') pattern already used by
        // syncStaleReviewNotifTitles() in manage-event_blade.php), then
        // stamp it onto each row as `live_status` before returning.
        $eventIds = $notifications->pluck('event_id')->filter()->unique()->values();

        if ($eventIds->isNotEmpty()) {
            $liveStatuses = AdminEvent::withTrashed()
                ->whereIn('id', $eventIds)
                ->pluck('status', 'id');

            $notifications->each(function ($n) use ($liveStatuses) {
                $n->live_status = $n->event_id ? ($liveStatuses[$n->event_id] ?? null) : null;
            });
        } else {
            $notifications->each(function ($n) {
                $n->live_status = null;
            });
        }

        return response()->json($notifications);
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  POST /director/notifications
    // ──────────────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $directorId = $this->resolveDirectorId();

        $validated = $request->validate([
            'icon'       => ['nullable', 'string', 'max:64'],
            'title'      => ['required', 'string', 'max:255'],
            'message'    => ['required', 'string', 'max:1000'],
            'link_route' => ['nullable', 'string', 'max:255'],
            'link_label' => ['nullable', 'string', 'max:100'],
            'dedup_key'  => ['nullable', 'string', 'max:255'],
        ]);

        $notification = DirectorNotification::createOrIncrement($directorId, $validated);

        return response()->json($notification, 201);
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  PATCH /director/notifications/{notification}/read
    // ──────────────────────────────────────────────────────────────────────────

    public function markRead(DirectorNotification $notification): JsonResponse
    {
        $directorId = $this->resolveDirectorId();

        abort_unless($notification->director_id === $directorId, 403, 'Unauthorized.');

        $notification->update(['read' => true]);

        return response()->json(['success' => true]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  PATCH /director/notifications/read-all
    // ──────────────────────────────────────────────────────────────────────────

    public function markAllRead(): JsonResponse
    {
        $directorId = $this->resolveDirectorId();

        DirectorNotification::forDirector($directorId)
            ->unread()
            ->update(['read' => true]);

        return response()->json(['success' => true]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  DELETE /director/notifications/{notification}
    // ──────────────────────────────────────────────────────────────────────────

    public function destroy(DirectorNotification $notification): JsonResponse
    {
        $directorId = $this->resolveDirectorId();

        abort_unless($notification->director_id === $directorId, 403, 'Unauthorized.');

        $notification->delete();

        return response()->json(['success' => true]);
    }
}