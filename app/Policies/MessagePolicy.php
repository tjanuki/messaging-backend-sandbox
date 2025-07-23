<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    /**
     * Determine whether the user can view any messages.
     */
    public function viewAny(User $user): bool
    {
        return true; // Users can view messages in conversations they participate in
    }

    /**
     * Determine whether the user can view the message.
     */
    public function view(User $user, Message $message): bool
    {
        return $message->conversation->participants->contains($user);
    }

    /**
     * Determine whether the user can create messages.
     */
    public function create(User $user): bool
    {
        return true; // Handled by conversation participation check
    }

    /**
     * Determine whether the user can update the message.
     */
    public function update(User $user, Message $message): bool
    {
        // Only message author can edit their own messages
        return $message->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the message.
     */
    public function delete(User $user, Message $message): bool
    {
        $conversation = $message->conversation;
        $participant = $conversation->participants()
            ->where('user_id', $user->id)
            ->first();

        // Message author, conversation creator, or conversation admin can delete
        return $message->user_id === $user->id || 
               $conversation->created_by === $user->id ||
               ($participant && $participant->pivot->is_admin === true);
    }

    /**
     * Determine whether the user can react to the message.
     */
    public function react(User $user, Message $message): bool
    {
        // Users can react if they're participants in the conversation
        return $message->conversation->participants->contains($user);
    }
}