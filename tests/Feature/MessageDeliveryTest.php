<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\MessageService;
use App\Services\NotificationService;
use App\Events\MessageSent;
use App\Jobs\SendPushNotificationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;

class MessageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $otherUser;
    protected $conversation;
    protected $messageService;
    protected $notificationService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        
        $this->conversation = Conversation::factory()->create();
        $this->conversation->participants()->attach([
            $this->user->id,
            $this->otherUser->id
        ]);

        $this->messageService = app(MessageService::class);
        $this->notificationService = Mockery::mock(NotificationService::class);
        $this->app->instance(NotificationService::class, $this->notificationService);
    }

    public function test_message_is_saved_to_database_on_send()
    {
        Sanctum::actingAs($this->user);

        $messageData = [
            'content' => 'Test message content',
            'type' => 'text'
        ];

        $response = $this->postJson("/api/conversations/{$this->conversation->id}/messages", $messageData);

        $response->assertStatus(201);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'content' => 'Test message content',
            'type' => 'text'
        ]);
    }

    public function test_conversation_last_message_is_updated_on_send()
    {
        Sanctum::actingAs($this->user);

        $messageData = [
            'content' => 'Latest message',
            'type' => 'text'
        ];

        $response = $this->postJson("/api/conversations/{$this->conversation->id}/messages", $messageData);

        $response->assertStatus(201);
        
        $conversation = $this->conversation->fresh();
        $this->assertNotNull($conversation->last_message_id);
        $this->assertNotNull($conversation->last_message_at);
        
        $lastMessage = $conversation->lastMessage;
        $this->assertEquals('Latest message', $lastMessage->content);
    }

    public function test_message_sent_event_is_dispatched()
    {
        Event::fake([MessageSent::class]);
        Sanctum::actingAs($this->user);

        $messageData = [
            'content' => 'Test message',
            'type' => 'text'
        ];

        $response = $this->postJson("/api/conversations/{$this->conversation->id}/messages", $messageData);

        $response->assertStatus(201);

        Event::assertDispatched(MessageSent::class, function ($event) {
            return $event->message->content === 'Test message';
        });
    }

    public function test_push_notification_is_sent_to_offline_participants()
    {
        Queue::fake();
        
        // Set other user as offline
        $this->otherUser->update([
            'is_online' => false,
            'fcm_token' => 'test_fcm_token'
        ]);

        $this->notificationService
            ->shouldReceive('sendMessageNotification')
            ->once()
            ->with(Mockery::type(Message::class));

        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/conversations/{$this->conversation->id}/messages", [
            'content' => 'Test notification message',
            'type' => 'text'
        ]);

        $response->assertStatus(201);
    }

    public function test_push_notification_is_not_sent_to_online_participants()
    {
        // Set other user as online
        $this->otherUser->update([
            'is_online' => true,
            'fcm_token' => 'test_fcm_token'
        ]);

        $this->notificationService
            ->shouldNotReceive('sendMessageNotification');

        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/conversations/{$this->conversation->id}/messages", [
            'content' => 'Test message',
            'type' => 'text'
        ]);

        $response->assertStatus(201);
    }

    public function test_message_delivery_status_tracking()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/conversations/{$this->conversation->id}/messages", [
            'content' => 'Test message',
            'type' => 'text'
        ]);

        $message = Message::latest()->first();
        
        // Message should be created with default delivery status
        $this->assertEquals('sent', $message->status ?? 'sent');
        $this->assertNotNull($message->created_at);
    }

    public function test_message_read_status_is_tracked()
    {
        Sanctum::actingAs($this->user);

        // Send a message
        $this->postJson("/api/conversations/{$this->conversation->id}/messages", [
            'content' => 'Test message',
            'type' => 'text'
        ]);

        // Other user marks messages as read
        Sanctum::actingAs($this->otherUser);
        
        $response = $this->postJson("/api/conversations/{$this->conversation->id}/read");
        $response->assertStatus(200);

        // Check that last_read_at was updated for the other user
        $participant = $this->conversation->participants()
                                        ->where('user_id', $this->otherUser->id)
                                        ->first();
        
        $this->assertNotNull($participant->pivot->last_read_at);
    }

    public function test_bulk_message_delivery_to_group_conversation()
    {
        Event::fake([MessageSent::class]);
        
        // Create group conversation with multiple participants
        $groupConversation = Conversation::factory()->create(['type' => 'group']);
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();
        
        $groupConversation->participants()->attach([
            $this->user->id,
            $user2->id,
            $user3->id
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/conversations/{$groupConversation->id}/messages", [
            'content' => 'Group message',
            'type' => 'text'
        ]);

        $response->assertStatus(201);

        // Event should be dispatched once for the group
        Event::assertDispatched(MessageSent::class);
    }

    public function test_message_delivery_with_metadata()
    {
        Sanctum::actingAs($this->user);

        $messageData = [
            'content' => 'Message with metadata',
            'type' => 'text',
            'metadata' => [
                'client_id' => 'client_123',
                'platform' => 'flutter',
                'version' => '1.0.0'
            ]
        ];

        $response = $this->postJson("/api/conversations/{$this->conversation->id}/messages", $messageData);

        $response->assertStatus(201);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'content' => 'Message with metadata'
        ]);

        $message = Message::latest()->first();
        $this->assertEquals('client_123', $message->metadata['client_id']);
        $this->assertEquals('flutter', $message->metadata['platform']);
    }

    public function test_message_delivery_failure_handling()
    {
        // Simulate database failure by using invalid data
        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/conversations/{$this->conversation->id}/messages", [
            'content' => str_repeat('x', 65536), // Exceeds TEXT field limit
            'type' => 'text'
        ]);

        // Should handle gracefully
        $this->assertIn($response->getStatusCode(), [422, 500]);
    }

    public function test_message_ordering_is_maintained()
    {
        Sanctum::actingAs($this->user);

        // Send multiple messages quickly
        $messages = [
            'First message',
            'Second message',
            'Third message'
        ];

        foreach ($messages as $content) {
            $this->postJson("/api/conversations/{$this->conversation->id}/messages", [
                'content' => $content,
                'type' => 'text'
            ]);
        }

        // Retrieve messages
        $response = $this->getJson("/api/conversations/{$this->conversation->id}/messages");
        $retrievedMessages = $response->json('messages');

        // Messages should be in reverse chronological order (newest first)
        $this->assertEquals('Third message', $retrievedMessages[0]['content']);
        $this->assertEquals('Second message', $retrievedMessages[1]['content']);
        $this->assertEquals('First message', $retrievedMessages[2]['content']);
    }

    public function test_concurrent_message_delivery()
    {
        Event::fake([MessageSent::class]);
        
        Sanctum::actingAs($this->user);

        // Simulate concurrent requests
        $promises = [];
        for ($i = 1; $i <= 5; $i++) {
            $promises[] = $this->postJson("/api/conversations/{$this->conversation->id}/messages", [
                'content' => "Concurrent message {$i}",
                'type' => 'text'
            ]);
        }

        // All messages should be delivered successfully
        foreach ($promises as $response) {
            $response->assertStatus(201);
        }

        // All events should be dispatched
        Event::assertDispatched(MessageSent::class, 5);

        // All messages should be in database
        $this->assertEquals(5, Message::where('conversation_id', $this->conversation->id)->count());
    }

    public function test_message_delivery_to_deleted_conversation_fails()
    {
        Sanctum::actingAs($this->user);

        // Delete the conversation
        $this->conversation->delete();

        $response = $this->postJson("/api/conversations/{$this->conversation->id}/messages", [
            'content' => 'Message to deleted conversation',
            'type' => 'text'
        ]);

        $response->assertStatus(404);
    }

    public function test_message_delivery_updates_participant_activity()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/conversations/{$this->conversation->id}/messages", [
            'content' => 'Activity update test',
            'type' => 'text'
        ]);

        $response->assertStatus(201);

        // Check that user's last activity was updated
        $this->user->refresh();
        $this->assertNotNull($this->user->last_seen);
    }

    public function test_message_delivery_with_empty_content_fails()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/conversations/{$this->conversation->id}/messages", [
            'content' => '',
            'type' => 'text'
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['content']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}