<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ConversationController extends Controller
{
    /**
     * List user's conversations
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $conversations = $user->conversations()
            ->with(['lastMessage.user', 'participants'])
            ->orderBy('last_message_at', 'desc')
            ->get();

        return response()->json([
            'conversations' => $conversations
        ]);
    }

    /**
     * Create new conversation
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:direct,group',
            'name' => 'required_if:type,group|string|max:255',
            'participant_ids' => 'required|array|min:1',
            'participant_ids.*' => 'exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        $participantIds = $request->participant_ids;

        // For direct conversations, ensure only 2 participants (including creator)
        if ($request->type === 'direct' && count($participantIds) > 1) {
            return response()->json([
                'message' => 'Direct conversations can only have 2 participants'
            ], 422);
        }

        // Check if direct conversation already exists
        if ($request->type === 'direct') {
            $existingConversation = $user->conversations()
                ->where('type', 'direct')
                ->whereHas('participants', function ($query) use ($participantIds) {
                    $query->whereIn('user_id', $participantIds);
                })
                ->first();

            if ($existingConversation) {
                return response()->json([
                    'message' => 'Direct conversation already exists',
                    'conversation' => $existingConversation->load(['participants', 'lastMessage.user'])
                ]);
            }
        }

        $conversation = Conversation::create([
            'name' => $request->name,
            'type' => $request->type,
            'created_by' => $user->id,
        ]);

        // Add creator as participant (admin for groups)
        $conversation->participants()->attach($user->id, [
            'is_admin' => $request->type === 'group',
            'joined_at' => now(),
        ]);

        // Add other participants
        foreach ($participantIds as $participantId) {
            if ($participantId != $user->id) {
                $conversation->participants()->attach($participantId, [
                    'is_admin' => false,
                    'joined_at' => now(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Conversation created successfully',
            'conversation' => $conversation->load(['participants', 'lastMessage.user'])
        ], 201);
    }

    /**
     * Get conversation details
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $conversation->load(['participants', 'lastMessage.user']);

        return response()->json([
            'conversation' => $conversation
        ]);
    }

    /**
     * Update conversation
     */
    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $conversation->update($request->only(['name']));

        return response()->json([
            'message' => 'Conversation updated successfully',
            'conversation' => $conversation->load(['participants', 'lastMessage.user'])
        ]);
    }

    /**
     * Delete conversation
     */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);

        $conversation->delete();

        return response()->json([
            'message' => 'Conversation deleted successfully'
        ]);
    }

    /**
     * Add participant to conversation
     */
    public function addParticipant(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('manageParticipants', $conversation);

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'is_admin' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if user is already a participant
        if ($conversation->participants->contains($request->user_id)) {
            return response()->json([
                'message' => 'User is already a participant'
            ], 422);
        }

        $conversation->participants()->attach($request->user_id, [
            'is_admin' => $request->is_admin ?? false,
            'joined_at' => now(),
        ]);

        return response()->json([
            'message' => 'Participant added successfully',
            'conversation' => $conversation->load(['participants', 'lastMessage.user'])
        ]);
    }

    /**
     * Remove participant from conversation
     */
    public function removeParticipant(Request $request, Conversation $conversation, $userId): JsonResponse
    {
        // Allow if removing themselves or if they can manage participants
        if ($request->user()->id != $userId) {
            $this->authorize('manageParticipants', $conversation);
        }

        // Cannot remove creator
        if ($conversation->created_by == $userId) {
            return response()->json([
                'message' => 'Cannot remove conversation creator'
            ], 422);
        }

        $conversation->participants()->detach($userId);

        return response()->json([
            'message' => 'Participant removed successfully',
            'conversation' => $conversation->load(['participants', 'lastMessage.user'])
        ]);
    }
}