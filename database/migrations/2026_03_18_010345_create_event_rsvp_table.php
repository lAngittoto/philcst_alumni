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

            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedBigInteger('alumni_id')->index();

            $table->enum('response', ['CONFIRMED', 'DECLINED', 'TENTATIVE'])->index();
            $table->text('message')->nullable();

            $table->timestamps();

            $table->unique(['event_id', 'alumni_id'], 'event_rsvps_event_alumni_unique');
            $table->index(['event_id', 'response'], 'event_rsvps_event_response_index');

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