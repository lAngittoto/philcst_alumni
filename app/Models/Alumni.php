<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'user_id',
        'first_name',
        'middle_initial',   // existing DB column — kept for backward compat
        // 'middle_name',   // ← add this after running the migration below
        'last_name',
        'suffix',
        'student_id',
        'email',
        'course_code',
        'course_name',
        'batch',
        'status',
        'profile_photo',
        'otp',
        'otp_expires_at',
        'password_changed_at',
    ];

    /*
     * ── MIGRATION NOTE ────────────────────────────────────────────────────────
     * The wizard's Step 1 form now collects the full middle name instead of
     * just an initial. To rename the DB column run:
     *
     *   php artisan make:migration rename_middle_initial_to_middle_name_in_alumni_table
     *
     * Migration content:
     *
     *   Schema::table('alumni', function (Blueprint $table) {
     *       $table->renameColumn('middle_initial', 'middle_name');
     *   });
     *
     * After migrating:
     *   1. Replace 'middle_initial' with 'middle_name' in $fillable above.
     *   2. Update AlumniController::import() — change 'middle_initial' key to
     *      'middle_name' and adjust the validation regex to allow full names.
     *   3. Update getFullName() below if needed.
     *   4. In change-password.blade.php, the comparison line already reads:
     *         $dbMn = strtolower(trim($alumni->middle_initial ?? ''));
     *      Change it to:
     *         $dbMn = strtolower(trim($alumni->middle_name ?? ''));
     * ─────────────────────────────────────────────────────────────────────────
     */

    protected $casts = [
        'batch'               => 'integer',
        'user_id'             => 'integer',
        'otp_expires_at'      => 'datetime',
        'password_changed_at' => 'datetime',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
        'deleted_at'          => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    // ─────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────

    public function scopeSearch($query, $search)
    {
        if (! $search) return $query;

        return $query->where(function ($q) use ($search) {
            $q->where('first_name',  'like', "%{$search}%")
              ->orWhere('last_name',  'like', "%{$search}%")
              ->orWhere('student_id', 'like', "%{$search}%")
              ->orWhere('course_code','like', "%{$search}%")
              ->orWhere('course_name','like', "%{$search}%");
        });
    }

    public function scopeByBatch($query, $batch)
    {
        if (! $batch || $batch === 'all') return $query;
        return $query->where('batch', $batch);
    }

    public function scopeByCourse($query, $course)
    {
        if (! $course || $course === 'all') return $query;
        return $query->where('course_code', $course);
    }

    // ─────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_code', 'code');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ─────────────────────────────────────────────────────
    // Account Setup Gate — THE SINGLE SOURCE OF TRUTH
    // ─────────────────────────────────────────────────────

    /**
     * Returns TRUE when the alumni has NOT yet completed the first-login wizard.
     *
     * Uses `password_changed_at` as the definitive flag:
     *   NULL   → wizard not completed → redirect to wizard
     *   Filled → wizard completed     → allow dashboard access
     */
    public function needsAccountSetup(): bool
    {
        return $this->password_changed_at === null;
    }

    /**
     * Stamp the wizard as complete.
     * Called inside verifyOtp() after OTP verified + email/password saved.
     */
    public function markPasswordChanged(): void
    {
        $this->update(['password_changed_at' => now()]);
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
        if (! $this->otp || ! $this->otp_expires_at) {
            return false;
        }

        if (now()->isAfter($this->otp_expires_at)) {
            return false;
        }

        return Hash::check($plain, $this->otp);
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
        if (! $user) return false;

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
    // Helpers
    // ─────────────────────────────────────────────────────

    public function getFullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_initial ?: null,  // swap to middle_name after migration
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
        if (! $this->profile_photo || str_contains($this->profile_photo, 'default.png')) {
            return asset('storage/alumni-photos/default.png');
        }
        if (str_starts_with($this->profile_photo, 'alumni-photos/')) {
            return asset('storage/' . $this->profile_photo);
        }
        return asset('storage/alumni-photos/default.png');
    }
}