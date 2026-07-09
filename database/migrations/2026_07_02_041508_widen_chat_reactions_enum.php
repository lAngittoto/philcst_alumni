<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_messages')) return;

        DB::statement("ALTER TABLE chat_messages MODIFY sender_type ENUM('alumni','organizer','coordinator','director') NOT NULL");
        DB::statement("ALTER TABLE chat_reactions MODIFY reactor_type ENUM('alumni','organizer','coordinator','director') NOT NULL");
        DB::statement("ALTER TABLE chat_reactions MODIFY reaction ENUM('heart','purple','like','dislike','happy','sad') NOT NULL");
        DB::statement("ALTER TABLE chat_pins MODIFY pinned_by_type ENUM('alumni','organizer','coordinator','director') NOT NULL");
        DB::statement("ALTER TABLE chat_mentions MODIFY mention_type ENUM('everyone','alumni','organizer','coordinator','director') NOT NULL");
    }

    public function down(): void
    {
        if (! Schema::hasTable('chat_messages')) return;

        DB::statement("ALTER TABLE chat_mentions MODIFY mention_type ENUM('everyone','alumni','organizer','coordinator','director') NOT NULL");
        DB::statement("ALTER TABLE chat_pins MODIFY pinned_by_type ENUM('alumni','organizer','coordinator','director') NOT NULL");
        DB::statement("ALTER TABLE chat_reactions MODIFY reaction ENUM('heart','purple','like','dislike') NOT NULL");
        DB::statement("ALTER TABLE chat_reactions MODIFY reactor_type ENUM('alumni','organizer','coordinator','director') NOT NULL");
        DB::statement("ALTER TABLE chat_messages MODIFY sender_type ENUM('alumni','organizer','director') NOT NULL");
    }
};