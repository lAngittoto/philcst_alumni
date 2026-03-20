<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer', function (Blueprint $table) {
            $table->string('otp')->nullable()->after('status');
            $table->timestamp('otp_expires_at')->nullable()->after('otp');
            $table->string('password_reset_token')->nullable()->after('otp_expires_at');
            $table->timestamp('password_reset_initiated_at')->nullable()->after('password_reset_token');
            $table->timestamp('password_changed_at')->nullable()->after('password_reset_initiated_at');

            $table->index('password_reset_token');
        });
    }

    public function down(): void
    {
        Schema::table('organizer', function (Blueprint $table) {
            $table->dropIndex(['password_reset_token']);
            $table->dropColumn([
                'otp',
                'otp_expires_at',
                'password_reset_token',
                'password_reset_initiated_at',
                'password_changed_at',
            ]);
        });
    }
};