<?php

namespace App\Http\Controllers;

use App\Models\Alumni;
use App\Models\AlumniNotification;
use App\Models\AdminEvent;
use App\Models\JobPosting;
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
    //  DELETE /alumni/notifications/{id}
    //
    //  Deletes a notification MESSAGE only — never the underlying alumni
    //  record, job posting, or event that generated it. This just removes
    //  the row from `alumni_notifications` so the panel/list gets shorter;
    //  whatever the notif was ABOUT is completely untouched.
    //
    //  Scoped via forAlumni($alumni->id) — same guard as markRead()/
    //  markAllRead() above — so one alumni can never delete another
    //  alumni's notification row by guessing/changing the id in the URL.
    //
    //  The frontend only ever shows the delete button once a notif is
    //  30+ days old (see sidebar-alumni.blade.php), but that's a UI-only
    //  gate — this endpoint doesn't re-check the age itself, matching how
    //  the registrar side's equivalent delete endpoint works.
    // ─────────────────────────────────────────────────────────────────────────
    public function destroy(int $id): JsonResponse
    {
        $alumni = Auth::user()?->alumni;
        if (!$alumni) return response()->json(['error' => 'Unauthenticated'], 401);

        $deleted = AlumniNotification::forAlumni($alumni->id)
            ->where('id', $id)
            ->delete();

        if (!$deleted) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PUBLIC: Notify all alumni targeted by a newly-posted job.
    //
    //  Called SERVER-SIDE from OrganizerJobManagement::savePost() right after
    //  the JobPosting row is created — this is NOT triggered by a client-side
    //  `window` event.
    //
    //  ROOT CAUSE THIS FIXES: the old code only did
    //  $this->dispatch('job-posted', [...]) from the ORGANIZER's Livewire
    //  component. Livewire's dispatch() fires a `window` CustomEvent inside
    //  whichever browser tab the organizer is using. The alumni layout's
    //  `job-posted` window.addEventListener(...) lives in a completely
    //  different person's browser session (a different device/tab/login
    //  entirely) — there is no realtime transport (no broadcast/Pusher/Echo)
    //  connecting the organizer's tab to the alumni's tab, so that listener
    //  could never fire. That's why "New Job Posting" never appeared in the
    //  alumni bell no matter how many jobs were posted — profile-updated /
    //  employment-updated worked fine because in those cases the SAME person
    //  (the alumni) is the one dispatching AND listening, in the same tab.
    //
    //  FIX: insert the notification rows directly here, on the server, for
    //  every alumni in the job's target college(s) — the same approach
    //  already used for event notifications via syncEventNotifications()
    //  below, just triggered at post-time instead of at fetch-time.
    //
    //  alumni_notifications.alumni_id is per-row (see migration), so every
    //  targeted alumni needs their own notification row. This bulk-inserts
    //  them in chunks instead of firing N individual ->create() calls.
    // ─────────────────────────────────────────────────────────────────────────
    public function notifyAlumniOfNewJob(JobPosting $job): void
    {
        if (empty($job->target_college)) return;

        $colleges = array_values(array_filter(array_map('trim', explode(',', $job->target_college))));
        if (empty($colleges)) return;

        $dedupKey = 'job-posted::' . $job->id;

        // Alumni IDs already notified for this exact job — safety net in case
        // this ever runs twice for the same job (e.g. a retried request).
        $alreadyNotified = AlumniNotification::where('dedup_key', $dedupKey)
            ->pluck('alumni_id')
            ->all();

        $targetAlumniIds = Alumni::whereHas('course', function ($q) use ($colleges) {
                $q->whereIn('college', $colleges);
            })
            ->when(!empty($alreadyNotified), fn ($q) => $q->whereNotIn('id', $alreadyNotified))
            ->pluck('id');

        if ($targetAlumniIds->isEmpty()) return;

        $message = $job->job_title . ' at ' . $job->company_name .
                   ($job->location ? ' — ' . $job->location : '') . '.';

        $now = now();

        $rows = $targetAlumniIds->map(fn ($alumniId) => [
            'alumni_id'  => $alumniId,
            'icon'       => 'briefcase',
            'title'      => 'New Job Posting',
            'message'    => $message,
            'link_route' => 'job.opportunities',
            'link_label' => 'View Job',
            'dedup_key'  => $dedupKey,
            'read'       => false,
            'count'      => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        // Bulk insert in chunks — avoids N individual queries when a college
        // has many alumni, and avoids one giant query if the list is huge.
        foreach (array_chunk($rows, 500) as $chunk) {
            AlumniNotification::insert($chunk);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PUBLIC: Notify all alumni targeted by a job posting that was just
    //  turned back ACTIVE (INACTIVE → ACTIVE via the organizer's Activate
    //  button, or a restore that lands back on ACTIVE).
    //
    //  Called SERVER-SIDE from OrganizerJobManagement::executeToggleStatus()
    //  and ::executeRestoreJob() — same reasoning as notifyAlumniOfNewJob()
    //  above: a client-side `window` event in the organizer's tab can never
    //  reach a different alumni's browser session, so this has to happen on
    //  the server at the moment the status actually flips.
    //
    //  Deliberately reuses the EXACT SAME 'job-posted::{id}' dedup_key,
    //  title ("New Job Posting"), and icon ('briefcase') as
    //  notifyAlumniOfNewJob() above — a re-activated job and a brand-new job
    //  are the same kind of event to the alumni ("the job is open now"), so
    //  they land in the same notification / counter instead of being split
    //  into a separate "Job Activated" type.
    //
    //  ── BUG FIXED HERE ──────────────────────────────────────────────────
    //  Because the dedup_key is identical to the original posting's, if that
    //  alumni was already notified when the job was first created (and
    //  hasn't had that row deleted), the store()-style "increment on match"
    //  behavior is replicated: bump count + flip back to unread on existing
    //  rows, and only insert fresh rows for alumni who never got the
    //  original notification.
    //
    //  BUT the old version only touched `updated_at`, `read`, and `count` on
    //  the existing row — it never refreshed `created_at`. Every list in the
    //  UI (index() query, the JS store's sort, and the "Jul 17" timestamp
    //  rendered in the panel) is driven off `created_at`. So a job that was
    //  posted yesterday, then deactivated, then re-activated today, kept
    //  showing yesterday's date and could get buried under newer
    //  notifications (or look like nothing new happened) — even though it
    //  WAS flipped back to unread in the database.
    //
    //  FIX: also bump `created_at` to `now()` when reusing an existing row,
    //  so a reactivated job reads as a brand-new notification (today's date,
    //  top of the list) exactly like a first-time post would.
    // ─────────────────────────────────────────────────────────────────────────
    public function notifyAlumniOfActivatedJob(JobPosting $job): void
    {
        if (empty($job->target_college)) return;

        $colleges = array_values(array_filter(array_map('trim', explode(',', $job->target_college))));
        if (empty($colleges)) return;

        $dedupKey = 'job-posted::' . $job->id;

        $targetAlumniIds = Alumni::whereHas('course', function ($q) use ($colleges) {
                $q->whereIn('college', $colleges);
            })
            ->pluck('id');

        if ($targetAlumniIds->isEmpty()) return;

        // Alumni who already have a notification row for this exact job
        // (from the original post) — bump those instead of duplicating.
        $existingRows = AlumniNotification::where('dedup_key', $dedupKey)
            ->whereIn('alumni_id', $targetAlumniIds)
            ->get(['id', 'alumni_id']);

        $existingAlumniIds = $existingRows->pluck('alumni_id')->all();
        $now = now();

        if ($existingRows->isNotEmpty()) {
            AlumniNotification::whereIn('id', $existingRows->pluck('id'))
                ->update([
                    'read'       => false,
                    'count'      => DB::raw('count + 1'),
                    // FIX: refresh created_at too — not just updated_at —
                    // so the notification sorts and displays as NEW (today),
                    // instead of keeping the original post date.
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $newAlumniIds = $targetAlumniIds->diff($existingAlumniIds);
        if ($newAlumniIds->isEmpty()) return;

        $message = $job->job_title . ' at ' . $job->company_name .
                   ($job->location ? ' — ' . $job->location : '') . '.';

        $rows = $newAlumniIds->map(fn ($alumniId) => [
            'alumni_id'  => $alumniId,
            'icon'       => 'briefcase',
            'title'      => 'New Job Posting',
            'message'    => $message,
            'link_route' => 'job.opportunities',
            'link_label' => 'View Job',
            'dedup_key'  => $dedupKey,
            'read'       => false,
            'count'      => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            AlumniNotification::insert($chunk);
        }
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