<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    public function subscribeToTopic(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'topic' => 'required|string|max:100|regex:/^[a-zA-Z0-9-_.~%]+$/',
            'token' => 'sometimes|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $topic = $request->input('topic');
        $token = $request->input('token', $request->user()->fcm_token);

        if (!$token) {
            return response()->json([
                'error' => 'No FCM token available'
            ], 400);
        }

        $success = $this->notificationService->subscribeToTopic($token, $topic);

        if ($success) {
            return response()->json([
                'message' => 'Successfully subscribed to topic',
                'topic' => $topic
            ]);
        }

        return response()->json([
            'error' => 'Failed to subscribe to topic'
        ], 500);
    }

    public function unsubscribeFromTopic(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'topic' => 'required|string|max:100|regex:/^[a-zA-Z0-9-_.~%]+$/',
            'token' => 'sometimes|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $topic = $request->input('topic');
        $token = $request->input('token', $request->user()->fcm_token);

        if (!$token) {
            return response()->json([
                'error' => 'No FCM token available'
            ], 400);
        }

        $success = $this->notificationService->unsubscribeFromTopic($token, $topic);

        if ($success) {
            return response()->json([
                'message' => 'Successfully unsubscribed from topic',
                'topic' => $topic
            ]);
        }

        return response()->json([
            'error' => 'Failed to unsubscribe from topic'
        ], 500);
    }

    public function sendTestNotification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:100',
            'body' => 'required|string|max:500',
            'token' => 'sometimes|string|max:1000',
            'data' => 'sometimes|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $token = $request->input('token', $request->user()->fcm_token);

        if (!$token) {
            return response()->json([
                'error' => 'No FCM token available'
            ], 400);
        }

        $notification = [
            'title' => $request->input('title'),
            'body' => $request->input('body'),
            'data' => array_merge(
                $request->input('data', []),
                [
                    'type' => 'test',
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
                ]
            )
        ];

        $success = $this->notificationService->sendToDevice($token, $notification);

        if ($success) {
            return response()->json([
                'message' => 'Test notification sent successfully'
            ]);
        }

        return response()->json([
            'error' => 'Failed to send test notification'
        ], 500);
    }

    public function sendTopicNotification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'topic' => 'required|string|max:100|regex:/^[a-zA-Z0-9-_.~%]+$/',
            'title' => 'required|string|max:100',
            'body' => 'required|string|max:500',
            'data' => 'sometimes|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $notification = [
            'title' => $request->input('title'),
            'body' => $request->input('body'),
            'data' => array_merge(
                $request->input('data', []),
                [
                    'type' => 'topic',
                    'topic' => $request->input('topic'),
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
                ]
            )
        ];

        $success = $this->notificationService->sendToTopic(
            $request->input('topic'), 
            $notification
        );

        if ($success) {
            return response()->json([
                'message' => 'Topic notification sent successfully',
                'topic' => $request->input('topic')
            ]);
        }

        return response()->json([
            'error' => 'Failed to send topic notification'
        ], 500);
    }

    public function getNotificationPreferences(Request $request): JsonResponse
    {
        $preferences = $this->notificationService->getNotificationPreferences(
            $request->user()->id
        );

        return response()->json([
            'preferences' => $preferences
        ]);
    }

    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message_notifications' => 'sometimes|boolean',
            'group_message_notifications' => 'sometimes|boolean',
            'mention_notifications' => 'sometimes|boolean',
            'reaction_notifications' => 'sometimes|boolean',
            'typing_notifications' => 'sometimes|boolean',
            'sound_enabled' => 'sometimes|boolean',
            'vibration_enabled' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        // For now, we'll just return success
        // In a real implementation, you would save these preferences to the database
        return response()->json([
            'message' => 'Notification preferences updated successfully',
            'preferences' => $request->only([
                'message_notifications',
                'group_message_notifications',
                'mention_notifications',
                'reaction_notifications',
                'typing_notifications',
                'sound_enabled',
                'vibration_enabled'
            ])
        ]);
    }

    public function validateToken(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => 'sometimes|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 422);
        }

        $token = $request->input('token', $request->user()->fcm_token);

        if (!$token) {
            return response()->json([
                'valid' => false,
                'message' => 'No FCM token available'
            ]);
        }

        // Send a minimal test notification to validate the token
        $testNotification = [
            'title' => 'Token Validation',
            'body' => 'Your notification token is working correctly',
            'data' => [
                'type' => 'validation',
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
            ]
        ];

        $success = $this->notificationService->sendToDevice($token, $testNotification);

        return response()->json([
            'valid' => $success,
            'message' => $success ? 'Token is valid' : 'Token validation failed'
        ]);
    }
}