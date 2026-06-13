<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            // ── Image upload (nullable — falls back to default-photo-job.jpg) ──
            if (! Schema::hasColumn('job_postings', 'job_image')) {
                $table->string('job_image')->nullable()->after('description');
            }

            // ── Safety: add qualifications & application_instructions ─────────
            // (in case the original migration didn't include them yet)
            if (! Schema::hasColumn('job_postings', 'qualifications')) {
                $table->text('qualifications')->nullable()->after('job_image');
            }
            if (! Schema::hasColumn('job_postings', 'application_instructions')) {
                $table->text('application_instructions')->nullable()->after('qualifications');
            }
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn(['job_image']);
        });
    }
};