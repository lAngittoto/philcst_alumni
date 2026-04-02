<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `job_postings` MODIFY `status` ENUM('ACTIVE', 'INACTIVE', 'EXPIRED', 'ORGANIZER_DELETED', 'ADMIN_DELETED') NOT NULL DEFAULT 'ACTIVE'");
    }

    public function down(): void
    {
        DB::statement("UPDATE `job_postings` SET `status` = 'ORGANIZER_DELETED' WHERE `status` = 'ADMIN_DELETED'");
        DB::statement("ALTER TABLE `job_postings` MODIFY `status` ENUM('ACTIVE', 'INACTIVE', 'EXPIRED', 'ORGANIZER_DELETED') NOT NULL DEFAULT 'ACTIVE'");
    }
};