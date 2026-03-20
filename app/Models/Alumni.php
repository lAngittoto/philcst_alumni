<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Alumni extends Model
{
    use HasFactory, SoftDeletes;

    protected $table      = 'alumni';
    protected $primaryKey = 'id';
    protected $keyType    = 'int';
    public    $timestamps = true;

    protected $fillable = [
        'user_id',          // ← added
        'first_name',
        'middle_initial',
        'last_name',
        'suffix',
        // 'name' is virtual — do NOT include
        'student_id',
        'email',
        'course_code',
        'course_name',
        'batch',
        'status',
        'profile_photo',
    ];

    protected $casts = [
        'batch'      => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $dates = ['deleted_at'];

    // ─────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────

    public function scopeSearch($query, $search)
    {
        if (!$search) return $query;

        return $query->where(function ($q) use ($search) {
            $q->where('name',       'like', "%{$search}%")
              ->orWhere('first_name', 'like', "%{$search}%")
              ->orWhere('last_name',  'like', "%{$search}%")
              ->orWhere('student_id', 'like', "%{$search}%")
              ->orWhere('email',      'like', "%{$search}%");
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
    // Relationships
    // ─────────────────────────────────────────────────────

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_code', 'code');
    }

    // Fixed: use user_id instead of email
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ─────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────

    public function getFullName(): string
    {
        return $this->name ?? trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_initial,
            $this->last_name,
            $this->suffix,
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

    public function isVerified(): bool
    {
        return $this->status === 'VERIFIED';
    }

    public function markVerified(): void
    {
        $this->update(['status' => 'VERIFIED']);
    }

    public function getAvatarLetter(): string
    {
        return strtoupper(substr($this->first_name ?? $this->name ?? '?', 0, 1));
    }

    public function getProfilePhotoUrl(): string
    {
        if (!$this->profile_photo) {
            return asset('storage/alumni-photos/default.png');
        }
        if (str_starts_with($this->profile_photo, 'alumni-photos/')) {
            return asset('storage/' . $this->profile_photo);
        }
        return asset('storage/alumni-photos/default.png');
    }
}