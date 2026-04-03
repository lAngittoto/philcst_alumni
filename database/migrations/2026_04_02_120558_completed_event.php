<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL requires re-declaring the full enum when adding a new value
        DB::statement("ALTER TABLE events MODIFY COLUMN status ENUM(
            'PENDING',
            'APPROVED',
            'REJECTED',
            'ORGANIZER_DELETED',
            'COMPLETED'
        ) NOT NULL DEFAULT 'PENDING'");
    }

    public function down(): void
    {
        // Revert COMPLETED events to APPROVED before dropping the value
        DB::statement("UPDATE events SET status = 'APPROVED' WHERE status = 'COMPLETED'");

        DB::statement("ALTER TABLE events MODIFY COLUMN status ENUM(
            'PENDING',
            'APPROVED',
            'REJECTED',
            'ORGANIZER_DELETED'
        ) NOT NULL DEFAULT 'PENDING'");
    }
};