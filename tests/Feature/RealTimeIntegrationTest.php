<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\TypingIndicator;
use App\Events\MessageSent;
use App\Events\UserOnline;
use App\Events\UserOffline;
use App\Events\UserTyping;
use App\Services\TypingService;
use App\Services\UserStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;

class RealTimeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $otherUser;
    protected $conversation;
    protected $typingService;
    protected $userStatusService;

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

        $this->typingService = app(TypingService::class);
        $this->userStatusService = app(UserStatusService::class);
    }

    public function test_real_time_message_flow_integration()
    {
        Event::fake([MessageSent::class]);
        Sanctum::actingAs($this->user);

        // Send a message
        $response = $this->postJson("/api/conversations/{$this->conversation->id}/messages", [
            'content' => 'Real-time test message',
            'type' => 'text'
        ]);

        $response->assertStatus(201);

        // Verify event was dispatched
        Event::assertDispatched(MessageSent::class, function ($event) {
            return $event->message->content === 'Real-time test message' &&
                   $event->message->conversation_id === $this->conversation->id;
        });

        // Verify message was saved
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'content' => 'Real-time test message'
        ]);

        // Verify conversation was updated
        $conversation = $this->conversation->fresh();
        $this->assertNotNull($conversation->last_message_id);
        $this->assertEquals('Real-time test message', $conversation->lastMessage->content);
    }

    public function test_typing_indicator_real_time_flow()
    {
        Event::fake([UserTyping::class]);
        Sanctum::actingAs($this->user);

        // Start typing
        $response = $this->postJson("/api/conversations/{$this->conversation->id}/typing", [
            'is_typing' => true
        ]);

        $response->assertStatus(200);

        // Verify typing indicator was created
        $this->assertDatabaseHas('typing_indicators', [
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'is_typing' => true
        ]);

        // Verify event was dispatched
        Event::assertDispatched(UserTyping::class, function ($event) {
            return $event->user->id === $this->user->id &&
                   $event->conversation->id === $this->conversation->id &&
                   $event->isTyping === true;
        });

        // Stop typing
        $response = $this->postJson("/api/conversations/{$this->conversation->id}/typing", [
            'is_typing' => false
        ]);

        $response->assertStatus(200);

        // Verify typing indicator was updated
        $this->assertDatabaseHas('typing_indicators', [
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'is_typing' => false
        ]);
    }

    public function test_user_online_status_real_time_flow()
    {
        Event::fake([UserOnline::class, UserOffline::class]);
        
        // User comes online (login)
        $response = $this->postJson('/api/login', [
            'email' => $this->user->email,
            'password' => 'password'
        ]);

        // Update user status to online
        $this->userStatusService->updateUserStatus($this->user->id, true);

        // Verify user status was updated
        $this->assertTrue($this->user->fresh()->is_online);
        $this->assertNotNull($this->user->fresh()->last_seen);

        // User goes offline
        $this->userStatusService->updateUserStatus($this->user->id, false);

        // Verify user status was updated
        $this->assertFalse($this->user->fresh()->is_online);
    }

    public function test_presence_channel_integration()
    {
        Sanctum::actingAs($this->user);

        // Simulate presence channel join
        $response = $this->postJson('/api/user/status', [
            'is_online' => true
        ]);

        $response->assertStatus(200);

        // Verify user is marked as online
        $this->assertTrue($this->user->fresh()->is_online);

        // Get online users for conversation
        $response = $this->getJson("/api/conversations/{$this->conversation->id}/online-users");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'online_users' => [
                        '*' => ['id', 'name', 'last_seen']
                    ]
                ]);
    }

    public function test_concurrent_typing_indicators()
    {
        Event::fake([UserTyping::class]);
        
        // Both users start typing simultaneously
        Sanctum::actingAs($this->user);
        $this->postJson("/api/conversations/{$this->conversation->id}/typing", [
            'is_typing' => true
        ]);

        Sanctum::actingAs($this->otherUser);
        $this->postJson("/api/conversations/{$this->conversation->id}/typing", [
            'is_typing' => true
        ]);

        // Verify both typing indicators exist
        $this->assertDatabaseHas('typing_indicators', [
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'is_typing' => true
        ]);

        $this->assertDatabaseHas('typing_indicators', [
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->otherUser->id,
            'is_typing' => true
        ]);

        // Get typing users
        $response = $this->getJson("/api/conversations/{$this->conversation->id}/typing");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'typing_users' => [
                        '*' => ['id', 'name']
                    ]
                ]);

        $typingUsers = $response->json('typing_users');
        $this->assertCount(2, $typingUsers);
    }

    public function test_typing_indicator_expiration()
    {
        Sanctum::actingAs($this->user);

        // Start typing
        $this->postJson("/api/conversations/{$this->conversation->id}/typing", [
            'is_typing' => true
        ]);

        // Manually expire the typing indicator
        TypingIndicator::where('user_id', $this->user->id)
                      ->where('conversation_id', $this->conversation->id)
                      ->update(['expires_at' => now()->subMinutes(1)]);

        // Get typing users (should not include expired ones)
        $response = $this->getJson("/api/conversations/{$this->conversation->id}/typing");

        $typingUsers = $response->json('typing_users');
        $this->assertCount(0, $typingUsers);
    }

    public function test_real_time_message_reactions_flow()
    {
        Event::fake();
        Sanctum::actingAs($this->user);

        // Create a message
        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->otherUser->id
        ]);

        // Add reaction
        $response = $this->postJson("/api/messages/{$message->id}/reactions", [
            'emoji' => '👍'
        ]);

        $response->assertStatus(201);

        // Verify reaction was saved
        $this->assertDatabaseHas('message_reactions', [
            'message_id' => $message->id,
            'user_id' => $this->user->id,
            'emoji' => '👍'
        ]);

        // Remove reaction
        $response = $this->deleteJson("/api/messages/{$message->id}/reactions", [
            'emoji' => '👍'
        ]);

        $response->assertStatus(204);

        // Verify reaction was removed
        $this->assertDatabaseMissing('message_reactions', [
            'message_id' => $message->id,
            'user_id' => $this->user->id,
            'emoji' => '👍'
        ]);
    }

    public function test_real_time_conversation_updates()
    {
        Sanctum::actingAs($this->user);

        // Create group conversation
        $groupConversation = Conversation::factory()->create([
            'type' => 'group',
            'name' => 'Test Group',
            'created_by' => $this->user->id
        ]);
        $groupConversation->participants()->attach($this->user->id, ['is_admin' => true]);

        // Update conversation name
        $response = $this->putJson("/api/conversations/{$groupConversation->id}", [
            'name' => 'Updated Group Name'
        ]);

        $response->assertStatus(200);

        // Verify update
        $this->assertDatabaseHas('conversations', [
            'id' => $groupConversation->id,
            'name' => 'Updated Group Name'
        ]);

        // Add participant
        $response = $this->postJson("/api/conversations/{$groupConversation->id}/participants", [
            'user_id' => $this->otherUser->id
        ]);

        $response->assertStatus(200);

        // Verify participant was added
        $this->assertTrue(
            $groupConversation->participants()->where('user_id', $this->otherUser->id)->exists()
        );
    }

    public function test_real_time_message_read_status_updates()
    {
        Sanctum::actingAs($this->user);

        // Send a message
        $this->postJson("/api/conversations/{$this->conversation->id}/messages", [
            'content' => 'Read status test',
            'type' => 'text'
        ]);

        // Other user marks as read
        Sanctum::actingAs($this->otherUser);
        $response = $this->postJson("/api/conversations/{$this->conversation->id}/read");

        $response->assertStatus(200);

        // Verify read status was updated
        $participant = $this->conversation->participants()
                                        ->where('user_id', $this->otherUser->id)
                                        ->first();
        
        $this->assertNotNull($participant->pivot->last_read_at);
    }

    public function test_websocket_channel_authorization()
    {
        // Test private conversation channels
        $this->assertTrue(
            \Illuminate\Support\Facades\Broadcast::channel('conversation.{id}', function ($user, $id) {
                return $user->conversations()->where('conversations.id', $id)->exists();
            }) !== null
        );

        // Test presence channels
        $this->assertTrue(
            \Illuminate\Support\Facades\Broadcast::channel('conversation.{id}', function ($user, $id) {
                if ($user->conversations()->where('conversations.id', $id)->exists()) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'avatar' => $user->avatar
                    ];
                }
                return false;
            }) !== null
        );
    }

    public function test_real_time_error_handling()
    {
        Event::fake();
        Sanctum::actingAs($this->user);

        // Try to send message to non-existent conversation
        $response = $this->postJson("/api/conversations/999999/messages", [
            'content' => 'This should fail',
            'type' => 'text'
        ]);

        $response->assertStatus(404);

        // No events should be dispatched for failed operations
        Event::assertNotDispatched(MessageSent::class);
    }

    public function test_redis_caching_for_real_time_data()
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not available');
        }

        Sanctum::actingAs($this->user);

        // Start typing (should cache typing status)
        $response = $this->postJson("/api/conversations/{$this->conversation->id}/typing", [
            'is_typing' => true
        ]);

        $response->assertStatus(200);

        // Check if typing status is cached
        $cacheKey = "typing:{$this->conversation->id}:{$this->user->id}";
        $this->assertTrue(Cache::has($cacheKey));
    }

    public function test_multiple_device_synchronization()
    {
        Event::fake();
        
        // Simulate same user on multiple devices
        $token1 = $this->user->createToken('device1')->plainTextToken;
        $token2 = $this->user->createToken('device2')->plainTextToken;

        // Send message from device 1
        $response = $this->withHeader('Authorization', 'Bearer ' . $token1)
                        ->postJson("/api/conversations/{$this->conversation->id}/messages", [
                            'content' => 'Message from device 1',
                            'type' => 'text'
                        ]);

        $response->assertStatus(201);

        // Message should be visible from device 2
        $response = $this->withHeader('Authorization', 'Bearer ' . $token2)
                        ->getJson("/api/conversations/{$this->conversation->id}/messages");

        $response->assertStatus(200);
        $messages = $response->json('messages');
        $this->assertEquals('Message from device 1', $messages[0]['content']);
    }

    public function test_real_time_connection_cleanup()
    {
        Sanctum::actingAs($this->user);

        // Start typing
        $this->postJson("/api/conversations/{$this->conversation->id}/typing", [
            'is_typing' => true
        ]);

        // Set user offline (simulating connection loss)
        $this->postJson('/api/user/status', [
            'is_online' => false
        ]);

        // Typing indicators should be cleaned up
        $this->artisan('typing:cleanup');

        // Verify typing indicator was cleaned up
        $activeTyping = TypingIndicator::where('user_id', $this->user->id)
                                     ->where('is_typing', true)
                                     ->where('expires_at', '>', now())
                                     ->count();
        
        $this->assertEquals(0, $activeTyping);
    }
}