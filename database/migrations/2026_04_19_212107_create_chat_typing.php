<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_typing', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->string('sender_type');        // 'alumni' | 'coordinator'
            $table->unsignedBigInteger('sender_id');
            $table->timestamp('typed_at');
            $table->timestamps();

            // One row per person per room — updateOrInsert keeps it clean
            $table->unique(['room_id', 'sender_type', 'sender_id']);

            // Foreign key to chat_rooms
            $table->foreign('room_id')
                  ->references('id')
                  ->on('chat_rooms')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_typing');
    }
};