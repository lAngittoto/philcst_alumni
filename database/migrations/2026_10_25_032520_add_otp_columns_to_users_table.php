<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds generic OTP + password_changed_at columns on `users`.
     *
     * Alumni and Organizer already store their OWN otp / otp_expires_at /
     * password_changed_at on their own tables (alumni, organizer) — this
     * migration does NOT touch those, and the unified forgot-password flow
     * keeps using those existing columns for those two roles.
     *
     * This migration exists ONLY so Registrar, Admin, and Director — who
     * have no (or no complete) per-role OTP storage — have somewhere to
     * put a hashed OTP during the forgot-password flow.
     *
     * Director already has its own `password_changed_at` on the `director`
     * table (used by EnsureDirectorPasswordChanged), so we do NOT add a
     * second one here — only otp / otp_expires_at are added for Director,
     * Registrar, and Admin, all of whom authenticate against `users`.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'otp')) {
                $table->string('otp')->nullable()->after('password');
            }
            if (!Schema::hasColumn('users', 'otp_expires_at')) {
                $table->timestamp('otp_expires_at')->nullable()->after('otp');
            }
            // Only Registrar/Admin lack a password_changed_at anywhere.
            // Director already has one on the `director` table.
            if (!Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable()->after('otp_expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $cols = array_filter(
                ['otp', 'otp_expires_at', 'password_changed_at'],
                fn ($c) => Schema::hasColumn('users', $c)
            );
            if ($cols) {
                $table->dropColumn($cols);
            }
        });
    }
};