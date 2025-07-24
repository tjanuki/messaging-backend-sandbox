<?php

namespace App\Services;

use App\Models\User;
use App\Events\UserOnline;
use App\Events\UserOffline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class UserStatusService
{
    const ONLINE_CACHE_TTL = 300; // 5 minutes in seconds
    const HEARTBEAT_INTERVAL = 60; // 1 minute in seconds

    public function setUserOnline(int $userId): void
    {
        DB::transaction(function () use ($userId) {
            $user = User::findOrFail($userId);
            
            $wasOnline = $user->is_online;
            
            $user->update([
                'is_online' => true,
                'last_seen' => now(),
            ]);

            // Cache the user's online status
            Cache::put("user_online_{$userId}", true, self::ONLINE_CACHE_TTL);

            // Only broadcast if status changed
            if (!$wasOnline) {
                // Load conversations for broadcasting
                $user->load('conversations');
                broadcast(new UserOnline($user))->toOthers();
            }
        });
    }

    public function setUserOffline(int $userId): void
    {
        DB::transaction(function () use ($userId) {
            $user = User::findOrFail($userId);
            
            $wasOnline = $user->is_online;
            
            $user->update([
                'is_online' => false,
                'last_seen' => now(),
            ]);

            // Remove from cache
            Cache::forget("user_online_{$userId}");

            // Only broadcast if status changed
            if ($wasOnline) {
                // Load conversations for broadcasting
                $user->load('conversations');
                broadcast(new UserOffline($user))->toOthers();
            }
        });
    }

    public function heartbeat(int $userId): void
    {
        // Update cache TTL to keep user online
        Cache::put("user_online_{$userId}", true, self::ONLINE_CACHE_TTL);
        
        // Update last_seen timestamp
        User::where('id', $userId)->update([
            'last_seen' => now(),
        ]);
    }

    public function isUserOnline(int $userId): bool
    {
        // Check cache first, then database
        return Cache::get("user_online_{$userId}", function () use ($userId) {
            $user = User::find($userId);
            return $user ? $user->is_online : false;
        });
    }

    public function getOnlineUsers(array $userIds = []): array
    {
        $query = User::where('is_online', true)
            ->select('id', 'name', 'avatar', 'is_online', 'last_seen');

        if (!empty($userIds)) {
            $query->whereIn('id', $userIds);
        }

        return $query->get()->toArray();
    }

    public function cleanupOfflineUsers(): int
    {
        // Find users who should be offline based on cache expiration
        $onlineUsers = User::where('is_online', true)->get();
        $markedOfflineCount = 0;

        foreach ($onlineUsers as $user) {
            if (!Cache::has("user_online_{$user->id}")) {
                $this->setUserOffline($user->id);
                $markedOfflineCount++;
            }
        }

        return $markedOfflineCount;
    }

    public function getUsersInConversation(int $conversationId, bool $onlineOnly = false): array
    {
        $query = DB::table('conversation_participants')
            ->join('users', 'conversation_participants.user_id', '=', 'users.id')
            ->where('conversation_participants.conversation_id', $conversationId)
            ->select('users.id', 'users.name', 'users.avatar', 'users.is_online', 'users.last_seen');

        if ($onlineOnly) {
            $query->where('users.is_online', true);
        }

        return $query->get()->toArray();
    }

    public function getLastSeenText(User $user): string
    {
        if ($user->is_online) {
            return 'Online';
        }

        if (!$user->last_seen) {
            return 'Never seen';
        }

        $diffInMinutes = now()->diffInMinutes($user->last_seen);
        
        if ($diffInMinutes < 5) {
            return 'Just now';
        }
        
        if ($diffInMinutes < 60) {
            return "{$diffInMinutes} minutes ago";
        }
        
        $diffInHours = now()->diffInHours($user->last_seen);
        if ($diffInHours < 24) {
            return "{$diffInHours} hours ago";
        }
        
        $diffInDays = now()->diffInDays($user->last_seen);
        if ($diffInDays < 7) {
            return "{$diffInDays} days ago";
        }
        
        return $user->last_seen->format('M j, Y');
    }
}