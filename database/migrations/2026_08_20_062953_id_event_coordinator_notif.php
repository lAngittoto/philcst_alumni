<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds an `event_id` column to coordinator_notifications so event-related
     * notifs (submit/resubmit/approved/rejected) can carry the underlying
     * OrganizerEvent id. The frontend uses this to build a
     * ?highlight_event={id} deep-link so clicking the notif jumps straight
     * to that event's View Details instead of just the events list.
     *
     * Nullable — non-event notifs (jobs, chat, alumni) simply leave it null.
     */
    public function up(): void
    {
        Schema::table('coordinator_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->nullable()->after('link_label');
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('coordinator_notifications', function (Blueprint $table) {
            $table->dropIndex(['event_id']);
            $table->dropColumn('event_id');
        });
    }
};