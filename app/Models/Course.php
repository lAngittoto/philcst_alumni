<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
}