<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\TypingIndicator;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class StatusController extends Controller
{
    /**
     * Update typing status
     */
    public function updateTypingStatus(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('participate', $conversation);

        $validator = Validator::make($request->all(), [
            'is_typing' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $isTyping = $request->is_typing;

        if ($isTyping) {
            // Create or update typing indicator with expiration
            TypingIndicator::updateOrCreate(
                [
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                ],
                [
                    'is_typing' => true,
                    'expires_at' => now()->addSeconds(10), // Expire after 10 seconds
                ]
            );
        } else {
            // Remove typing indicator
            TypingIndicator::where([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
            ])->delete();
        }

        return response()->json([
            'message' => 'Typing status updated',
            'is_typing' => $isTyping
        ]);
    }

    /**
     * Get typing users for a conversation
     */
    public function getTypingUsers(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('participate', $conversation);

        // Clean up expired typing indicators
        TypingIndicator::where('expires_at', '<', now())->delete();

        $typingUsers = TypingIndicator::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $request->user()->id) // Exclude current user
            ->where('is_typing', true)
            ->where('expires_at', '>', now())
            ->with('user')
            ->get()
            ->pluck('user');

        return response()->json([
            'typing_users' => $typingUsers
        ]);
    }

    /**
     * Update user online status
     */
    public function updateOnlineStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'is_online' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $user->updateOnlineStatus($request->is_online);

        return response()->json([
            'message' => 'Online status updated',
            'is_online' => $request->is_online,
            'last_seen' => $user->last_seen
        ]);
    }

    /**
     * Get online users
     */
    public function getOnlineUsers(Request $request): JsonResponse
    {
        $onlineUsers = \App\Models\User::online()
            ->where('id', '!=', $request->user()->id)
            ->select(['id', 'name', 'email', 'avatar', 'is_online', 'last_seen'])
            ->get();

        return response()->json([
            'online_users' => $onlineUsers
        ]);
    }
}