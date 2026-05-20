<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrarNotification extends Model
{
    protected $fillable = [
        'icon',
        'title',
        'message',
        'link_route',
        'link_label',
        'link_params',
        'read',
        'count',
        'dedup_key',   // ← REQUIRED: must be in fillable or it's always NULL in DB
    ];

    protected $casts = [
        'link_params' => 'array',
        'read'        => 'boolean',
        'count'       => 'integer',
    ];

    public function scopeUnread($query)
    {
        return $query->where('read', false);
    }

    // NOTE: Do NOT name this scopeLatest — it shadows Eloquent's built-in
    // latest() method and breaks ->latest('column') calls in the controller.
    public function scopeNewest($query)
    {
        return $query->orderByDesc('created_at');
    }
}