<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL requires ALTER TABLE to modify ENUM — we can't use Blueprint->enum() for modification
        DB::statement("ALTER TABLE `events` MODIFY `status` ENUM('PENDING','APPROVED','REJECTED','ORGANIZER_DELETED') NOT NULL DEFAULT 'PENDING'");
    }

    public function down(): void
    {
        // Revert — make sure no rows have ORGANIZER_DELETED before rolling back
        DB::statement("UPDATE `events` SET `status` = 'REJECTED' WHERE `status` = 'ORGANIZER_DELETED'");
        DB::statement("ALTER TABLE `events` MODIFY `status` ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING'");
    }
};