<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organizer extends Model
{
    use HasFactory, SoftDeletes;

    protected $table      = 'organizer';
    protected $primaryKey = 'id';
    protected $keyType    = 'int';
    public    $timestamps = true;

    protected $fillable = [
        'user_id',
        'first_name',
        'middle_initial',
        'last_name',
        'suffix',
        'email',
        'id_number',
        'department',
        'profile_photo',
        'status',
        'otp',
        'otp_expires_at',
        'password_reset_token',
        'password_reset_initiated_at',
        'password_changed_at',
    ];

    protected $casts = [
        'created_at'                  => 'datetime',
        'updated_at'                  => 'datetime',
        'deleted_at'                  => 'datetime',
        'otp_expires_at'              => 'datetime',
        'password_reset_initiated_at' => 'datetime',
        'password_changed_at'         => 'datetime',
    ];

    protected $appends = [
        'profile_photo_url',
        'display_name',
    ];

    // ===================================
    // Relationships
    // ===================================

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ===================================
    // Password Change Helpers
    // ===================================

    public function needsPasswordChange(): bool
    {
        return $this->password_changed_at === null;
    }

    public function isOtpValid(string $otp): bool
    {
        if (!$this->otp || !$this->otp_expires_at) {
            return false;
        }
        if ($this->otp !== $otp) {
            return false;
        }
        return now()->lessThanOrEqualTo($this->otp_expires_at);
    }

    public function generateOtp(): string
    {
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->update([
            'otp'            => $otp,
            'otp_expires_at' => now()->addMinutes(10),
        ]);
        return $otp;
    }

    public function clearOtp(): void
    {
        $this->update([
            'otp'            => null,
            'otp_expires_at' => null,
        ]);
    }

    public function markPasswordChanged(): void
    {
        $this->update([
            'password_changed_at'         => now(),
            'password_reset_token'        => null,
            'password_reset_initiated_at' => null,
            'otp'                         => null,
            'otp_expires_at'              => null,
        ]);
    }

    // ===================================
    // Scopes
    // ===================================

    public function scopeActive($query)
    {
        return $query->where('status', 'ACTIVE');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'INACTIVE');
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', 'SUSPENDED');
    }

    public function scopeByDepartment($query, $department)
    {
        if (!$department) {
            return $query;
        }
        return $query->where('department', $department);
    }

    public function scopeNeedsPasswordChange($query)
    {
        return $query->whereNull('password_changed_at');
    }

    // ===================================
    // Accessors
    // ===================================

    public function getProfilePhotoUrlAttribute()
    {
        if (!$this->profile_photo) {
            return asset('storage/organizers/default.png');
        }
        if (str_starts_with($this->profile_photo, 'organizers/')) {
            return asset('storage/' . $this->profile_photo);
        }
        return asset('storage/organizers/default.png');
    }

    public function getDisplayNameAttribute()
    {
        $name = $this->getFullName();
        return $name . ' (' . $this->id_number . ')';
    }

    // ===================================
    // Helpers
    // ===================================

    public function getFullName(): string
    {
        $parts = [];
        if ($this->first_name)     $parts[] = $this->first_name;
        if ($this->middle_initial) $parts[] = $this->middle_initial;
        if ($this->last_name)      $parts[] = $this->last_name;
        if ($this->suffix)         $parts[] = $this->suffix;
        return implode(' ', $parts);
    }

    public function getAvatarLetter(): string
    {
        return strtoupper(substr($this->first_name ?? '?', 0, 1));
    }

    public function getProfilePhotoUrl(): string
    {
        if (!$this->profile_photo) {
            return asset('storage/organizers/default.png');
        }
        if (str_starts_with($this->profile_photo, 'organizers/')) {
            return asset('storage/' . $this->profile_photo);
        }
        return asset('storage/organizers/default.png');
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'ACTIVE'    => 'Active',
            'INACTIVE'  => 'Inactive',
            'SUSPENDED' => 'Suspended',
            default     => 'Unknown',
        };
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            'ACTIVE'    => 'badge-ok',
            'INACTIVE'  => 'badge-warn',
            'SUSPENDED' => 'badge-danger',
            default     => 'badge-gray',
        };
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    public function markActive(): void
    {
        $this->update(['status' => 'ACTIVE']);
    }

    public function markInactive(): void
    {
        $this->update(['status' => 'INACTIVE']);
    }

    public function markSuspended(): void
    {
        $this->update(['status' => 'SUSPENDED']);
    }
}