<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AlumniInformation
 *
 * Stores the personal profile fields that an alumni must complete
 * after finishing the account-setup wizard (Gate 2).
 *
 * Table  : alumni_information
 * FK     : alumni_id → alumni.id
 *
 * NOTE: Run the migration below before using this model.
 *
 * php artisan make:migration create_alumni_information_table
 *
 * Schema::create('alumni_information', function (Blueprint $table) {
 *     $table->id();
 *     $table->foreignId('alumni_id')->unique()->constrained('alumni')->onDelete('cascade');
 *
 *     // ── Personal Details ──────────────────────────────────────────────
 *     $table->string('gender')->nullable();
 *     $table->date('date_of_birth')->nullable();
 *     $table->string('place_of_birth')->nullable();
 *     $table->string('citizenship', 100)->nullable()->default('Filipino');
 *     $table->string('civil_status', 50)->nullable();
 *     $table->string('blood_type', 10)->nullable();
 *
 *     // ── Contact ───────────────────────────────────────────────────────
 *     $table->string('contact_number', 20)->nullable();
 *
 *     // ── Family ────────────────────────────────────────────────────────
 *     $table->string('father_name')->nullable();
 *     $table->string('mother_name')->nullable();
 *     $table->string('spouse_name')->nullable();
 *
 *     // ── Home Address ──────────────────────────────────────────────────
 *     $table->string('address_no', 50)->nullable();
 *     $table->string('address_street')->nullable();
 *     $table->string('address_barangay')->nullable();
 *     $table->string('address_municipality')->nullable();
 *     $table->string('address_province')->nullable();
 *     $table->string('address_zip_code', 10)->nullable();
 *
 *     // ── Completion Flag ───────────────────────────────────────────────
 *     $table->boolean('profile_completed')->default(false);
 *
 *     $table->timestamps();
 * });
 */
class AlumniInformation extends Model
{
    use HasFactory;

    protected $table      = 'alumni_information';
    protected $primaryKey = 'id';
    public    $timestamps = true;

    protected $fillable = [
        'alumni_id',

        // ── Personal Details ──────────────────────────────────────────────────
        'gender',
        'date_of_birth',
        'place_of_birth',
        'citizenship',
        'civil_status',
        'blood_type',

        // ── Contact ───────────────────────────────────────────────────────────
        'contact_number',

        // ── Family ────────────────────────────────────────────────────────────
        'father_name',
        'mother_name',
        'spouse_name',

        // ── Home Address ──────────────────────────────────────────────────────
        'address_no',
        'address_street',
        'address_barangay',
        'address_municipality',
        'address_province',
        'address_zip_code',

        // ── Completion Flag ───────────────────────────────────────────────────
        'profile_completed',
    ];

    protected $casts = [
        'date_of_birth'     => 'date',
        'profile_completed' => 'boolean',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The alumni this profile belongs to.
     */
    public function alumni(): BelongsTo
    {
        return $this->belongsTo(Alumni::class, 'alumni_id');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Profile Completion
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check if all required fields are filled.
     * Called by the controller before setting profile_completed = true.
     *
     * @param  array $data  The validated request data
     */
    public static function checkIfComplete(array $data): bool
    {
        $required = [
            'gender',
            'date_of_birth',
            'place_of_birth',
            'citizenship',
            'civil_status',
            'contact_number',
            'father_name',
            'mother_name',
            'address_street',
            'address_barangay',
            'address_municipality',
            'address_province',
            'address_zip_code',
        ];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Instance-level profile completion check.
     * Mirrors Alumni::isProfileComplete() — used for on-the-fly checks.
     */
    public function isComplete(): bool
    {
        return $this->profile_completed === true
            && !empty($this->gender)
            && !empty($this->date_of_birth)
            && !empty($this->place_of_birth)
            && !empty($this->citizenship)
            && !empty($this->civil_status)
            && !empty($this->contact_number)
            && !empty($this->father_name)
            && !empty($this->mother_name)
            && !empty($this->address_street)
            && !empty($this->address_barangay)
            && !empty($this->address_municipality)
            && !empty($this->address_province)
            && !empty($this->address_zip_code);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns the full formatted home address as a single string.
     */
    public function getFullAddress(): string
    {
        return trim(implode(', ', array_filter([
            $this->address_no,
            $this->address_street,
            $this->address_barangay,
            $this->address_municipality,
            $this->address_province,
            $this->address_zip_code,
        ])));
    }
}