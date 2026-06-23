<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    // ─────────────────────────────────────────────────────
    // Role Helpers
    // ─────────────────────────────────────────────────────
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isAlumni(): bool
    {
        return $this->role === 'alumni';
    }

    public function isOrganizer(): bool
    {
        return $this->role === 'organizer';
    }

    public function isRegistrar(): bool
    {
        return $this->role === 'registrar';
    }

    public function isDirector(): bool
    {
        return $this->role === 'director';
    }

    // ─────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────
    public function alumni()
    {
        return $this->hasOne(Alumni::class, 'user_id');
    }

    public function organizer()
    {
        return $this->hasOne(Organizer::class, 'user_id');
    }

    public function director()
    {
        return $this->hasOne(Director::class, 'user_id');
    }
}