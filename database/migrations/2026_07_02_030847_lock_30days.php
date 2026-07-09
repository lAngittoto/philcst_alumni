<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alumni', function (Blueprint $table) {
            if (!Schema::hasColumn('alumni', 'profile_changed_at')) {
                $table->timestamp('profile_changed_at')->nullable()->after('email_changed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('alumni', function (Blueprint $table) {
            if (Schema::hasColumn('alumni', 'profile_changed_at')) {
                $table->dropColumn('profile_changed_at');
            }
        });
    }
};