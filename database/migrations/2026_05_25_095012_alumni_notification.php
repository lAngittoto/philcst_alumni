<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alumni Notifications Table
 *
 * Single migration that creates the full alumni_notifications table
 * with all required columns including dedup_key and count.
 *
 * php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alumni_notifications', function (Blueprint $table) {
            $table->id();

            // Which alumni user this notification belongs to
            $table->unsignedBigInteger('alumni_id')->nullable()->index();

            // Display
            $table->string('icon')->default('bell');
            $table->string('title');

            // Used as the daily dedup key — e.g. 'job-posted::42', 'event-announced::7'
            // Nullable so rows without a key are never merged
            $table->string('dedup_key', 64)->nullable();

            $table->text('message');

            // Navigation target when the user clicks the notification
            $table->string('link_route')->nullable();   // e.g. 'job.opportunities'
            $table->string('link_label')->nullable();   // e.g. 'View Jobs'
            $table->json('link_params')->nullable();    // optional route params

            // Read state
            $table->boolean('read')->default(false);

            // Number of events grouped into this row for the day (daily dedup counter)
            $table->unsignedInteger('count')->default(1);

            $table->timestamps();

            // Foreign key — nullable so system-wide notifications (alumni_id = null)
            // are also supported. Cascade delete keeps the table clean.
            $table->foreign('alumni_id')
                  ->references('id')
                  ->on('alumni')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alumni_notifications');
    }
};