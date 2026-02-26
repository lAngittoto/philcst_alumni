<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organizer extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'organizer';  // ✅ Explicitly set table name (singular)

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
        return $query->where('department', $department);
    }

    /**
     * Get profile photo URL
     */
    public function getProfilePhotoUrlAttribute()
    {
        return $this->profile_photo 
            ? asset('storage/' . $this->profile_photo) 
            : null;
    }

    /**
     * Get full display name
     */
    public function getDisplayNameAttribute()
    {
        return "{$this->name} ({$this->id_number})";
    }
}