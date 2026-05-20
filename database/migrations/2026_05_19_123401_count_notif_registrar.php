<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Run this migration to add the `count` column used for daily deduplication.
 *
 * php artisan make:migration add_count_to_registrar_notifications_table
 * (then replace the file content with this)
 * php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrar_notifications', function (Blueprint $table) {
            // Number of events grouped into this row for the day
            $table->unsignedInteger('count')->default(1)->after('read');
        });
    }

    public function down(): void
    {
        Schema::table('registrar_notifications', function (Blueprint $table) {
            $table->dropColumn('count');
        });
    }
};