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
        'status',
        'profile_photo',

        // ── Account setup ─────────────────────────────────────────────────────
        'otp',
        'otp_expires_at',
        'password_changed_at',

        // ── Personal profile ──────────────────────────────────────────────────
        'gender',
        'date_of_birth',
        'place_of_birth',
        'citizenship',
        'civil_status',
        'blood_type',
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
     * Uses password_changed_at as the definitive flag:
     *   NULL   → wizard not done → redirect to wizard
     *   Filled → wizard done     → proceed to Gate 2
     */
    public function needsAccountSetup(): bool
    {
        return $this->password_changed_at === null;
    }

    /**
     * Stamp the wizard as complete.
     */
    public function markPasswordChanged(): void
    {
        $this->update(['password_changed_at' => now()]);
    }

    // ─────────────────────────────────────────────────────
    // Gate 2 — Profile Completion
    // ─────────────────────────────────────────────────────

    /**
     * TRUE = alumni has filled all required profile fields.
     *
     * FIX: Removed `$this->profile_completed === true` as a prerequisite.
     * The old code had a chicken-and-egg problem:
     *   - isProfileComplete() required profile_completed === true
     *   - but profile_completed was only set to true if isProfileComplete() returned true
     *   - so profile_completed could NEVER become true
     *
     * Now we check only the actual data fields. The profile_completed boolean
     * flag is still stored in the DB as a convenience cache (set by the
     * controller after saving), but it is NOT the source of truth here.
     */
    public function isProfileComplete(): bool
    {
        return !empty($this->gender)
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

    // ─────────────────────────────────────────────────────
    // OTP Helpers
    // ─────────────────────────────────────────────────────

    /**
     * Generate a 6-digit OTP, persist its hash + expiry, and
     * return the plain-text code so the caller can e-mail it.
     */
    public function generateOtp(): string
    {
        $plain = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->update([
            'otp'            => Hash::make($plain),
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        return $plain;
    }

    /**
     * Validate a submitted OTP against the stored hash.
     * Returns FALSE if expired, null, or hash mismatch.
     */
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

    /**
     * Returns TRUE if an OTP has been issued and its 10-minute
     * window has NOT yet expired.
     *
     * Used by the change-password wizard to block the alumni from
     * navigating back to Step 2 (email) while a live OTP session
     * is in progress — preventing email changes mid-verification.
     */
    public function isOtpStillActive(): bool
    {
        return $this->otp !== null
            && $this->otp_expires_at !== null
            && now()->lt($this->otp_expires_at);
    }

    /**
     * Wipe OTP columns after a successful verification.
     */
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

    /**
     * Returns the plain-text temporary password for this alumni.
     * Format: {student_id}_{Xx}   e.g. "00037801_De"
     */
    public function getPlainTempPassword(): string
    {
        $suffix = substr(trim($this->last_name), 0, 2);
        $suffix = ucfirst(strtolower($suffix));
        return $this->student_id . '_' . $suffix;
    }

    /**
     * Returns true if the alumni's User still holds the temp password.
     */
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