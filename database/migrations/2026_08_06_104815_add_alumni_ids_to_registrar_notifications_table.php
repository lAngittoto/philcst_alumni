<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a dedicated `alumni_ids` JSON column to registrar_notifications.
     *
     * Previously this was piggy-backing on the existing `link_params`
     * column, which works but mixes concerns (link_params was meant for
     * generic route params, not domain data). A dedicated column is
     * clearer, easier to query/index later if needed, and keeps
     * link_params free for its original purpose.
     */
    public function up(): void
    {
        Schema::table('registrar_notifications', function (Blueprint $table) {
            $table->json('alumni_ids')->nullable()->after('link_params');
        });
    }

    public function down(): void
    {
        Schema::table('registrar_notifications', function (Blueprint $table) {
            $table->dropColumn('alumni_ids');
        });
    }
};