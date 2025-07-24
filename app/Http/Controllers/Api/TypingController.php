<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\TypingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class TypingController extends Controller
{
    protected TypingService $typingService;

    public function __construct(TypingService $typingService)
    {
        $this->typingService = $typingService;
    }

    /**
     * Start typing in a conversation
     */
    public function startTyping(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('participate', $conversation);

        $this->typingService->startTyping($request->user()->id, $conversation->id);

        return response()->json([
            'message' => 'Typing started',
            'is_typing' => true
        ]);
    }

    /**
     * Stop typing in a conversation
     */
    public function stopTyping(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('participate', $conversation);

        $this->typingService->stopTyping($request->user()->id, $conversation->id);

        return response()->json([
            'message' => 'Typing stopped',
            'is_typing' => false
        ]);
    }

    /**
     * Toggle typing status
     */
    public function toggleTyping(Request $request, Conversation $conversation): JsonResponse
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

        if ($request->is_typing) {
            $this->typingService->startTyping($request->user()->id, $conversation->id);
        } else {
            $this->typingService->stopTyping($request->user()->id, $conversation->id);
        }

        return response()->json([
            'message' => $request->is_typing ? 'Typing started' : 'Typing stopped',
            'is_typing' => $request->is_typing
        ]);
    }

    /**
     * Get typing users in a conversation
     */
    public function getTypingUsers(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('participate', $conversation);

        $typingUsers = $this->typingService->getTypingUsers($conversation->id);

        return response()->json([
            'typing_users' => $typingUsers
        ]);
    }

    /**
     * Heartbeat to extend typing indicator
     */
    public function heartbeat(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('participate', $conversation);

        $this->typingService->heartbeat($request->user()->id, $conversation->id);

        return response()->json([
            'message' => 'Typing heartbeat received'
        ]);
    }
}