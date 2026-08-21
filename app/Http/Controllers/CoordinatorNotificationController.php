<?php

namespace App\Http\Controllers;

use App\Models\CoordinatorNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CoordinatorNotificationController extends Controller
{
    /**
     * Return all notifications for the authenticated organizer/coordinator,
     * newest first, limited to the last 60 records.
     */
    public function index()
    {
        $notifications = CoordinatorNotification::where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->limit(60)
            ->get();

        return response()->json($notifications);
    }

    /**
     * Store a new notification (called from the JS frontend via fetch POST).
     * Handles dedup_key so the same event isn't stored multiple times
     * within a short window (5 minutes).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'icon'       => 'nullable|string|max:60',
            'title'      => 'required|string|max:191',
            'message'    => 'required|string|max:1000',
            'link_route' => 'nullable|string|max:191',
            'link_label' => 'nullable|string|max:100',
            'dedup_key'  => 'nullable|string|max:191',
            // ── event_id: the underlying OrganizerEvent id for event-related
            //    notifs (submit/resubmit/approved/rejected). Lets the
            //    frontend build a ?highlight_event={id} deep-link so
            //    clicking the notif opens that exact event's View Details
            //    instead of just landing on the events list. Nullable —
            //    non-event notifs (jobs, chat, alumni) omit it entirely. ──
            'event_id'   => 'nullable|integer',
            // ── job_id: same idea as event_id above, but for the underlying
            //    JobPosting id on job-related notifs. Lets the frontend
            //    build a ?highlight_job={id} deep-link so clicking a job
            //    notification opens that exact job's View/Edit Details
            //    instead of just landing on the jobs table. Nullable —
            //    notifs where the job was deleted, or non-job notifs,
            //    omit it entirely. ──
            'job_id'     => 'nullable|integer',
        ]);

        $userId = Auth::id();

        // ── Dedup: skip if same dedup_key was written in the last 5 minutes ──
        if (!empty($data['dedup_key'])) {
            $exists = CoordinatorNotification::where('user_id', $userId)
                ->where('dedup_key', $data['dedup_key'])
                ->where('created_at', '>=', now()->subMinutes(5))
                ->exists();

            if ($exists) {
                return response()->json(['status' => 'skipped'], 200);
            }
        }

        $notification = CoordinatorNotification::create([
            'user_id'    => $userId,
            'icon'       => $data['icon']       ?? 'bell',
            'title'      => $data['title'],
            'message'    => $data['message'],
            'link_route' => $data['link_route'] ?? null,
            'link_label' => $data['link_label'] ?? null,
            'dedup_key'  => $data['dedup_key']  ?? null,
            'event_id'   => $data['event_id']   ?? null,
            'job_id'     => $data['job_id']     ?? null,
            'read'       => false,
        ]);

        return response()->json($notification, 201);
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(CoordinatorNotification $n)
    {
        // Ensure the notification belongs to the authenticated user
        abort_if($n->user_id !== Auth::id(), 403);

        $n->update(['read' => true]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Mark ALL notifications for the current user as read.
     */
    public function markAllRead()
    {
        CoordinatorNotification::where('user_id', Auth::id())
            ->where('read', false)
            ->update(['read' => true]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Delete a single notification MESSAGE only — never the underlying
     * event/job/chat data it was about. Available for any notification
     * from the delete button in the panel.
     */
    public function destroy(CoordinatorNotification $n)
    {
        // Ensure the notification belongs to the authenticated user
        abort_if($n->user_id !== Auth::id(), 403);

        $n->delete();

        return response()->json(['status' => 'ok']);
    }
}