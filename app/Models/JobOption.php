<?php

namespace App\Models;  // ← tanggalin ang \Jobs

use Illuminate\Database\Eloquent\Model;

class JobOption extends Model
{
    protected $table = 'job_options';

    protected $fillable = [
        'type',
        'label',
    ];
}