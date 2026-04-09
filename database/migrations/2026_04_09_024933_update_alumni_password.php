<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alumni', function (Blueprint $table) {
            // OTP for email verification during first-login setup
            $table->string('otp')->nullable()->after('status');
            $table->timestamp('otp_expires_at')->nullable()->after('otp');

            // Null = password never changed (still on temp password)
            // Filled = alumni completed the setup wizard
            $table->timestamp('password_changed_at')->nullable()->after('otp_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('alumni', function (Blueprint $table) {
            $table->dropColumn([
                'otp',
                'otp_expires_at',
                'password_changed_at',
            ]);
        });
    }
};