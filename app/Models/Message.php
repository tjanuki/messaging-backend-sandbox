<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'content',
        'type',
        'metadata',
        'edited_at'
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'edited_at' => 'datetime'
        ];
    }

    /**
     * Relationships
     */
    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reactions()
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * Scopes
     */
    public function scopeForConversation($query, $conversationId)
    {
        return $query->where('conversation_id', $conversationId);
    }

    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Methods
     */
    public function addReaction(User $user, string $emoji)
    {
        return $this->reactions()->updateOrCreate(
            ['user_id' => $user->id, 'emoji' => $emoji],
            ['created_at' => now()]
        );
    }

    public function removeReaction(User $user, string $emoji)
    {
        return $this->reactions()
                   ->where('user_id', $user->id)
                   ->where('emoji', $emoji)
                   ->delete();
    }
}
