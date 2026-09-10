<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_trackings', function (Blueprint $table) {
            // Free-text reason the alumnus gives when they select
            // "Not Currently Looking" under Unemployment Status
            // (e.g. "Furthering studies", "Health reasons", "Taking care of family").
            // Placed right after unemployment_status since it's directly
            // dependent on that field.
            $table->string('unemployment_reason', 255)
                  ->nullable()
                  ->after('unemployment_status');
        });
    }

    public function down(): void
    {
        Schema::table('employment_trackings', function (Blueprint $table) {
            $table->dropColumn('unemployment_reason');
        });
    }
};