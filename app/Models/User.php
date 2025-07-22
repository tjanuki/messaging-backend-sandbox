<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'fcm_token'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'fcm_token'
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_seen' => 'datetime',
            'is_online' => 'boolean'
        ];
    }

    /**
     * Relationships
     */
    public function conversations()
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
                   ->withPivot('joined_at', 'last_read_at', 'is_admin')
                   ->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function reactions()
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function typingIndicators()
    {
        return $this->hasMany(TypingIndicator::class);
    }

    /**
     * Scopes
     */
    public function scopeOnline($query)
    {
        return $query->where('is_online', true);
    }

    /**
     * Methods
     */
    public function updateOnlineStatus(bool $isOnline)
    {
        $this->update([
            'is_online' => $isOnline,
            'last_seen' => now()
        ]);
    }
}
