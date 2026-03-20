<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            // Make organizer_id nullable for admin-posted jobs
            $table->foreignId('organizer_id')->nullable()->change();

            if (!Schema::hasColumn('job_postings', 'deleted_by')) {
                $table->string('deleted_by')->nullable()->after('updated_by_role');
            }
            if (!Schema::hasColumn('job_postings', 'deleted_by_role')) {
                $table->string('deleted_by_role')->nullable()->after('deleted_by');
            }
        });

        DB::statement("ALTER TABLE job_postings MODIFY COLUMN status ENUM('ACTIVE','INACTIVE','EXPIRED','ORGANIZER_DELETED') NOT NULL DEFAULT 'ACTIVE'");
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            if (Schema::hasColumn('job_postings', 'deleted_by')) {
                $table->dropColumn('deleted_by');
            }
            if (Schema::hasColumn('job_postings', 'deleted_by_role')) {
                $table->dropColumn('deleted_by_role');
            }
            $table->foreignId('organizer_id')->nullable(false)->change();
        });

        DB::statement("ALTER TABLE job_postings MODIFY COLUMN status ENUM('ACTIVE','INACTIVE','EXPIRED') NOT NULL DEFAULT 'ACTIVE'");
    }
};