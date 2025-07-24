<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\TypingIndicator;
use App\Services\UserStatusService;
use App\Services\TypingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class StatusController extends Controller
{
    protected UserStatusService $userStatusService;
    protected TypingService $typingService;

    public function __construct(
        UserStatusService $userStatusService,
        TypingService $typingService
    ) {
        $this->userStatusService = $userStatusService;
        $this->typingService = $typingService;
    }

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

        if ($request->is_typing) {
            $this->typingService->startTyping($request->user()->id, $conversation->id);
        } else {
            $this->typingService->stopTyping($request->user()->id, $conversation->id);
        }

        return response()->json([
            'message' => 'Typing status updated',
            'is_typing' => $request->is_typing
        ]);
    }

    /**
     * Get typing users for a conversation
     */
    public function getTypingUsers(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('participate', $conversation);

        $typingUsers = $this->typingService->getTypingUsers($conversation->id);
        
        // Filter out current user
        $typingUsers = array_filter($typingUsers, function ($user) use ($request) {
            return $user['id'] !== $request->user()->id;
        });

        return response()->json([
            'typing_users' => array_values($typingUsers)
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

        if ($request->is_online) {
            $this->userStatusService->setUserOnline($request->user()->id);
        } else {
            $this->userStatusService->setUserOffline($request->user()->id);
        }

        return response()->json([
            'message' => 'Online status updated',
            'is_online' => $request->is_online,
            'last_seen' => $request->user()->fresh()->last_seen
        ]);
    }

    /**
     * Get online users
     */
    public function getOnlineUsers(Request $request): JsonResponse
    {
        $onlineUsers = $this->userStatusService->getOnlineUsers();
        
        // Filter out current user
        $onlineUsers = array_filter($onlineUsers, function ($user) use ($request) {
            return $user['id'] !== $request->user()->id;
        });

        return response()->json([
            'online_users' => array_values($onlineUsers)
        ]);
    }

    /**
     * Heartbeat to keep user online
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $this->userStatusService->heartbeat($request->user()->id);

        return response()->json([
            'message' => 'Heartbeat received',
            'timestamp' => now()->toISOString()
        ]);
    }
}