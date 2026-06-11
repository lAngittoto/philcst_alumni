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
}