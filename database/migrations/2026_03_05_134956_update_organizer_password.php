<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizer', function (Blueprint $table) {
            // Add OTP and password reset tracking columns
            $table->string('otp')->nullable()->after('status')->comment('One-time password for password reset');
            $table->timestamp('otp_expires_at')->nullable()->after('otp')->comment('OTP expiration time');
            $table->string('password_reset_token')->nullable()->after('otp_expires_at')->comment('Unique token for password reset session');
            $table->timestamp('password_reset_initiated_at')->nullable()->after('password_reset_token')->comment('When the password reset was initiated');
            $table->timestamp('password_changed_at')->nullable()->after('password_reset_initiated_at')->comment('When the password was last successfully changed');
            
            // Add index for OTP lookup
            $table->index('password_reset_token')->comment('Index for fast password reset token lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizer', function (Blueprint $table) {
            $table->dropIndex(['password_reset_token']);
            $table->dropColumn([
                'otp',
                'otp_expires_at',
                'password_reset_token',
                'password_reset_initiated_at',
                'password_changed_at'
            ]);
        });
    }
};