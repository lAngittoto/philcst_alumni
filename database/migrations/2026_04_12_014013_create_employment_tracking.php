<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the employment_trackings table linked to alumni.
     * Designed for scalability: indexed foreign key + status columns,
     * JSON career_path for multi-select without pivot tables,
     * and nullable conditional columns to avoid sparse row waste.
     */
    public function up(): void
    {
        Schema::create('employment_trackings', function (Blueprint $table) {
            $table->id();

            // ── Relationship ──────────────────────────────────────────────────
            $table->foreignId('alumni_id')
                  ->constrained('alumni')
                  ->onDelete('cascade');         // auto-clean on alumni delete

            // ── Core Status ───────────────────────────────────────────────────
            // Values: employed | self_employed | unemployed
            $table->string('employment_status', 30);

            // ── Employment / Self-Employment Details ──────────────────────────
            $table->string('company_name')->nullable();
            $table->string('job_title')->nullable();

            // Values: full_time | part_time | contractual | project_based | internship
            $table->string('employment_type', 30)->nullable();

            // Values: local | abroad
            $table->string('work_location', 10)->nullable();

            $table->date('date_hired')->nullable();

            // Multi-select stored as JSON array:
            // ["ofw","freelancer","entrepreneur","career_shifter","industry_professional"]
            $table->json('career_path')->nullable();

            // Values: none | pursuing_masteral | pursuing_doctorate
            $table->string('education_status', 30)->nullable();

            // Values: yes | no | partially
            $table->string('course_relevance', 15)->nullable();

            // ── Unemployment Details ──────────────────────────────────────────
            // Values: seeking_employment | not_looking
            $table->string('unemployment_status', 30)->nullable();

            // ── Audit / Soft-delete ───────────────────────────────────────────
            $table->timestamps();
            $table->softDeletes();  // allows audit trail; never hard-delete records

            // ── Indexes (performance / scalability) ───────────────────────────
            // alumni_id is the most frequent WHERE clause column
            $table->index('alumni_id', 'idx_et_alumni');
            // Frequently filtered in admin dashboards
            $table->index('employment_status', 'idx_et_status');
            $table->index('work_location', 'idx_et_location');
            $table->index('course_relevance', 'idx_et_relevance');
            // Composite: used in employment dashboard queries
            $table->index(['employment_status', 'work_location'], 'idx_et_status_loc');
            $table->index(['employment_status', 'course_relevance'], 'idx_et_status_rel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employment_trackings');
    }
};