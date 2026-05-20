<?php

namespace App\Http\Controllers;

use App\Models\RegistrarNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class RegistrarNotificationController extends Controller
{
    /**
     * GET /registrar/notifications
     * Returns all notifications for the registrar, newest first.
     */
    public function index()
    {
        $items = RegistrarNotification::orderByDesc('created_at')->get();
        return response()->json($items);
    }

    /**
     * POST /registrar/notifications
     *
     * Daily deduplication keyed on title + dedup_key (which includes the
     * employment status).  This ensures that:
     *
     *   • "New Employment Record — Employed"
     *   • "New Employment Record — Unemployed"
     *   • "Employment Status Updated — Unemployed"
     *
     * all appear as SEPARATE notification rows, never collapsed together.
     *
     * The `message` is always overwritten with the latest payload so the
     * panel always shows the most recent alumni name.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'icon'       => ['nullable', 'string', 'max:64'],
            'title'      => ['required', 'string', 'max:255'],
            'message'    => ['required', 'string'],
            'link_route' => ['nullable', 'string', 'max:255'],
            'link_label' => ['nullable', 'string', 'max:64'],
            'dedup_key'  => ['nullable', 'string', 'max:64'],
        ]);

        $today = Carbon::today();

        /*
         * Build the dedup key:
         *   dedup_key if provided (e.g. "recorded::employed")
         *   otherwise fall back to first 40 chars of message.
         *
         * This means each status variant gets its own DB row.
         */
        $dedupKey = $data['dedup_key'] ?? substr($data['message'], 0, 40);

        $existing = RegistrarNotification::where('title', $data['title'])
            ->where('dedup_key', $dedupKey)
            ->whereDate('created_at', $today)
            ->latest('updated_at')   // uses Eloquent's built-in latest() — safe because
            ->first();               // scopeLatest was renamed to scopeNewest in the model.

        if ($existing) {
            // Same title + same status today → increment count + refresh message.
            $existing->update([
                'message'    => $data['message'],
                'read'       => false,
                'count'      => $existing->count + 1,
                'icon'       => $data['icon']       ?? $existing->icon,
                'link_route' => $data['link_route'] ?? $existing->link_route,
                'link_label' => $data['link_label'] ?? $existing->link_label,
                'updated_at' => now(),
            ]);

            return response()->json($existing->fresh(), 200);
        }

        // First occurrence for this title + status today → new row.
        $notification = RegistrarNotification::create(array_merge(
            $data,
            [
                'read'      => false,
                'count'     => 1,
                'dedup_key' => $dedupKey,   // persisted because dedup_key is in $fillable
            ]
        ));

        return response()->json($notification, 201);
    }

    /**
     * PATCH /registrar/notifications/{notification}/read
     */
    public function markRead(RegistrarNotification $notification)
    {
        $notification->update(['read' => true]);
        return response()->json(['ok' => true]);
    }

    /**
     * PATCH /registrar/notifications/read-all
     */
    public function markAllRead()
    {
        RegistrarNotification::where('read', false)->update(['read' => true]);
        return response()->json(['ok' => true]);
    }
}