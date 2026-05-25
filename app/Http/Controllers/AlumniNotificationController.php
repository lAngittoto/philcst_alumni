<?php

namespace App\Http\Controllers;

use App\Models\AlumniNotification;
use App\Models\AdminEvent;
use App\Models\OrganizerEvent;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AlumniNotificationController extends Controller
{
    // ── How many notifications to return to the panel ────────────────────────
    private const FETCH_LIMIT = 40;

    // ── How many days back to look for "new events" on each fetch ────────────
    private const EVENT_LOOKBACK_DAYS = 30;

    // ─────────────────────────────────────────────────────────────────────────
    //  GET /alumni/notifications
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $alumni = Auth::user()?->alumni;
        if (!$alumni) return response()->json([]);

        // ── 1. Auto-inject new event notifications ────────────────────────────
        $this->syncEventNotifications($alumni);

        // ── 2. Return notifications for the panel ─────────────────────────────
        $rows = AlumniNotification::forAlumni($alumni->id)
            ->orderByDesc('created_at')
            ->limit(self::FETCH_LIMIT)
            ->get()
            ->map(fn ($n) => [
                'id'          => $n->id,
                '_ids'        => [$n->id],
                'icon'        => $n->icon,
                'title'       => $n->title,
                'message'     => $n->message,
                'link_route'  => $n->link_route,
                'link_label'  => $n->link_label,
                'read'        => $n->read,
                'count'       => (int) $n->count,
                'dedup_key'   => $n->dedup_key,
                'created_at'  => $n->created_at?->toISOString(),
            ]);

        return response()->json($rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  POST /alumni/notifications
    //  Called by the JS bridge for non-event notifications (profile, employment, message)
    // ─────────────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $alumni = Auth::user()?->alumni;
        if (!$alumni) return response()->json(['error' => 'Unauthenticated'], 401);

        $validated = $request->validate([
            'icon'        => 'nullable|string|max:60',
            'title'       => 'required|string|max:160',
            'message'     => 'nullable|string|max:500',
            'link_route'  => 'nullable|string|max:120',
            'link_label'  => 'nullable|string|max:80',
            'dedup_key'   => 'nullable|string|max:200',
        ]);

        $dedupKey = $validated['dedup_key'] ?? null;

        if ($dedupKey) {
            // Today's window for client-fired dedup keys
            $todayStart = Carbon::today('UTC');

            $existing = AlumniNotification::forAlumni($alumni->id)
                ->where('dedup_key', $dedupKey)
                ->where('created_at', '>=', $todayStart)
                ->first();

            if ($existing) {
                $existing->increment('count');
                $existing->update(['read' => false, 'updated_at' => now()]);
                return response()->json(['id' => $existing->id, 'action' => 'incremented']);
            }
        }

        $notif = AlumniNotification::create([
            'alumni_id'  => $alumni->id,
            'icon'       => $validated['icon']       ?? 'bell',
            'title'      => $validated['title'],
            'message'    => $validated['message']    ?? '',
            'link_route' => $validated['link_route'] ?? null,
            'link_label' => $validated['link_label'] ?? null,
            'dedup_key'  => $dedupKey,
            'read'       => false,
            'count'      => 1,
        ]);

        return response()->json(['id' => $notif->id, 'action' => 'created'], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PATCH /alumni/notifications/{id}/read
    // ─────────────────────────────────────────────────────────────────────────
    public function markRead(int $id): JsonResponse
    {
        $alumni = Auth::user()?->alumni;
        if (!$alumni) return response()->json(['error' => 'Unauthenticated'], 401);

        AlumniNotification::forAlumni($alumni->id)
            ->where('id', $id)
            ->update(['read' => true]);

        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PATCH /alumni/notifications/read-all
    // ─────────────────────────────────────────────────────────────────────────
    public function markAllRead(): JsonResponse
    {
        $alumni = Auth::user()?->alumni;
        if (!$alumni) return response()->json(['error' => 'Unauthenticated'], 401);

        AlumniNotification::forAlumni($alumni->id)
            ->where('read', false)
            ->update(['read' => true]);

        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PRIVATE: Auto-sync event notifications for this alumni
    //  Runs on every GET /alumni/notifications so the panel stays current.
    // ─────────────────────────────────────────────────────────────────────────
    private function syncEventNotifications($alumni): void
    {
        $college = $alumni->course?->college ?? null;
        $courses = $alumni->course ? [$alumni->course->code] : [];

        if (!$college && empty($courses)) return;

        $since = Carbon::now('UTC')->subDays(self::EVENT_LOOKBACK_DAYS);

        // ── Admin events ──────────────────────────────────────────────────────
        $adminEvents = AdminEvent::withoutTrashed()
            ->whereIn('status', ['APPROVED', 'COMPLETED'])
            ->where('created_at', '>=', $since)
            ->where(function ($q) use ($college) {
                $q->where('target_participants', 'like', 'All Colleges%')
                  ->orWhere('target_participants', 'like', "%{$college}%");
            })
            ->select('id', 'title', 'event_date', 'venue', 'status', 'created_at')
            ->get();

        // ── Organizer events ──────────────────────────────────────────────────
        $organizerEvents = OrganizerEvent::whereIn('status', ['APPROVED', 'COMPLETED'])
            ->where('created_at', '>=', $since)
            ->where(function ($q) use ($courses) {
                $q->where('target_participants', 'like', 'All Courses%');
                foreach ($courses as $course) {
                    $q->orWhere('target_participants', 'like', "%{$course}%");
                }
            })
            ->select('id', 'title', 'event_date', 'venue', 'status', 'created_at')
            ->get();

        $allEvents = $adminEvents->map(fn ($e) => (object)[
            'id'         => $e->id,
            'source'     => 'ADMIN',
            'title'      => $e->title,
            'event_date' => $e->event_date,
            'venue'      => $e->venue,
            'status'     => $e->status,
            'created_at' => $e->created_at,
        ])->concat(
            $organizerEvents->map(fn ($e) => (object)[
                'id'         => $e->id,
                'source'     => 'ORGANIZER',
                'title'      => $e->title,
                'event_date' => $e->event_date,
                'venue'      => $e->venue,
                'status'     => $e->status,
                'created_at' => $e->created_at,
            ])
        );

        foreach ($allEvents as $event) {
            $dedupKey = 'event-announced::' . $event->source . '::' . $event->id;

            $exists = AlumniNotification::forAlumni($alumni->id)
                ->where('dedup_key', $dedupKey)
                ->exists();

            if ($exists) continue;

            $isCompleted = $event->status === 'COMPLETED';
            $datePH = Carbon::parse($event->event_date)->setTimezone('Asia/Manila');

            AlumniNotification::create([
                'alumni_id'  => $alumni->id,
                'icon'       => $isCompleted ? 'circle-check' : 'calendar',
                'title'      => $isCompleted ? 'Event Completed' : 'New Event Announced',
                'message'    => ($isCompleted ? '✅ ' : '📅 ') .
                                $event->title .
                                ($event->venue ? ' at ' . $event->venue : '') .
                                ' on ' . $datePH->format('M d, Y') . '.',
                'link_route' => 'upcoming.events',
                'link_label' => $isCompleted ? 'View Recap' : 'View Events',
                'dedup_key'  => $dedupKey,
                'read'       => false,
                'count'      => 1,
            ]);
        }
    }
}