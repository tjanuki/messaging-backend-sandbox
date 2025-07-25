<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use App\Models\Conversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class NotificationService
{
    protected string $fcmServerKey;
    protected string $fcmUrl = 'https://fcm.googleapis.com/fcm/send';

    public function __construct()
    {
        $this->fcmServerKey = config('services.fcm.server_key');
    }

    public function sendMessageNotification(Message $message): void
    {
        $conversation = $message->conversation;
        $sender = $message->user;

        // Get all participants except the sender who are offline
        $participants = $conversation->participants()
                                   ->where('user_id', '!=', $sender->id)
                                   ->whereNotNull('fcm_token')
                                   ->where('is_online', false)
                                   ->get();

        foreach ($participants as $participant) {
            $this->sendToDevice($participant->fcm_token, [
                'title' => $conversation->name ?: $sender->name,
                'body' => $this->formatMessageContent($message),
                'data' => [
                    'type' => 'message',
                    'conversation_id' => (string) $conversation->id,
                    'message_id' => (string) $message->id,
                    'sender_id' => (string) $sender->id,
                    'sender_name' => $sender->name,
                    'conversation_name' => $conversation->name,
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
                ]
            ]);
        }
    }

    public function sendToDevice(string $token, array $notification): bool
    {
        if (empty($this->fcmServerKey) || empty($token)) {
            Log::warning('FCM server key or device token is empty');
            return false;
        }

        try {
            $payload = [
                'to' => $token,
                'notification' => [
                    'title' => $notification['title'],
                    'body' => $notification['body'],
                    'click_action' => $notification['data']['click_action'] ?? 'FLUTTER_NOTIFICATION_CLICK',
                    'sound' => 'default',
                    'badge' => 1
                ],
                'data' => $notification['data'] ?? [],
                'priority' => 'high',
                'content_available' => true
            ];

            $response = Http::withHeaders([
                'Authorization' => 'key=' . $this->fcmServerKey,
                'Content-Type' => 'application/json'
            ])->timeout(30)->post($this->fcmUrl, $payload);

            $responseData = $response->json();

            if ($response->successful() && isset($responseData['success']) && $responseData['success'] > 0) {
                Log::info('FCM notification sent successfully', [
                    'token' => substr($token, 0, 20) . '...',
                    'response' => $responseData
                ]);
                return true;
            } else {
                Log::warning('FCM notification failed', [
                    'token' => substr($token, 0, 20) . '...',
                    'response' => $responseData,
                    'status' => $response->status()
                ]);
                
                // Handle invalid tokens
                if (isset($responseData['results'][0]['error']) && 
                    in_array($responseData['results'][0]['error'], ['InvalidRegistration', 'NotRegistered'])) {
                    $this->handleInvalidToken($token);
                }
                
                return false;
            }
        } catch (\Exception $e) {
            Log::error('FCM notification exception: ' . $e->getMessage(), [
                'token' => substr($token, 0, 20) . '...',
                'exception' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    public function sendToTopic(string $topic, array $notification): bool
    {
        if (empty($this->fcmServerKey)) {
            Log::warning('FCM server key is empty');
            return false;
        }

        try {
            $payload = [
                'to' => '/topics/' . $topic,
                'notification' => [
                    'title' => $notification['title'],
                    'body' => $notification['body'],
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'sound' => 'default'
                ],
                'data' => $notification['data'] ?? [],
                'priority' => 'high'
            ];

            $response = Http::withHeaders([
                'Authorization' => 'key=' . $this->fcmServerKey,
                'Content-Type' => 'application/json'
            ])->timeout(30)->post($this->fcmUrl, $payload);

            if ($response->successful()) {
                Log::info('FCM topic notification sent successfully', [
                    'topic' => $topic,
                    'response' => $response->json()
                ]);
                return true;
            } else {
                Log::warning('FCM topic notification failed', [
                    'topic' => $topic,
                    'response' => $response->json(),
                    'status' => $response->status()
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('FCM topic notification exception: ' . $e->getMessage(), [
                'topic' => $topic,
                'exception' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    public function subscribeToTopic(string $token, string $topic): bool
    {
        if (empty($this->fcmServerKey) || empty($token)) {
            Log::warning('FCM server key or device token is empty');
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'key=' . $this->fcmServerKey,
                'Content-Type' => 'application/json'
            ])->timeout(30)->post('https://iid.googleapis.com/iid/v1/' . $token . '/rel/topics/' . $topic);

            if ($response->successful()) {
                Log::info('FCM topic subscription successful', [
                    'token' => substr($token, 0, 20) . '...',
                    'topic' => $topic
                ]);
                return true;
            } else {
                Log::warning('FCM topic subscription failed', [
                    'token' => substr($token, 0, 20) . '...',
                    'topic' => $topic,
                    'response' => $response->json(),
                    'status' => $response->status()
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('FCM topic subscription exception: ' . $e->getMessage(), [
                'token' => substr($token, 0, 20) . '...',
                'topic' => $topic,
                'exception' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    public function unsubscribeFromTopic(string $token, string $topic): bool
    {
        if (empty($this->fcmServerKey) || empty($token)) {
            Log::warning('FCM server key or device token is empty');
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'key=' . $this->fcmServerKey,
                'Content-Type' => 'application/json'
            ])->timeout(30)->delete('https://iid.googleapis.com/iid/v1/' . $token . '/rel/topics/' . $topic);

            if ($response->successful()) {
                Log::info('FCM topic unsubscription successful', [
                    'token' => substr($token, 0, 20) . '...',
                    'topic' => $topic
                ]);
                return true;
            } else {
                Log::warning('FCM topic unsubscription failed', [
                    'token' => substr($token, 0, 20) . '...',
                    'topic' => $topic,
                    'response' => $response->json(),
                    'status' => $response->status()
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error('FCM topic unsubscription exception: ' . $e->getMessage(), [
                'token' => substr($token, 0, 20) . '...',
                'topic' => $topic,
                'exception' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    public function sendBulkNotifications(array $tokens, array $notification): array
    {
        if (empty($this->fcmServerKey)) {
            Log::warning('FCM server key is empty');
            return ['success' => 0, 'failure' => count($tokens)];
        }

        $chunks = array_chunk($tokens, 1000); // FCM supports up to 1000 tokens per request
        $totalSuccess = 0;
        $totalFailure = 0;

        foreach ($chunks as $tokenChunk) {
            try {
                $payload = [
                    'registration_ids' => $tokenChunk,
                    'notification' => [
                        'title' => $notification['title'],
                        'body' => $notification['body'],
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        'sound' => 'default'
                    ],
                    'data' => $notification['data'] ?? [],
                    'priority' => 'high'
                ];

                $response = Http::withHeaders([
                    'Authorization' => 'key=' . $this->fcmServerKey,
                    'Content-Type' => 'application/json'
                ])->timeout(30)->post($this->fcmUrl, $payload);

                $responseData = $response->json();

                if ($response->successful() && isset($responseData['success'])) {
                    $totalSuccess += $responseData['success'];
                    $totalFailure += $responseData['failure'];
                    
                    // Handle invalid tokens
                    if (isset($responseData['results'])) {
                        foreach ($responseData['results'] as $index => $result) {
                            if (isset($result['error']) && 
                                in_array($result['error'], ['InvalidRegistration', 'NotRegistered'])) {
                                $this->handleInvalidToken($tokenChunk[$index]);
                            }
                        }
                    }
                } else {
                    $totalFailure += count($tokenChunk);
                }
            } catch (\Exception $e) {
                Log::error('FCM bulk notification exception: ' . $e->getMessage());
                $totalFailure += count($tokenChunk);
            }
        }

        Log::info('FCM bulk notification completed', [
            'total_tokens' => count($tokens),
            'success' => $totalSuccess,
            'failure' => $totalFailure
        ]);

        return ['success' => $totalSuccess, 'failure' => $totalFailure];
    }

    protected function formatMessageContent(Message $message): string
    {
        switch ($message->type) {
            case 'image':
                return '📷 Sent an image';
            case 'file':
                return '📎 Sent a file';
            case 'system':
                return $message->content;
            default:
                return $message->content;
        }
    }

    protected function handleInvalidToken(string $token): void
    {
        // Remove invalid FCM tokens from users
        User::where('fcm_token', $token)->update(['fcm_token' => null]);
        
        Log::info('Removed invalid FCM token', [
            'token' => substr($token, 0, 20) . '...'
        ]);
    }

    public function updateUserFcmToken(int $userId, string $token): bool
    {
        $user = User::find($userId);
        
        if (!$user) {
            return false;
        }

        $user->update(['fcm_token' => $token]);
        
        Log::info('Updated user FCM token', [
            'user_id' => $userId,
            'token' => substr($token, 0, 20) . '...'
        ]);

        return true;
    }

    public function getNotificationPreferences(int $userId): array
    {
        $cacheKey = "notification_preferences:{$userId}";
        
        return Cache::remember($cacheKey, 3600, function () use ($userId) {
            // Default preferences - could be stored in database
            return [
                'message_notifications' => true,
                'group_message_notifications' => true,
                'mention_notifications' => true,
                'reaction_notifications' => false,
                'typing_notifications' => true,
                'sound_enabled' => true,
                'vibration_enabled' => true
            ];
        });
    }

    public function shouldSendNotification(int $userId, string $notificationType): bool
    {
        $preferences = $this->getNotificationPreferences($userId);
        
        return $preferences[$notificationType] ?? true;
    }
}