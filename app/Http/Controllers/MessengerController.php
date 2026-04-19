<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * MessengerController
 *
 * REST endpoints consumed by the Livewire messenger component.
 *
 * Add these routes inside the alumni middleware group in web.php:
 *
 *   Route::post('/messenger/ping',              [MessengerController::class, 'ping'])        ->name('messenger.ping');
 *   Route::get('/messenger/{roomId}/online',    [MessengerController::class, 'onlineCount']) ->name('messenger.online');
 *
 * IMPORTANT — run this migration first so last_seen_at exists:
 *
 *   php artisan make:migration add_last_seen_at_to_alumni_table
 *
 *   Schema::table('alumni', function (Blueprint $table) {
 *       $table->timestamp('last_seen_at')->nullable()->after('status')->index();
 *   });
 */
class MessengerController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────
    // POST /messenger/ping
    // Called by the Livewire component every ~8 s via wire:poll.
    // Updates last_seen_at so other members can see this alumni is "online".
    // ──────────────────────────────────────────────────────────────────────
    public function ping(): JsonResponse
    {
        $user = Auth::user();

        if ($user && $user->role === 'alumni') {
            DB::table('alumni')
                ->where('user_id', $user->id)
                ->update(['last_seen_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // GET /messenger/{roomId}/online
    // Returns online / total member counts for a given chat room.
    // "Online" is defined as last_seen_at within the last 5 minutes.
    // ──────────────────────────────────────────────────────────────────────
    public function onlineCount(int $roomId): JsonResponse
    {
        $room = DB::table('chat_rooms')->find($roomId);

        if (! $room) {
            return response()->json(['online' => 0, 'total' => 0], 404);
        }

        $base = DB::table('alumni')
            ->where('course_code', $room->course_code)
            ->where('batch', $room->batch)
            ->whereNull('deleted_at');

        $total  = (clone $base)->count();
        $online = (clone $base)
            ->where('last_seen_at', '>=', now()->subMinutes(5))
            ->count();

        return response()->json([
            'online' => $online,
            'total'  => $total,
        ]);
    }
}