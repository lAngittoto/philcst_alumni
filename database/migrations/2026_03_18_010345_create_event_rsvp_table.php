<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_rsvps', function (Blueprint $table) {
            $table->id();

            // Which event
            $table->unsignedBigInteger('event_id')->index();

            // Which alumni responded
            $table->unsignedBigInteger('alumni_id')->index();

            // RSVP response
            // CONFIRMED  → Going
            // DECLINED   → Not Going
            // TENTATIVE  → Maybe
            $table->enum('response', ['CONFIRMED', 'DECLINED', 'TENTATIVE'])->index();

            // Optional message from the alumnus
            $table->text('message')->nullable();

            $table->timestamps();

            // One response per alumnus per event (can update but not duplicate)
            $table->unique(['event_id', 'alumni_id'], 'event_rsvps_event_alumni_unique');

            // Composite index for organizer dashboard queries
            $table->index(['event_id', 'response'], 'event_rsvps_event_response_index');

            // Explicit named foreign keys to avoid MySQL duplicate constraint error
            $table->foreign('event_id', 'event_rsvps_event_id_foreign')
                  ->references('id')
                  ->on('events')
                  ->cascadeOnDelete();

            $table->foreign('alumni_id', 'event_rsvps_alumni_id_foreign')
                  ->references('id')
                  ->on('alumni')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_rsvps');
    }
};