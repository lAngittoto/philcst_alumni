<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organizer extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'organizer';
    protected $primaryKey = 'id';
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'id_number',
        'department',
        'profile_photo',
        'status',
        'notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'profile_photo_url',
        'display_name',
    ];

    /**
     * Relationship: Organizer belongs to User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: Get active organizers
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'ACTIVE');
    }

    /**
     * Scope: Get inactive organizers
     */
    public function scopeInactive($query)
    {
        return $query->where('status', 'INACTIVE');
    }

    /**
     * Scope: Get suspended organizers
     */
    public function scopeSuspended($query)
    {
        return $query->where('status', 'SUSPENDED');
    }

    /**
     * Scope: Get organizers by department
     */
    public function scopeByDepartment($query, $department)
    {
        if (!$department) {
            return $query;
        }

        return $query->where('department', $department);
    }

    /**
     * Get profile photo URL
     */
    public function getProfilePhotoUrlAttribute()
    {
        if ($this->profile_photo && file_exists(storage_path('app/public/' . $this->profile_photo))) {
            return asset('storage/' . $this->profile_photo);
        }

        return asset('storage/alumni-photos/default.png');
    }

    /**
     * Get full display name
     */
    public function getDisplayNameAttribute()
    {
        return "{$this->name} ({$this->id_number})";
    }

    /**
     * Get status label
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'ACTIVE' => 'Active',
            'INACTIVE' => 'Inactive',
            'SUSPENDED' => 'Suspended',
            default => 'Unknown',
        };
    }

    /**
     * Get status color for badges
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            'ACTIVE' => 'badge-ok',
            'INACTIVE' => 'badge-warn',
            'SUSPENDED' => 'badge-danger',
            default => 'badge-gray',
        };
    }

    /**
     * Check if organizer is active
     */
    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }

    /**
     * Mark as active
     */
    public function markActive(): void
    {
        $this->update(['status' => 'ACTIVE']);
    }

    /**
     * Mark as inactive
     */
    public function markInactive(): void
    {
        $this->update(['status' => 'INACTIVE']);
    }

    /**
     * Mark as suspended
     */
    public function markSuspended(): void
    {
        $this->update(['status' => 'SUSPENDED']);
    }

    /**
     * Get first letter of name for avatar fallback
     */
    public function getAvatarLetter(): string
    {
        return strtoupper(substr($this->name, 0, 1));
    }
}