<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        // --------------------------------------------------------
        // job_options — admin-managed dropdown choices
        // --------------------------------------------------------
        Schema::create('job_options', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['company_type', 'employment_type', 'experience_level', 'target_college']);
            $table->string('label');
            $table->string('default_location')->nullable();
            $table->timestamps();
            $table->index('type');
            $table->index(['type', 'label']);
        });
        // --------------------------------------------------------
        // job_postings — actual job postings by organizer
        // --------------------------------------------------------
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('organizer')->cascadeOnDelete();
            $table->string('job_title');
            $table->string('company_name');
            $table->string('company_type');
            $table->string('location');
            $table->string('employment_type');
            $table->string('experience_level');
            $table->string('salary')->nullable();
            $table->date('deadline');
            $table->longText('description');
            $table->string('target_college')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'EXPIRED'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();
            $table->index('organizer_id');
            $table->index('status');
            $table->index('deadline');
            $table->index('employment_type');
            $table->index('experience_level');
            $table->index('company_type');
            $table->index('target_college');
            $table->index('deleted_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('job_postings');
        Schema::dropIfExists('job_options');
    }
};