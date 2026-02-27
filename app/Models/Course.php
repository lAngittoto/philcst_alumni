<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    use HasFactory;

    protected $table = 'courses';
    protected $primaryKey = 'id';
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    protected $casts = [
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get all alumni for this course
     */
    public function alumni()
    {
        return $this->hasMany(Alumni::class, 'course_code', 'code');
    }

    /**
     * Get count of alumni in this course
     */
    public function getAlumniCount(): int
    {
        return $this->alumni()->count();
    }

    /**
     * Scope: Search courses by code or name
     */
    public function scopeSearch($query, $search)
    {
        if (!$search) {
            return $query;
        }

        return $query->where('code', 'like', "%{$search}%")
            ->orWhere('name', 'like', "%{$search}%");
    }

    /**
     * Get full course name with code
     */
    public function getFullName(): string
    {
        return "{$this->code} — {$this->name}";
    }
}