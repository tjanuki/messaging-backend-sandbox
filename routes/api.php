<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\TypingController;
use App\Http\Controllers\Api\NotificationController;

// Authentication routes (public)
Route::middleware(['throttle:auth'])->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Protected routes
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Authentication
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'updateProfile']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/fcm-token', [AuthController::class, 'updateFcmToken']);

    // Conversations
    Route::apiResource('conversations', ConversationController::class);
    Route::post('/conversations/{conversation}/participants', [ConversationController::class, 'addParticipant']);
    Route::delete('/conversations/{conversation}/participants/{userId}', [ConversationController::class, 'removeParticipant']);

    // Messages (with specific rate limiting for sending messages)
    Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);
    Route::middleware('throttle:messages')->group(function () {
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
    });
    Route::put('/messages/{message}', [MessageController::class, 'update']);
    Route::delete('/messages/{message}', [MessageController::class, 'destroy']);
    Route::post('/messages/{message}/reactions', [MessageController::class, 'addReaction']);
    Route::delete('/messages/{message}/reactions', [MessageController::class, 'removeReaction']);
    Route::post('/conversations/{conversation}/read', [MessageController::class, 'markAsRead']);

    // Typing indicators
    Route::post('/conversations/{conversation}/typing/start', [TypingController::class, 'startTyping']);
    Route::post('/conversations/{conversation}/typing/stop', [TypingController::class, 'stopTyping']);
    Route::post('/conversations/{conversation}/typing', [TypingController::class, 'toggleTyping']);
    Route::get('/conversations/{conversation}/typing', [TypingController::class, 'getTypingUsers']);
    Route::post('/conversations/{conversation}/typing/heartbeat', [TypingController::class, 'heartbeat']);

    // Legacy typing endpoint for backward compatibility
    Route::post('/conversations/{conversation}/typing-status', [StatusController::class, 'updateTypingStatus']);

    // User status and presence
    Route::post('/user/status', [StatusController::class, 'updateOnlineStatus']);
    Route::get('/users/online', [StatusController::class, 'getOnlineUsers']);
    Route::post('/user/heartbeat', [StatusController::class, 'heartbeat']);

    // Push notifications
    Route::post('/notifications/subscribe', [NotificationController::class, 'subscribeToTopic']);
    Route::post('/notifications/unsubscribe', [NotificationController::class, 'unsubscribeFromTopic']);
    Route::post('/notifications/test', [NotificationController::class, 'sendTestNotification']);
    Route::post('/notifications/topic', [NotificationController::class, 'sendTopicNotification']);
    Route::get('/notifications/preferences', [NotificationController::class, 'getNotificationPreferences']);
    Route::put('/notifications/preferences', [NotificationController::class, 'updateNotificationPreferences']);
    Route::post('/notifications/validate-token', [NotificationController::class, 'validateToken']);
});