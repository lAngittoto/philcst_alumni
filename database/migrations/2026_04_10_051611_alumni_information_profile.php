<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds split parent name fields, DSWD household no., disability, and year_level
     * to the alumni table. Drops the old single-string father_name / mother_name columns
     * if they exist (added in the previous profile migration).
     */
    public function up(): void
    {
        Schema::table('alumni', function (Blueprint $table) {

            // ── Year Level (from school records – read only in UI) ────────────
            if (!Schema::hasColumn('alumni', 'year_level')) {
                $table->string('year_level', 20)->nullable()->after('batch');
            }

            // ── Father's Name (split) ─────────────────────────────────────────
            if (!Schema::hasColumn('alumni', 'father_last_name')) {
                $table->string('father_last_name')->nullable()->after('spouse_name');
            }
            if (!Schema::hasColumn('alumni', 'father_given_name')) {
                $table->string('father_given_name')->nullable()->after('father_last_name');
            }
            if (!Schema::hasColumn('alumni', 'father_middle_name')) {
                $table->string('father_middle_name')->nullable()->after('father_given_name');
            }

            // ── Mother's Maiden Name (split) ──────────────────────────────────
            if (!Schema::hasColumn('alumni', 'mother_last_name')) {
                $table->string('mother_last_name')->nullable()->after('father_middle_name');
            }
            if (!Schema::hasColumn('alumni', 'mother_given_name')) {
                $table->string('mother_given_name')->nullable()->after('mother_last_name');
            }
            if (!Schema::hasColumn('alumni', 'mother_middle_name')) {
                $table->string('mother_middle_name')->nullable()->after('mother_given_name');
            }

            // ── DSWD Household Number ────────────────────────────────────────
            if (!Schema::hasColumn('alumni', 'dswd_household_no')) {
                $table->string('dswd_household_no', 50)->nullable()->after('mother_middle_name');
            }

            // ── Disability ───────────────────────────────────────────────────
            // Free-text description (e.g. "None", "Visual Impairment", etc.)
            if (!Schema::hasColumn('alumni', 'disability')) {
                $table->string('disability')->nullable()->after('dswd_household_no');
            }
        });

        // ── Drop old single-string parent name columns if they still exist ───
        Schema::table('alumni', function (Blueprint $table) {
            $dropCols = [];
            foreach (['father_name', 'mother_name', 'spouse_name'] as $col) {
                if (Schema::hasColumn('alumni', $col)) {
                    $dropCols[] = $col;
                }
            }
            if (!empty($dropCols)) {
                $table->dropColumn($dropCols);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alumni', function (Blueprint $table) {
            $cols = [
                'year_level',
                'father_last_name', 'father_given_name', 'father_middle_name',
                'mother_last_name',  'mother_given_name',  'mother_middle_name',
                'dswd_household_no',
                'disability',
            ];

            $existing = array_filter($cols, fn($c) => Schema::hasColumn('alumni', $c));
            if (!empty($existing)) {
                $table->dropColumn(array_values($existing));
            }

            // Re-add old columns
            if (!Schema::hasColumn('alumni', 'father_name')) {
                $table->string('father_name')->nullable();
            }
            if (!Schema::hasColumn('alumni', 'mother_name')) {
                $table->string('mother_name')->nullable();
            }
            if (!Schema::hasColumn('alumni', 'spouse_name')) {
                $table->string('spouse_name')->nullable();
            }
        });
    }
};