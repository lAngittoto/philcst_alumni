<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrar_notifications', function (Blueprint $table) {
            // Nullable so existing rows are not affected.
            // Used as the daily dedup key — includes the employment status
            // so Employed / Self-Employed / Unemployed each get their own row.
            $table->string('dedup_key', 64)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('registrar_notifications', function (Blueprint $table) {
            $table->dropColumn('dedup_key');
        });
    }
};