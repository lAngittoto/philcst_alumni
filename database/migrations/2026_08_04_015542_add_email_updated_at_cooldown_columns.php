<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds an `email_updated_at` timestamp to alumni, director, and users
     * (for registrar usernames) so the admin User Management screen can
     * enforce a 30-day cooldown between email/username updates.
     */
    public function up(): void
    {
        Schema::table('alumni', function (Blueprint $table) {
            $table->timestamp('email_updated_at')->nullable()->after('email');
        });

        Schema::table('director', function (Blueprint $table) {
            $table->timestamp('email_updated_at')->nullable()->after('email');
        });

        Schema::table('users', function (Blueprint $table) {
            // Tracks the registrar's last username change (users.email holds
            // the login username for registrars, e.g. "jdelacruz@registrar.internal").
            $table->timestamp('email_updated_at')->nullable()->after('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alumni', function (Blueprint $table) {
            $table->dropColumn('email_updated_at');
        });

        Schema::table('director', function (Blueprint $table) {
            $table->dropColumn('email_updated_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_updated_at');
        });
    }
};