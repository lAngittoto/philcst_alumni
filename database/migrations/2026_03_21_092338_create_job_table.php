<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── job_postings ──────────────────────────────────────────
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')
                  ->nullable()
                  ->constrained('organizer')
                  ->nullOnDelete()
                  ->index();
            $table->string('job_title');
            $table->string('company_name');
            $table->string('company_type');
            $table->string('location')->nullable();
            $table->string('employment_type');
            $table->string('experience_level');
            $table->string('salary')->nullable();
            $table->date('deadline');
            $table->text('description');
            $table->string('target_college')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'EXPIRED', 'ORGANIZER_DELETED'])
                  ->default('ACTIVE')
                  ->index();
            $table->string('updated_by')->nullable();
            $table->string('updated_by_role')->nullable();
            $table->string('deleted_by')->nullable();
            $table->string('deleted_by_role')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ── job_options ───────────────────────────────────────────
        Schema::create('job_options', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('label');
            $table->string('default_location')->nullable();
            $table->timestamps();

            $table->index('type');
        });

        // ── Seed default job_options ──────────────────────────────
        $now = now();

        DB::table('job_options')->insert([
            // Employment Types
            ['type' => 'employment_type', 'label' => 'Full-Time',  'default_location' => null, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'employment_type', 'label' => 'Part-Time',  'default_location' => null, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'employment_type', 'label' => 'Contract',   'default_location' => null, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'employment_type', 'label' => 'Internship', 'default_location' => null, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'employment_type', 'label' => 'Freelance',  'default_location' => null, 'created_at' => $now, 'updated_at' => $now],

            // Experience Levels
            ['type' => 'experience_level', 'label' => 'No Experience Required',        'default_location' => null, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'experience_level', 'label' => 'Entry Level (At Least 1 Year)', 'default_location' => null, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'experience_level', 'label' => 'Mid Level (2-3 Years)',          'default_location' => null, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'experience_level', 'label' => 'Senior Level (4-5 Years)',       'default_location' => null, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'experience_level', 'label' => 'Expert Level (5+ Years)',        'default_location' => null, 'created_at' => $now, 'updated_at' => $now],

            // Company Types
            ['type' => 'company_type', 'label' => 'PHILCST Main Campus', 'default_location' => 'Old Nalsian Road, Nalsian, Calasiao, Pangasinan', 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'company_type', 'label' => 'Private Company',     'default_location' => null, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'company_type', 'label' => 'Government Agency',   'default_location' => null, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'company_type', 'label' => 'NGO',                 'default_location' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
        Schema::dropIfExists('job_options');
    }
};