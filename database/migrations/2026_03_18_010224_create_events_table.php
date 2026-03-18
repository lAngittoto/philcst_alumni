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

            // Organizer who created the event
            $table->foreignId('organizer_id')
                  ->constrained('organizer')
                  ->cascadeOnDelete()
                  ->index();

            // Basic event details
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('photo')->nullable();               // event cover photo path

            // Schedule
            $table->dateTime('event_date');                    // start date & time
            $table->dateTime('event_end_date')->nullable();    // end date & time (optional)

            // Location
            $table->string('venue');                           // venue / location name
            $table->text('venue_address')->nullable();

            // Targeting
            $table->string('target_participants')->nullable(); // e.g. "All Alumni", "BSIT Batch 2020"
            $table->integer('expected_attendees')->nullable(); // estimated headcount

            // Organizer contact info snapshot (denormalized for audit)
            $table->string('contact_person')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            // Additional notes / requirements
            $table->text('notes')->nullable();

            // Admin review workflow
            // PENDING  → newly submitted, awaiting admin action
            // APPROVED → admin approved, visible to alumni
            // REJECTED → admin rejected, hidden from alumni
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])
                  ->default('PENDING')
                  ->index();

            // Audit trail — admin review decision
            $table->unsignedBigInteger('reviewed_by')->nullable(); // admin user_id
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_remarks')->nullable();            // reason for rejection, etc.

            // Audit trail — edit & delete tracking
            $table->string('updated_by')->nullable();              // name of last editor
            $table->string('updated_by_role')->nullable();         // 'organizer' or 'admin'
            $table->string('deleted_by')->nullable();              // name of who soft-deleted
            $table->string('deleted_by_role')->nullable();         // 'organizer' or 'admin'

            $table->timestamps();
            $table->softDeletes();

            // Foreign key for reviewer
            $table->foreign('reviewed_by')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();

            // Useful composite indexes
            $table->index(['status', 'event_date']);
            $table->index(['organizer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};