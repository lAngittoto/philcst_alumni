<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoordinatorNotification extends Model
{
    protected $fillable = [
        'user_id',
        'icon',
        'title',
        'message',
        'link_route',
        'link_label',
        'dedup_key',
        'event_id',
        'read',
    ];

    protected $casts = [
        'read' => 'boolean',
    ];
}