<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organizer_id')
                  ->constrained('organizer')
                  ->cascadeOnDelete()
                  ->index();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('photo')->nullable();

            $table->dateTime('event_date');
            $table->dateTime('event_end_date')->nullable();

            $table->string('venue');
            $table->text('venue_address')->nullable();

            $table->string('target_participants')->nullable();
            $table->integer('expected_attendees')->nullable();

            $table->string('contact_person')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            $table->text('notes')->nullable();

            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])
                  ->default('PENDING')
                  ->index();

            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();

            $table->string('updated_by')->nullable();
            $table->string('updated_by_role')->nullable();
            $table->string('deleted_by')->nullable();
            $table->string('deleted_by_role')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('reviewed_by')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();

            $table->index(['status', 'event_date']);
            $table->index(['organizer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};