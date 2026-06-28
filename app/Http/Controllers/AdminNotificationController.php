<?php

namespace App\Http\Controllers;

use App\Models\AdminNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminNotificationController extends Controller
{
    /**
     * GET /admin/notifications
     * Ginagamit ng poller (_fetch() sa layout JS) — kailangan flat array,
     * hindi wrapped sa {data: [...]} dahil _groupByDay(raw) umaasa
     * na array agad ang JSON response.
     */
    public function index()
    {
        $notifications = AdminNotification::query()
            ->orderByDesc('created_at')
            ->limit(200)
            ->get([
                'id',
                'icon',
                'title',
                'message',
                'link_route',
                'link_label',
                'dedup_key',
                'read',
                'created_at',
            ]);

        return response()->json($notifications);
    }

    /**
     * POST /admin/notifications
     * Tinatawag ng _saveAdminNotif() sa layout JS tuwing mag-fire
     * yung mga window events (admin-user-updated, admin-job-updated, atbp).
     *
     * Dedup logic: kung may existing UNREAD na row na may parehong
     * dedup_key, i-touch/update lang yun imbes na gumawa ng bagong row
     * (para hindi sumobra sobra yung notifications kapag mabilis na
     * sunod-sunod na nag-fire yung event para sa parehong record).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'icon'       => ['nullable', 'string', 'max:50'],
            'title'      => ['required', 'string', 'max:255'],
            'message'    => ['nullable', 'string'],
            'link_route' => ['nullable', 'string', 'max:100'],
            'link_label' => ['nullable', 'string', 'max:100'],
            'dedup_key'  => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $notification = null;

        if (!empty($data['dedup_key'])) {
            $notification = AdminNotification::query()
                ->where('dedup_key', $data['dedup_key'])
                ->where('read', false)
                ->first();
        }

        if ($notification) {
            $notification->fill($data);
            $notification->touch();
            $notification->save();
        } else {
            $notification = AdminNotification::create($data);
        }

        return response()->json($notification, 201);
    }

    /**
     * PATCH /admin/notifications/{notification}/read
     */
    public function markRead(AdminNotification $notification)
    {
        if (!$notification->read) {
            $notification->update([
                'read'    => true,
                'read_at' => now(),
            ]);
        }

        return response()->json($notification);
    }

    /**
     * PATCH /admin/notifications/read-all
     */
    public function markAllRead()
    {
        AdminNotification::unread()->update([
            'read'    => true,
            'read_at' => now(),
        ]);

        return response()->json(['status' => 'ok']);
    }
}