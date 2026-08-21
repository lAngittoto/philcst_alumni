<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds job_id to coordinator_notifications, mirroring the existing
     * event_id column. Lets job-related notifications carry the underlying
     * JobPosting id so the frontend can build a ?highlight_job={id}
     * deep-link — same mechanism already used for events via event_id.
     */
    public function up(): void
    {
        Schema::table('coordinator_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('job_id')->nullable()->after('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('coordinator_notifications', function (Blueprint $table) {
            $table->dropColumn('job_id');
        });
    }
};