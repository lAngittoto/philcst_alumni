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
     * Daily deduplication keyed on title + dedup_key.
     * When a duplicate is found today, increment count + refresh BOTH
     * created_at and updated_at so the panel always shows the latest time.
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

        $dedupKey = $data['dedup_key'] ?? substr($data['message'], 0, 40);

        $existing = RegistrarNotification::where('title', $data['title'])
            ->where('dedup_key', $dedupKey)
            ->whereDate('created_at', $today)
            ->latest('updated_at')
            ->first();

        if ($existing) {
            $now = now();

            // ✅ Refresh BOTH created_at and updated_at so the displayed
            //    timestamp always reflects the most recent update.
            $existing->timestamps = false; // disable auto-touch so we set manually
            $existing->update([
                'message'    => $data['message'],
                'read'       => false,
                'count'      => $existing->count + 1,
                'icon'       => $data['icon']       ?? $existing->icon,
                'link_route' => $data['link_route'] ?? $existing->link_route,
                'link_label' => $data['link_label'] ?? $existing->link_label,
                'created_at' => $now, // ✅ this is what the JS panel reads for display
                'updated_at' => $now,
            ]);

            return response()->json($existing->fresh(), 200);
        }

        // First occurrence for this title + status today → new row.
        $notification = RegistrarNotification::create(array_merge(
            $data,
            [
                'read'      => false,
                'count'     => 1,
                'dedup_key' => $dedupKey,
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