<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registrars live in the `users` table, whose `email` column is the login
 * ("username@registrar.internal"). This adds a separate contact email so a
 * registrar has an email like a Director does — it's where credentials and
 * temporary passwords are sent when the admin updates it in User Management.
 * Existing registrars keep NULL and show as "Not set" until an admin sets one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'contact_email')) {
                $table->string('contact_email', 255)->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'contact_email')) {
                $table->dropColumn('contact_email');
            }
        });
    }
};