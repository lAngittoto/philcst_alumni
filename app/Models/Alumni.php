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

    public function scopeSearch($query, $search)
    {
        if (!$search) {
            return $query;
        }
        return $query->where('name', 'like', "%{$search}%")
            ->orWhere('student_id', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%");
    }

    public function scopeByBatch($query, $batch)
    {
        if (!$batch || $batch == 'all') {
            return $query;
        }
        return $query->where('batch', $batch);
    }

    public function scopeByCourse($query, $course)
    {
        if (!$course || $course == 'all') {
            return $query;
        }
        return $query->where('course_code', $course);
    }

    public function course()
    {
        return $this->belongsTo(Course::class, 'course_code', 'code');
    }

    public function user()
    {
        return $this->hasOne(User::class, 'email', 'email');
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'VERIFIED' => 'Verified',
            'PENDING' => 'Pending',
            'REJECTED' => 'Rejected',
            default => 'Unknown',
        };
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            'VERIFIED' => 'badge-ok',
            'PENDING' => 'badge-warn',
            'REJECTED' => 'badge-danger',
            default => 'badge-gray',
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
        return strtoupper(substr($this->name, 0, 1));
    }

    /**
     * Get full profile photo URL
     * NULL = show default
     * Any other value = prepend storage/ and return
     */
    public function getProfilePhotoUrl(): string
    {
        // If NULL or empty, use default
        if (!$this->profile_photo) {
            return asset('storage/alumni-photos/default.png');
        }

        // If it's a path starting with alumni-photos/, prepend storage/
        if (str_starts_with($this->profile_photo, 'alumni-photos/')) {
            return asset('storage/' . $this->profile_photo);
        }

        // Fallback to default
        return asset('storage/alumni-photos/default.png');
    }
}