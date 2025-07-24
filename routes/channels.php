<?php

use Illuminate\Support\Facades\Broadcast;

// Default Laravel User channel
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private channel for conversation messages
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    // Check if user is participant of the conversation
    return $user->conversations()
                ->where('conversations.id', $conversationId)
                ->exists();
});

// Presence channel for conversation participants (online status)
Broadcast::channel('presence-conversation.{conversationId}', function ($user, $conversationId) {
    // Check if user is participant of the conversation
    $isParticipant = $user->conversations()
                          ->where('conversations.id', $conversationId)
                          ->exists();
    
    if ($isParticipant) {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->avatar,
            'is_online' => $user->is_online,
            'last_seen' => $user->last_seen?->toISOString(),
        ];
    }
    
    return false;
});

// Private channel for individual user notifications
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Presence channel for general online users (optional, for contact lists)
Broadcast::channel('presence-online-users', function ($user) {
    return [
        'id' => $user->id,
        'name' => $user->name,
        'avatar' => $user->avatar,
        'is_online' => $user->is_online,
        'last_seen' => $user->last_seen?->toISOString(),
    ];
});