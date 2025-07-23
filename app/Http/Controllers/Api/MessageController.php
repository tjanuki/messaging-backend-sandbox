<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller
{
    /**
     * Get messages for a conversation
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('participate', $conversation);

        $validator = Validator::make($request->all(), [
            'page' => 'sometimes|integer|min:1',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $limit = $request->get('limit', 50);
        $page = $request->get('page', 1);

        $messages = $conversation->messages()
            ->with(['user', 'reactions.user'])
            ->orderBy('created_at', 'desc')
            ->paginate($limit, ['*'], 'page', $page);

        return response()->json([
            'messages' => $messages->items(),
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ]
        ]);
    }

    /**
     * Send a message
     */
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('participate', $conversation);

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:5000',
            'type' => 'sometimes|in:text,image,file,system',
            'metadata' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
            'content' => $request->content,
            'type' => $request->get('type', 'text'),
            'metadata' => $request->get('metadata'),
        ]);

        // Update conversation's last message
        $conversation->update([
            'last_message_id' => $message->id,
            'last_message_at' => $message->created_at,
        ]);

        $message->load(['user', 'reactions.user']);

        return response()->json([
            'message' => $message
        ], 201);
    }

    /**
     * Update/edit a message
     */
    public function update(Request $request, Message $message): JsonResponse
    {
        $this->authorize('update', $message);

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $message->update([
            'content' => $request->content,
            'edited_at' => now(),
        ]);

        $message->load(['user', 'reactions.user']);

        return response()->json([
            'message' => $message
        ]);
    }

    /**
     * Delete a message
     */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        $this->authorize('delete', $message);

        $message->delete();

        return response()->json([
            'message' => 'Message deleted successfully'
        ]);
    }

    /**
     * Add reaction to message
     */
    public function addReaction(Request $request, Message $message): JsonResponse
    {
        $this->authorize('react', $message);

        $validator = Validator::make($request->all(), [
            'emoji' => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $reaction = MessageReaction::updateOrCreate(
            [
                'message_id' => $message->id,
                'user_id' => $request->user()->id,
                'emoji' => $request->emoji,
            ],
            [
                'created_at' => now(),
            ]
        );

        $reaction->load('user');

        return response()->json([
            'reaction' => $reaction
        ], 201);
    }

    /**
     * Remove reaction from message
     */
    public function removeReaction(Request $request, Message $message): JsonResponse
    {
        $this->authorize('react', $message);

        $validator = Validator::make($request->all(), [
            'emoji' => 'required|string|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $reaction = MessageReaction::where([
            'message_id' => $message->id,
            'user_id' => $request->user()->id,
            'emoji' => $request->emoji,
        ])->first();

        if (!$reaction) {
            return response()->json([
                'message' => 'Reaction not found'
            ], 404);
        }

        $reaction->delete();

        return response()->json([
            'message' => 'Reaction removed successfully'
        ]);
    }

    /**
     * Mark messages as read
     */
    public function markAsRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('participate', $conversation);

        $conversation->participants()
            ->where('user_id', $request->user()->id)
            ->update(['last_read_at' => now()]);

        return response()->json([
            'message' => 'Messages marked as read'
        ]);
    }
}