<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
     * Get status color
     */


    /**
     * Get status icon
     */

}
