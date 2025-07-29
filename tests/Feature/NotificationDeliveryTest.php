<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\NotificationService;
use App\Jobs\SendPushNotificationJob;
use App\Jobs\SendBulkNotificationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $otherUser;
    protected $conversation;
    protected $notificationService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create([
            'fcm_token' => 'sender_token_123'
        ]);
        
        $this->otherUser = User::factory()->create([
            'fcm_token' => 'receiver_token_456',
            'is_online' => false
        ]);
        
        $this->conversation = Conversation::factory()->create();
        $this->conversation->participants()->attach([
            $this->user->id,
            $this->otherUser->id
        ]);

        $this->notificationService = new NotificationService();
    }

    public function test_fcm_notification_is_sent_for_new_message()
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response([
                'success' => 1,
                'failure' => 0,
                'results' => [
                    ['message_id' => 'msg_123']
                ]
            ])
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'content' => 'Test notification message'
        ]);

        $result = $this->notificationService->sendMessageNotification($message);

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://fcm.googleapis.com/fcm/send' &&
                   $request->hasHeader('Authorization') &&
                   str_contains($request->header('Authorization')[0], 'key=');
        });
    }

    public function test_notification_contains_correct_message_data()
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response(['success' => 1])
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'content' => 'Hello from notification test'
        ]);

        $this->notificationService->sendMessageNotification($message);

        Http::assertSent(function ($request) use ($message) {
            $body = $request->data();
            
            return $body['to'] === 'receiver_token_456' &&
                   $body['notification']['body'] === 'Hello from notification test' &&
                   $body['data']['message_id'] == $message->id &&
                   $body['data']['conversation_id'] == $this->conversation->id &&
                   $body['data']['sender_id'] == $this->user->id;
        });
    }

    public function test_notification_is_not_sent_to_sender()
    {
        Http::fake();

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'content' => 'Self message'
        ]);

        $this->notificationService->sendMessageNotification($message);

        // Should not send to sender's token
        Http::assertNotSent(function ($request) {
            $body = $request->data();
            return isset($body['to']) && $body['to'] === 'sender_token_123';
        });
    }

    public function test_notification_is_not_sent_to_users_without_fcm_token()
    {
        Http::fake();

        // User without FCM token
        $userWithoutToken = User::factory()->create(['fcm_token' => null]);
        $this->conversation->participants()->attach($userWithoutToken->id);

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'content' => 'Test message'
        ]);

        $this->notificationService->sendMessageNotification($message);

        // Should only send one notification (to user with token)
        Http::assertSentCount(1);
    }

    public function test_notification_handles_fcm_service_failure()
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response([
                'success' => 0,
                'failure' => 1,
                'results' => [
                    ['error' => 'InvalidRegistration']
                ]
            ], 400)
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'content' => 'Test message'
        ]);

        $result = $this->notificationService->sendMessageNotification($message);

        $this->assertFalse($result);
    }

    public function test_bulk_notification_job_processes_multiple_tokens()
    {
        Queue::fake();

        $tokens = ['token1', 'token2', 'token3'];
        $notificationData = [
            'title' => 'Bulk Notification',
            'body' => 'This is a bulk notification',
            'data' => ['type' => 'bulk']
        ];

        SendBulkNotificationJob::dispatch($tokens, $notificationData);

        Queue::assertPushed(SendBulkNotificationJob::class, function ($job) use ($tokens, $notificationData) {
            return $job->tokens === $tokens && 
                   $job->notification['title'] === 'Bulk Notification';
        });
    }

    public function test_notification_job_handles_invalid_tokens()
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response([
                'success' => 0,
                'failure' => 1,
                'results' => [
                    ['error' => 'NotRegistered']
                ]
            ])
        ]);

        $job = new SendPushNotificationJob(
            'invalid_token',
            'Test Title',
            'Test Body',
            []
        );

        // Should not throw exception
        $job->handle($this->notificationService);

        Http::assertSent(function ($request) {
            return $request->data()['to'] === 'invalid_token';
        });
    }

    public function test_topic_subscription_works()
    {
        Http::fake([
            'iid.googleapis.com/*' => Http::response(['success' => 1])
        ]);

        $result = $this->notificationService->subscribeToTopic(
            'test_token_123',
            'general_updates'
        );

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'iid.googleapis.com') &&
                   str_contains($request->url(), 'test_token_123') &&
                   str_contains($request->url(), 'general_updates');
        });
    }

    public function test_topic_unsubscription_works()
    {
        Http::fake([
            'iid.googleapis.com/*' => Http::response(['success' => 1])
        ]);

        $result = $this->notificationService->unsubscribeFromTopic(
            'test_token_123',
            'general_updates'
        );

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE' &&
                   str_contains($request->url(), 'test_token_123') &&
                   str_contains($request->url(), 'general_updates');
        });
    }

    public function test_notification_to_topic_works()
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response(['success' => 1])
        ]);

        $result = $this->notificationService->sendToTopic(
            'general_updates',
            [
                'title' => 'Topic Notification',
                'body' => 'This is a topic notification',
                'data' => ['type' => 'topic']
            ]
        );

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['to'] === '/topics/general_updates' &&
                   $body['notification']['title'] === 'Topic Notification';
        });
    }

    public function test_notification_preferences_are_respected()
    {
        // User with notifications disabled
        $userWithDisabledNotifications = User::factory()->create([
            'fcm_token' => 'disabled_token',
            'notification_preferences' => json_encode([
                'message_notifications' => false
            ])
        ]);

        $this->conversation->participants()->attach($userWithDisabledNotifications->id);

        Http::fake();

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'content' => 'Test message'
        ]);

        $this->notificationService->sendMessageNotification($message);

        // Should not send to user with disabled notifications
        Http::assertNotSent(function ($request) {
            $body = $request->data();
            return isset($body['to']) && $body['to'] === 'disabled_token';
        });
    }

    public function test_notification_includes_conversation_name_for_groups()
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response(['success' => 1])
        ]);

        $groupConversation = Conversation::factory()->create([
            'type' => 'group',
            'name' => 'Test Group Chat'
        ]);

        $groupConversation->participants()->attach([
            $this->user->id,
            $this->otherUser->id
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $groupConversation->id,
            'user_id' => $this->user->id,
            'content' => 'Group message'
        ]);

        $this->notificationService->sendMessageNotification($message);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['notification']['title'] === 'Test Group Chat';
        });
    }

    public function test_notification_includes_sender_name_for_direct_chats()
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response(['success' => 1])
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'content' => 'Direct message'
        ]);

        $this->notificationService->sendMessageNotification($message);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['notification']['title'] === $this->user->name;
        });
    }

    public function test_notification_retry_mechanism()
    {
        // First attempt fails, second succeeds
        Http::fake([
            'fcm.googleapis.com/*' => Http::sequence()
                ->push(['error' => 'Unavailable'], 503)
                ->push(['success' => 1], 200)
        ]);

        $result = $this->notificationService->sendToDevice(
            'test_token',
            [
                'title' => 'Retry Test',
                'body' => 'Testing retry mechanism'
            ]
        );

        // Should eventually succeed
        $this->assertTrue($result);
    }

    public function test_notification_batching_for_multiple_recipients()
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response(['success' => 1])
        ]);

        // Add more users to conversation
        $users = User::factory()->count(5)->create();
        foreach ($users as $user) {
            $user->update(['fcm_token' => 'token_' . $user->id]);
            $this->conversation->participants()->attach($user->id);
        }

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'content' => 'Batch notification test'
        ]);

        $this->notificationService->sendMessageNotification($message);

        // Should send individual notifications to each participant
        Http::assertSentCount(6); // 5 new users + 1 original other user
    }

    public function test_notification_includes_click_action()
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response(['success' => 1])
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'content' => 'Click action test'
        ]);

        $this->notificationService->sendMessageNotification($message);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['notification']['click_action'] === 'FLUTTER_NOTIFICATION_CLICK';
        });
    }
}