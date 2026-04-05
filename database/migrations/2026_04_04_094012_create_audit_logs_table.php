<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
   Schema::create('audit_logs', function (Blueprint $table) {
       $table->id();
       $table->unsignedBigInteger('user_id')->nullable();
       $table->string('user_role')->nullable();          // admin | organizer | alumni | system
       $table->string('user_name')->nullable();
       $table->string('user_email')->nullable();
       $table->string('action');                         // created|updated|deleted|verified|rejected|login|logout|restored|exported|password_changed
       $table->string('module');                         // alumni|organizer|event|job_posting|user|auth|system
       $table->unsignedBigInteger('subject_id')->nullable();
       $table->string('subject_type')->nullable();       // model class name
       $table->string('subject_label')->nullable();      // human-readable name/title
       $table->json('old_values')->nullable();
       $table->json('new_values')->nullable();    $table->text('description');
       $table->string('ip_address', 45)->nullable();
       $table->text('user_agent')->nullable();
       $table->string('session_id')->nullable();
       $table->enum('severity', ['info','warning','critical'])->default('info');
       $table->boolean('is_flagged')->default(false);
       $table->text('flag_reason')->nullable();
       $table->timestamps();
 
       $table->index(['module', 'action']);
       $table->index(['user_id', 'created_at']);
       $table->index(['severity', 'is_flagged']);
       $table->index('created_at');
   });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
