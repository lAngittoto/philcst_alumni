<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chat system: one Group Chat (GC) per course_code + batch.
     *
     * Alumni  → sees ONLY their own course+batch room.
     * Organizer → sees ALL rooms belonging to their department's courses.
     *
     * Tables created:
     *   chat_rooms        – one row per course+batch GC
     *   chat_messages     – messages (soft-delete = "unsend")
     *   chat_reactions    – ❤️ 💜 👍 👎  (one per user per message)
     *   chat_pins         – pinned messages (one record per message)
     *   chat_mentions     – @someone / @everyone references inside a message
     */
    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────────
        // 1. CHAT ROOMS  (one per course_code + batch combination)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('chat_rooms', function (Blueprint $table) {
            $table->id();

            // Display name, e.g. "BSIT · Batch 2023"
            $table->string('name');

            // Ties room to a specific course and graduation year
            $table->string('course_code')->index();
            $table->unsignedSmallInteger('batch')->index();

            // Department/college mirror (copied from courses.college) –
            // lets the organizer side quickly find all rooms it can access.
            $table->string('department')->nullable()->index();

            $table->timestamps();

            // One room per course+batch pair
            $table->unique(['course_code', 'batch']);
        });

        // ─────────────────────────────────────────────────────────────────
        // 2. CHAT MESSAGES
        //    soft-delete row  = "unsend" (message body hidden, record kept
        //    so that reactions / pin records stay consistent)
        // ─────────────────────────────────────────────────────────────────
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('room_id')
                  ->constrained('chat_rooms')
                  ->onDelete('cascade');

            // Polymorphic-style sender (alumni | organizer)
            $table->enum('sender_type', ['alumni', 'organizer']);
            $table->unsignedBigInteger('sender_id')->index();

            $table->text('body');

            // For the "reply" / quote feature
            $table->foreignId('reply_to_id')
                  ->nullable()
                  ->constrained('chat_messages')
                  ->nullOnDelete();

            // Filled when the sender edits the message
            $table->timestamp('edited_at')->nullable();

            $table->timestamps();

            // Soft-delete = "unsend"
            $table->softDeletes();

            $table->index(['room_id', 'created_at']);
        });

        // ─────────────────────────────────────────────────────────────────
        // 3. CHAT REACTIONS  (❤️ 💜 👍 👎)
        //    One reaction per user per message.
        //    Toggle: re-clicking the same emoji removes it.
        //    Switching: clicking a different emoji replaces it.
        // ─────────────────────────────────────────────────────────────────
        Schema::create('chat_reactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')
                  ->constrained('chat_messages')
                  ->onDelete('cascade');

            $table->enum('reactor_type', ['alumni', 'organizer']);
            $table->unsignedBigInteger('reactor_id');

            // Only 4 reactions allowed
            $table->enum('reaction', ['heart', 'purple', 'like', 'dislike']);

            $table->timestamps();

            // Enforce one reaction per user per message
            $table->unique(
                ['message_id', 'reactor_type', 'reactor_id'],
                'one_reaction_per_user'
            );
        });

        // ─────────────────────────────────────────────────────────────────
        // 4. CHAT PINS
        //    A message can be pinned once (unique on message_id).
        //    Anyone in the room can pin/unpin.
        // ─────────────────────────────────────────────────────────────────
        Schema::create('chat_pins', function (Blueprint $table) {
            $table->id();

            $table->foreignId('room_id')
                  ->constrained('chat_rooms')
                  ->onDelete('cascade');

            // Unique: each message can only be pinned once at a time
            $table->foreignId('message_id')
                  ->unique()
                  ->constrained('chat_messages')
                  ->onDelete('cascade');

            $table->enum('pinned_by_type', ['alumni', 'organizer']);
            $table->unsignedBigInteger('pinned_by_id');

            $table->timestamps();
        });

        // ─────────────────────────────────────────────────────────────────
        // 5. CHAT MENTIONS  (@everyone  or  @specific person)
        //    Stored separately so the backend can notify mentioned users.
        // ─────────────────────────────────────────────────────────────────
        Schema::create('chat_mentions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')
                  ->constrained('chat_messages')
                  ->onDelete('cascade');

            // 'everyone' → mentioned_id is null
            // 'alumni' / 'organizer' → mentioned_id = their PK
            $table->enum('mention_type', ['everyone', 'alumni', 'organizer']);
            $table->unsignedBigInteger('mentioned_id')->nullable();

            $table->timestamps();

            $table->index(['mention_type', 'mentioned_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_mentions');
        Schema::dropIfExists('chat_pins');
        Schema::dropIfExists('chat_reactions');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_rooms');
    }
};