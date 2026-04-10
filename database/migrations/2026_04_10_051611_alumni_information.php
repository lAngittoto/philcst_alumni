<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * TASK 3: Add personal profile fields to alumni table
     */
    public function up(): void
    {
        Schema::table('alumni', function (Blueprint $table) {

            // ── Personal Details ─────────────────────────────────────────────
            $table->string('gender')->nullable()->after('suffix');
            $table->date('date_of_birth')->nullable()->after('gender');
            $table->string('place_of_birth')->nullable()->after('date_of_birth');
            $table->string('citizenship', 100)->nullable()->default('Filipino')->after('place_of_birth');
            $table->string('civil_status', 50)->nullable()->after('citizenship');

            // ── Contact ──────────────────────────────────────────────────────
            $table->string('contact_number', 20)->nullable()->after('civil_status');

            // ── Family ───────────────────────────────────────────────────────
            $table->string('father_name')->nullable()->after('contact_number');
            $table->string('mother_name')->nullable()->after('father_name');
            $table->string('spouse_name')->nullable()->after('mother_name');   // optional, for married

            // ── Home Address ─────────────────────────────────────────────────
            $table->string('address_no', 50)->nullable()->after('spouse_name');
            $table->string('address_street')->nullable()->after('address_no');
            $table->string('address_barangay')->nullable()->after('address_street');
            $table->string('address_municipality')->nullable()->after('address_barangay');
            $table->string('address_province')->nullable()->after('address_municipality');
            $table->string('address_zip_code', 10)->nullable()->after('address_province');

            // ── Optional ─────────────────────────────────────────────────────
            $table->string('blood_type', 10)->nullable()->after('address_zip_code');

            // ── Profile Completion Flag ───────────────────────────────────────
            // true  = alumni has filled in all required profile information
            // false = alumni still needs to complete profile
            $table->boolean('profile_completed')->default(false)->after('blood_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alumni', function (Blueprint $table) {
            $table->dropColumn([
                'gender',
                'date_of_birth',
                'place_of_birth',
                'citizenship',
                'civil_status',
                'contact_number',
                'father_name',
                'mother_name',
                'spouse_name',
                'address_no',
                'address_street',
                'address_barangay',
                'address_municipality',
                'address_province',
                'address_zip_code',
                'blood_type',
                'profile_completed',
            ]);
        });
    }
};
