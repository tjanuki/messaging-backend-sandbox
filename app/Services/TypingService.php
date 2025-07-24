<?php

namespace App\Services;

use App\Models\User;
use App\Models\Conversation;
use App\Models\TypingIndicator;
use App\Events\UserTyping;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TypingService
{
    const TYPING_TIMEOUT = 30; // seconds

    public function startTyping(int $userId, int $conversationId): void
    {
        DB::transaction(function () use ($userId, $conversationId) {
            $user = User::findOrFail($userId);
            $conversation = Conversation::findOrFail($conversationId);

            // Update or create typing indicator
            TypingIndicator::updateOrCreate(
                [
                    'user_id' => $userId,
                    'conversation_id' => $conversationId,
                ],
                [
                    'is_typing' => true,
                    'expires_at' => now()->addSeconds(self::TYPING_TIMEOUT),
                ]
            );

            // Broadcast typing event
            broadcast(new UserTyping($user, $conversation, true))->toOthers();
        });
    }

    public function stopTyping(int $userId, int $conversationId): void
    {
        DB::transaction(function () use ($userId, $conversationId) {
            $user = User::findOrFail($userId);
            $conversation = Conversation::findOrFail($conversationId);

            // Remove typing indicator
            TypingIndicator::where([
                'user_id' => $userId,
                'conversation_id' => $conversationId,
            ])->delete();

            // Broadcast stop typing event
            broadcast(new UserTyping($user, $conversation, false))->toOthers();
        });
    }

    public function getTypingUsers(int $conversationId): array
    {
        $typingIndicators = TypingIndicator::where('conversation_id', $conversationId)
            ->where('is_typing', true)
            ->where('expires_at', '>', now())
            ->with('user:id,name,avatar')
            ->get();

        return $typingIndicators->map(function ($indicator) {
            return [
                'id' => $indicator->user->id,
                'name' => $indicator->user->name,
                'avatar' => $indicator->user->avatar,
            ];
        })->toArray();
    }

    public function cleanupExpiredTypingIndicators(): int
    {
        // This method should be called by a scheduled job
        $expiredIndicators = TypingIndicator::where('expires_at', '<', now())
            ->with(['user:id,name,avatar', 'conversation'])
            ->get();

        $deletedCount = 0;
        
        foreach ($expiredIndicators as $indicator) {
            // Broadcast stop typing event for expired indicators
            broadcast(new UserTyping(
                $indicator->user, 
                $indicator->conversation, 
                false
            ))->toOthers();
            
            $indicator->delete();
            $deletedCount++;
        }

        return $deletedCount;
    }

    public function heartbeat(int $userId, int $conversationId): void
    {
        // Extend typing indicator expiration
        TypingIndicator::where([
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'is_typing' => true,
        ])->update([
            'expires_at' => now()->addSeconds(self::TYPING_TIMEOUT),
        ]);
    }
}