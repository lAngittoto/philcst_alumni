<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Relationship: User has one Organizer record
     */
    public function organizer()
    {
        return $this->hasOne(\App\Models\Organizer::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * NOTE: 'password' cast is intentionally REMOVED.
     * Laravel's 'hashed' cast auto-hashes on assignment via $model->password = 'plain'.
     * Since we use Hash::make() explicitly before storing, the cast would double-hash.
     * We control hashing manually in controllers/components.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // 'password' => 'hashed',  <-- REMOVED: causes double-hashing
        ];
    }
}