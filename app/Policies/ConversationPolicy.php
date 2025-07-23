<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Determine whether the user can view any conversations.
     */
    public function viewAny(User $user): bool
    {
        return true; // Users can view their own conversations
    }

    /**
     * Determine whether the user can view the conversation.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->participants->contains($user);
    }

    /**
     * Determine whether the user can create conversations.
     */
    public function create(User $user): bool
    {
        return true; // All authenticated users can create conversations
    }

    /**
     * Determine whether the user can update the conversation.
     */
    public function update(User $user, Conversation $conversation): bool
    {
        // Only conversation creator or admins can update
        $participant = $conversation->participants()
            ->where('user_id', $user->id)
            ->first();

        return $conversation->created_by === $user->id || 
               ($participant && $participant->pivot->is_admin === true);
    }

    /**
     * Determine whether the user can delete the conversation.
     */
    public function delete(User $user, Conversation $conversation): bool
    {
        // Only conversation creator can delete
        return $conversation->created_by === $user->id;
    }

    /**
     * Determine whether the user can participate in the conversation.
     */
    public function participate(User $user, Conversation $conversation): bool
    {
        return $conversation->participants->contains($user);
    }

    /**
     * Determine whether the user can manage participants.
     */
    public function manageParticipants(User $user, Conversation $conversation): bool
    {
        // Only conversation creator or admins can manage participants
        $participant = $conversation->participants()
            ->where('user_id', $user->id)
            ->first();

        return $conversation->created_by === $user->id || 
               ($participant && $participant->pivot->is_admin === true);
    }
}