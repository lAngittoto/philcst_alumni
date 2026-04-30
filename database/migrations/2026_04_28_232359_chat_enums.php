<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Alter sender_type enum in chat_messages to include 'director'
        DB::statement("ALTER TABLE `chat_messages` MODIFY COLUMN `sender_type` ENUM('alumni', 'organizer', 'director') NOT NULL");

        // Alter mention_type enum in chat_mentions to include 'coordinator' and 'director'
        DB::statement("ALTER TABLE `chat_mentions` MODIFY COLUMN `mention_type` ENUM('everyone', 'alumni', 'organizer', 'coordinator', 'director') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE `chat_messages` MODIFY COLUMN `sender_type` ENUM('alumni', 'organizer') NOT NULL");
        DB::statement("ALTER TABLE `chat_mentions` MODIFY COLUMN `mention_type` ENUM('everyone', 'alumni', 'organizer') NOT NULL");
    }
};