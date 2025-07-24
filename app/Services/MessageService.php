<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Events\MessageDeleted;
use App\Events\MessageReactionAdded;
use App\Events\MessageReactionRemoved;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MessageService
{
    public function getMessages(int $conversationId, int $page = 1, int $limit = 50): LengthAwarePaginator
    {
        return Message::forConversation($conversationId)
                     ->with(['user:id,name,avatar', 'reactions.user:id,name,avatar'])
                     ->recent()
                     ->paginate($limit, ['*'], 'page', $page);
    }

    public function createMessage(int $conversationId, int $userId, array $data): Message
    {
        return DB::transaction(function () use ($conversationId, $userId, $data) {
            $message = Message::create([
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'content' => $data['content'],
                'type' => $data['type'] ?? 'text',
                'metadata' => $data['metadata'] ?? null
            ]);

            // Load user relationship for broadcasting
            $message->load('user:id,name,avatar');

            // Update conversation's last message
            $conversation = Conversation::find($conversationId);
            $conversation->update([
                'last_message_id' => $message->id,
                'last_message_at' => $message->created_at
            ]);

            // Broadcast the message to conversation participants
            broadcast(new MessageSent($message))->toOthers();

            return $message;
        });
    }

    public function updateMessage(int $messageId, array $data): Message
    {
        return DB::transaction(function () use ($messageId, $data) {
            $message = Message::findOrFail($messageId);
            
            $message->update([
                'content' => $data['content'],
                'edited_at' => now()
            ]);

            // Load user relationship for broadcasting
            $message->load('user:id,name,avatar');

            // Broadcast the update to conversation participants
            broadcast(new MessageUpdated($message))->toOthers();

            return $message;
        });
    }

    public function deleteMessage(int $messageId): void
    {
        DB::transaction(function () use ($messageId) {
            $message = Message::findOrFail($messageId);
            
            // Broadcast the deletion before deleting (need the data for broadcasting)
            broadcast(new MessageDeleted($message))->toOthers();
            
            // If this was the last message in conversation, update conversation
            $conversation = $message->conversation;
            if ($conversation->last_message_id === $message->id) {
                $previousMessage = Message::forConversation($conversation->id)
                    ->where('id', '!=', $message->id)
                    ->recent()
                    ->first();
                
                $conversation->update([
                    'last_message_id' => $previousMessage?->id,
                    'last_message_at' => $previousMessage?->created_at
                ]);
            }
            
            $message->delete();
        });
    }

    public function addReaction(int $messageId, int $userId, string $emoji): MessageReaction
    {
        return DB::transaction(function () use ($messageId, $userId, $emoji) {
            $message = Message::findOrFail($messageId);
            
            $reaction = MessageReaction::updateOrCreate(
                [
                    'message_id' => $messageId,
                    'user_id' => $userId,
                    'emoji' => $emoji
                ],
                ['created_at' => now()]
            );

            // Load user relationship for broadcasting
            $reaction->load('user:id,name,avatar', 'message');

            // Broadcast the reaction to conversation participants
            broadcast(new MessageReactionAdded($reaction))->toOthers();

            return $reaction;
        });
    }

    public function removeReaction(int $messageId, int $userId, string $emoji): void
    {
        DB::transaction(function () use ($messageId, $userId, $emoji) {
            $reaction = MessageReaction::where([
                'message_id' => $messageId,
                'user_id' => $userId,
                'emoji' => $emoji
            ])->first();

            if ($reaction) {
                // Load relationships before broadcasting
                $reaction->load('user:id,name,avatar', 'message');
                
                // Broadcast the removal to conversation participants
                broadcast(new MessageReactionRemoved($reaction))->toOthers();
                
                $reaction->delete();
            }
        });
    }

    public function markAsRead(int $conversationId, int $userId): void
    {
        $conversation = Conversation::findOrFail($conversationId);
        
        $conversation->participants()
                    ->where('user_id', $userId)
                    ->update(['last_read_at' => now()]);
    }

    public function getUnreadCount(int $conversationId, int $userId): int
    {
        $participant = DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->first();

        if (!$participant) {
            return 0;
        }

        $lastReadAt = $participant->last_read_at;
        
        if (!$lastReadAt) {
            // If never read, count all messages
            return Message::forConversation($conversationId)->count();
        }

        return Message::forConversation($conversationId)
            ->where('created_at', '>', $lastReadAt)
            ->where('user_id', '!=', $userId) // Don't count own messages
            ->count();
    }
}