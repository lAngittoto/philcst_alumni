<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registrars live in the `users` table. These columns let a registrar store
 * first / middle / last / suffix separately (same as the `director` table),
 * so User Management → View Details can show them like the Director card.
 * Existing registrars keep NULL and are shown as "—".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'first_name'))  $table->string('first_name', 100)->nullable()->after('name');
            if (!Schema::hasColumn('users', 'middle_name')) $table->string('middle_name', 100)->nullable()->after('first_name');
            if (!Schema::hasColumn('users', 'last_name'))   $table->string('last_name', 100)->nullable()->after('middle_name');
            if (!Schema::hasColumn('users', 'suffix'))      $table->string('suffix', 20)->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['suffix', 'last_name', 'middle_name', 'first_name'] as $col) {
                if (Schema::hasColumn('users', $col)) $table->dropColumn($col);
            }
        });
    }
};