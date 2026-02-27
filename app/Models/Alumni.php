<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Alumni extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'alumni';
    protected $primaryKey = 'id';
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'student_id',
        'name',
        'email',
        'course_code',
        'course_name',
        'batch',
        'status',
        'profile_photo',
    ];

    protected $casts = [
        'batch' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    /**
     * Search by name, student ID, or email
     */
    public function scopeSearch($query, $search)
    {
        if (!$search) {
            return $query;
        }

        return $query->where('name', 'like', "%{$search}%")
            ->orWhere('student_id', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%");
    }

    /**
     * Filter by batch/year
     */
    public function scopeByBatch($query, $batch)
    {
        if (!$batch || $batch == 'all') {
            return $query;
        }

        return $query->where('batch', $batch);
    }

    /**
     * Filter by course
     */
    public function scopeByCourse($query, $course)
    {
        if (!$course || $course == 'all') {
            return $query;
        }

        return $query->where('course_code', $course);
    }

    /**
     * Relationship with Course
     */
    public function course()
    {
        return $this->belongsTo(Course::class, 'course_code', 'code');
    }

    /**
     * Relationship with User (for login credentials)
     */
    public function user()
    {
        return $this->hasOne(User::class, 'email', 'email');
    }

    /**
     * Get status label with formatting
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'VERIFIED' => 'Verified',
            'PENDING' => 'Pending',
            'REJECTED' => 'Rejected',
            default => 'Unknown',
        };
    }

    /**
     * Get status color for badges
     */
    public function getStatusColor(): string
    {
        return match ($this->status) {
            'VERIFIED' => 'badge-ok',
            'PENDING' => 'badge-warn',
            'REJECTED' => 'badge-danger',
            default => 'badge-gray',
        };
    }

    /**
     * Check if alumni is verified
     */
    public function isVerified(): bool
    {
        return $this->status === 'VERIFIED';
    }

    /**
     * Mark as verified
     */
    public function markVerified(): void
    {
        $this->update(['status' => 'VERIFIED']);
    }

    /**
     * Get first letter of name for avatar fallback
     */
    public function getAvatarLetter(): string
    {
        return strtoupper(substr($this->name, 0, 1));
    }
}