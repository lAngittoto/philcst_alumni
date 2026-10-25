<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registrars live in the `users` table (no separate record table like
 * Director), so their "must change password on next login" flag has to
 * live there too. NULL = still on the temporary password.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('password_changed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('password_changed_at');
            });
        }
    }
};