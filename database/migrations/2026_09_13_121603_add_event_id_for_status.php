<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * manage-event_blade.php (syncStaleReviewNotifTitles, updateDirectorReviewNotif,
     * notifyOrganizerEvent) has been reading/writing an `event_id` column on
     * director_notifications via raw DB::table() calls all along — but the
     * column was never actually added to the table. Every one of those
     * ->where('event_id', ...) / insert(['event_id' => ...]) calls has been
     * silently throwing a SQL error, swallowed by a try/catch(\Throwable) in
     * each method, which is the real reason the director bell could get
     * stuck reading "Event Submitted → Pending" forever even after an event
     * was Approved/Rejected/Completed: the code path that was supposed to
     * flip the title was never able to find (or write) the row it needed.
     */
    public function up(): void
    {
        Schema::table('director_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('event_id')->nullable()->index()->after('director_id');
        });
    }

    public function down(): void
    {
        Schema::table('director_notifications', function (Blueprint $table) {
            $table->dropColumn('event_id');
        });
    }
};