<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;

class Alumni extends Model
{
    use HasFactory, SoftDeletes;

    protected $table      = 'alumni';
    protected $primaryKey = 'id';
    protected $keyType    = 'int';
    public    $timestamps = true;

    protected $fillable = [
        // ── Identity ──────────────────────────────────────────────────────────
        'user_id',
        'first_name',
        'middle_initial',
        'last_name',
        'suffix',
        'student_id',
        'email',
        'course_code',
        'course_name',
        'batch',
        'year_level',
        'status',
        'profile_photo',

        // ── Account setup ─────────────────────────────────────────────────────
        'otp',
        'otp_expires_at',
        'password_changed_at',

        // ── Personal profile (NEW fields — aligned with migration) ────────────
        'gender',
        'date_of_birth',
        'contact_number',

        // ── Father's name (split) ─────────────────────────────────────────────
        'father_last_name',
        'father_given_name',
        'father_middle_name',

        // ── Mother's maiden name (split) ──────────────────────────────────────
        'mother_last_name',
        'mother_given_name',
        'mother_middle_name',

        // ── DSWD / Disability ─────────────────────────────────────────────────
        'dswd_household_no',
        'disability',

        // ── Address ───────────────────────────────────────────────────────────
        'address_street',
        'address_barangay',
        'address_municipality',
        'address_province',

        // ── Status ────────────────────────────────────────────────────────────
        'profile_completed',
    ];

    protected $casts = [
        'batch'               => 'integer',
        'user_id'             => 'integer',
        'date_of_birth'       => 'date',
        'profile_completed'   => 'boolean',
        'otp_expires_at'      => 'datetime',
        'password_changed_at' => 'datetime',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
        'deleted_at'          => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    // ─────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_code', 'code');
    }

    // ─────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────

    public function scopeSearch($query, $search)
    {
        if (!$search) return $query;

        return $query->where(function ($q) use ($search) {
            $q->where('first_name',   'like', "%{$search}%")
              ->orWhere('last_name',  'like', "%{$search}%")
              ->orWhere('student_id', 'like', "%{$search}%")
              ->orWhere('course_code','like', "%{$search}%")
              ->orWhere('course_name','like', "%{$search}%");
        });
    }

    public function scopeByBatch($query, $batch)
    {
        if (!$batch || $batch === 'all') return $query;
        return $query->where('batch', $batch);
    }

    public function scopeByCourse($query, $course)
    {
        if (!$course || $course === 'all') return $query;
        return $query->where('course_code', $course);
    }

    // ─────────────────────────────────────────────────────
    // Gate 1 — Account Setup
    // ─────────────────────────────────────────────────────

    /**
     * TRUE = alumni has NOT yet completed the first-login wizard.
     * password_changed_at NULL = wizard not done → must go to wizard first.
     */
    public function needsAccountSetup(): bool
    {
        return $this->password_changed_at === null;
    }

    public function markPasswordChanged(): void
    {
        $this->update(['password_changed_at' => now()]);
    }

    // ─────────────────────────────────────────────────────
    // Gate 2 — Profile Completion
    // ─────────────────────────────────────────────────────

    /**
     * TRUE = profile is marked as complete in the database.
     *
     * We trust the profile_completed DB flag as the single source of truth.
     * This flag is set to TRUE by AlumniInformationController only after
     * all required fields have been validated and saved successfully.
     *
     * DO NOT re-check individual fields here — that caused a mismatch where
     * profile_completed = true in the DB but this method returned false
     * because some optional/legacy fields were empty.
     */
    public function isProfileComplete(): bool
    {
        return (bool) $this->profile_completed;
    }

    /**
     * Check if all required profile fields are filled.
     * Used by AlumniInformationController when saving the profile
     * to decide whether to set profile_completed = true.
     *
     * Keep this separate from isProfileComplete() so the two concerns
     * (field validation vs. completion status) don't get tangled.
     */
    public function hasAllRequiredFields(): bool
    {
        return !empty($this->gender)
            && !empty($this->date_of_birth)
            && !empty($this->contact_number)
            && !empty($this->father_last_name)
            && !empty($this->father_given_name)
            && !empty($this->mother_last_name)
            && !empty($this->mother_given_name)
            && !empty($this->address_street)
            && !empty($this->address_barangay)
            && !empty($this->address_municipality)
            && !empty($this->address_province);
    }

    // ─────────────────────────────────────────────────────
    // OTP Helpers
    // ─────────────────────────────────────────────────────

    public function generateOtp(): string
    {
        $plain = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->update([
            'otp'            => Hash::make($plain),
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        return $plain;
    }

    public function isOtpValid(string $plain): bool
    {
        if (!$this->otp || !$this->otp_expires_at) {
            return false;
        }

        if (now()->isAfter($this->otp_expires_at)) {
            return false;
        }

        return Hash::check($plain, $this->otp);
    }

    public function isOtpStillActive(): bool
    {
        return $this->otp !== null
            && $this->otp_expires_at !== null
            && now()->lt($this->otp_expires_at);
    }

    public function clearOtp(): void
    {
        $this->update([
            'otp'            => null,
            'otp_expires_at' => null,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // Password Helpers
    // ─────────────────────────────────────────────────────

    public function getPlainTempPassword(): string
    {
        $suffix = substr(trim($this->last_name), 0, 2);
        $suffix = ucfirst(strtolower($suffix));
        return $this->student_id . '_' . $suffix;
    }

    public function hasTemporaryPassword(): bool
    {
        $user = $this->user;
        if (!$user) return false;

        return Hash::check(
            $this->getPlainTempPassword(),
            $user->password
        );
    }

    public function isVerified(): bool
    {
        return $this->password_changed_at !== null;
    }

    public function isPending(): bool
    {
        return $this->password_changed_at === null;
    }

    // ─────────────────────────────────────────────────────
    // Display Helpers
    // ─────────────────────────────────────────────────────

    public function getFullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_initial ?: null,
            $this->last_name,
            $this->suffix         ?: null,
        ])));
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'VERIFIED' => 'Verified',
            'PENDING'  => 'Pending',
            'REJECTED' => 'Rejected',
            default    => 'Unknown',
        };
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            'VERIFIED' => 'badge-ok',
            'PENDING'  => 'badge-warn',
            'REJECTED' => 'badge-danger',
            default    => 'badge-gray',
        };
    }

    public function markVerified(): void
    {
        $this->update(['status' => 'VERIFIED']);
    }

    public function getAvatarLetter(): string
    {
        return strtoupper(substr($this->first_name ?? '?', 0, 1));
    }

    public function getProfilePhotoUrl(): string
    {
        if (!$this->profile_photo || str_contains($this->profile_photo, 'default.png')) {
            return asset('storage/alumni-photos/default.png');
        }

        if (str_starts_with($this->profile_photo, 'alumni-photos/')) {
            return asset('storage/' . $this->profile_photo);
        }

        return asset('storage/alumni-photos/default.png');
    }
}