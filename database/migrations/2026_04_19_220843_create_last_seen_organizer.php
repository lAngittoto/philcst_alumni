<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer', function (Blueprint $table) {
            $table->timestamp('last_seen_at')
                  ->nullable()
                  ->after('status')
                  ->index();
        });
    }
 
    public function down(): void
    {
        Schema::table('organizer', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
 