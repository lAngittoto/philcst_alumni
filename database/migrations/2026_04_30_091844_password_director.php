<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds password_changed_at to the director table so the system
     * can enforce a first-login password change (same flow as organizer).
     */
    public function up(): void
    {
        Schema::table('director', function (Blueprint $table) {
            if (! Schema::hasColumn('director', 'password_changed_at')) {
                $table->timestamp('password_changed_at')
                      ->nullable()
                      ->after('status')
                      ->comment('NULL = director must change password on first login');
            }
        });
    }

    public function down(): void
    {
        Schema::table('director', function (Blueprint $table) {
            if (Schema::hasColumn('director', 'password_changed_at')) {
                $table->dropColumn('password_changed_at');
            }
        });
    }
};