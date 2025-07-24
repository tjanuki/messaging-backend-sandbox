<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Services\MessageService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller
{
    protected MessageService $messageService;

    public function __construct(MessageService $messageService)
    {
        $this->messageService = $messageService;
    }

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

        $messages = $this->messageService->getMessages($conversation->id, $page, $limit);

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

        $message = $this->messageService->createMessage(
            $conversation->id,
            $request->user()->id,
            $request->validated()
        );

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

        $updatedMessage = $this->messageService->updateMessage(
            $message->id,
            $request->validated()
        );

        return response()->json([
            'message' => $updatedMessage
        ]);
    }

    /**
     * Delete a message
     */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        $this->authorize('delete', $message);

        $this->messageService->deleteMessage($message->id);

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

        $reaction = $this->messageService->addReaction(
            $message->id,
            $request->user()->id,
            $request->emoji
        );

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

        $this->messageService->removeReaction(
            $message->id,
            $request->user()->id,
            $request->emoji
        );

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

        $this->messageService->markAsRead($conversation->id, $request->user()->id);

        return response()->json([
            'message' => 'Messages marked as read'
        ]);
    }
}