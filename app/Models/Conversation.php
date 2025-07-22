<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'created_by',
        'last_message_id',
        'last_message_at'
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime'
        ];
    }

    /**
     * Relationships
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants()
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
                   ->withPivot('joined_at', 'last_read_at', 'is_admin')
                   ->withTimestamps();
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function lastMessage()
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function typingIndicators()
    {
        return $this->hasMany(TypingIndicator::class);
    }

    /**
     * Scopes
     */
    public function scopeForUser($query, $userId)
    {
        return $query->whereHas('participants', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        });
    }

    /**
     * Methods
     */
    public function addParticipant(User $user, bool $isAdmin = false)
    {
        return $this->participants()->attach($user->id, [
            'is_admin' => $isAdmin,
            'joined_at' => now()
        ]);
    }

    public function removeParticipant(User $user)
    {
        return $this->participants()->detach($user->id);
    }
}
