<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserOnline implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function broadcastOn(): array
    {
        // Broadcast to all conversations where this user is a participant
        $conversationChannels = $this->user->conversations
            ->map(fn($conversation) => new PrivateChannel('conversation.' . $conversation->id))
            ->toArray();

        return array_merge($conversationChannels, [
            new PrivateChannel('user.' . $this->user->id)
        ]);
    }

    public function broadcastAs(): string
    {
        return 'user.online';
    }

    public function broadcastWith(): array
    {
        return [
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar' => $this->user->avatar,
                'is_online' => true,
                'last_seen' => $this->user->last_seen?->toISOString(),
            ]
        ];
    }
}