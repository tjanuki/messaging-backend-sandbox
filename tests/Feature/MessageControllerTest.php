<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Conversation;
use App\Models\Message;
use App\Events\MessageSent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

class MessageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $otherUser;
    protected $conversation;

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
    }

    public function test_user_can_get_conversation_messages()
    {
        Sanctum::actingAs($this->user);

        // Create some messages
        Message::factory()->count(5)->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id
        ]);

        $response = $this->getJson("/api/conversations/{$this->conversation->id}/messages");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'messages' => [
                        '*' => [
                            'id', 'content', 'type', 'user_id', 'created_at',
                            'user' => ['id', 'name', 'email'],
                            'reactions'
                        ]
                    ],
                    'pagination' => [
                        'current_page', 'last_page', 'per_page', 'total'
                    ]
                ]);
    }

    public function test_user_cannot_get_messages_from_conversation_they_are_not_part_of()
    {
        Sanctum::actingAs($this->user);

        $otherConversation = Conversation::factory()->create();
        $otherConversation->participants()->attach($this->otherUser->id);

        $response = $this->getJson("/api/conversations/{$otherConversation->id}/messages");

        $response->assertStatus(403);
    }

    public function test_user_can_send_message_to_conversation()
    {
        Event::fake();
        Sanctum::actingAs($this->user);

        $messageData = [
            'content' => 'Hello, this is a test message!',
            'type' => 'text'
        ];

        $response = $this->postJson("/api/conversations/{$this->conversation->id}/messages", $messageData);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'id', 'content', 'type', 'user_id', 'conversation_id', 'created_at',
                    'user' => ['id', 'name', 'email']
                ])
                ->assertJson([
                    'content' => 'Hello, this is a test message!',
                    'type' => 'text',
                    'user_id' => $this->user->id
                ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'content' => 'Hello, this is a test message!'
        ]);

        Event::assertDispatched(MessageSent::class);
    }

    public function test_message_content_is_required()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/conversations/{$this->conversation->id}/messages", [
            'type' => 'text'
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['content']);
    }

    public function test_user_can_update_their_own_message()
    {
        Event::fake();
        Sanctum::actingAs($this->user);

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'content' => 'Original message'
        ]);

        $response = $this->putJson("/api/messages/{$message->id}", [
            'content' => 'Updated message content'
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'content' => 'Updated message content'
                ]);

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'content' => 'Updated message content'
        ]);

        $this->assertNotNull($message->fresh()->edited_at);
    }

    public function test_user_cannot_update_other_users_message()
    {
        Sanctum::actingAs($this->user);

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->otherUser->id,
            'content' => 'Original message'
        ]);

        $response = $this->putJson("/api/messages/{$message->id}", [
            'content' => 'Updated message content'
        ]);

        $response->assertStatus(403);
    }

    public function test_user_can_delete_their_own_message()
    {
        Event::fake();
        Sanctum::actingAs($this->user);

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id
        ]);

        $response = $this->deleteJson("/api/messages/{$message->id}");

        $response->assertStatus(204);

        $this->assertSoftDeleted('messages', ['id' => $message->id]);
    }

    public function test_user_cannot_delete_other_users_message()
    {
        Sanctum::actingAs($this->user);

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->otherUser->id
        ]);

        $response = $this->deleteJson("/api/messages/{$message->id}");

        $response->assertStatus(403);
    }

    public function test_user_can_add_reaction_to_message()
    {
        Event::fake();
        Sanctum::actingAs($this->user);

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->otherUser->id
        ]);

        $response = $this->postJson("/api/messages/{$message->id}/reactions", [
            'emoji' => '👍'
        ]);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'id', 'message_id', 'user_id', 'emoji', 'created_at'
                ]);

        $this->assertDatabaseHas('message_reactions', [
            'message_id' => $message->id,
            'user_id' => $this->user->id,
            'emoji' => '👍'
        ]);
    }

    public function test_user_can_remove_their_reaction()
    {
        Event::fake();
        Sanctum::actingAs($this->user);

        $message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->otherUser->id
        ]);

        // First add a reaction
        $message->reactions()->create([
            'user_id' => $this->user->id,
            'emoji' => '👍'
        ]);

        $response = $this->deleteJson("/api/messages/{$message->id}/reactions", [
            'emoji' => '👍'
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseMissing('message_reactions', [
            'message_id' => $message->id,
            'user_id' => $this->user->id,
            'emoji' => '👍'
        ]);
    }

    public function test_user_can_mark_messages_as_read()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson("/api/conversations/{$this->conversation->id}/read");

        $response->assertStatus(200)
                ->assertJson(['message' => 'Messages marked as read']);

        // Check that last_read_at was updated
        $participant = $this->conversation->participants()
                                        ->where('user_id', $this->user->id)
                                        ->first();
        
        $this->assertNotNull($participant->pivot->last_read_at);
    }

    public function test_messages_are_paginated()
    {
        Sanctum::actingAs($this->user);

        // Create 75 messages
        Message::factory()->count(75)->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id
        ]);

        $response = $this->getJson("/api/conversations/{$this->conversation->id}/messages?limit=20");

        $response->assertStatus(200)
                ->assertJsonPath('pagination.per_page', 20)
                ->assertJsonPath('pagination.total', 75);

        $this->assertCount(20, $response->json('messages'));
    }

    public function test_messages_are_ordered_by_creation_date()
    {
        Sanctum::actingAs($this->user);

        // Create messages with different timestamps
        $oldMessage = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'created_at' => now()->subHours(2)
        ]);

        $newMessage = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id,
            'created_at' => now()->subHour()
        ]);

        $response = $this->getJson("/api/conversations/{$this->conversation->id}/messages");

        $messages = $response->json('messages');
        
        // Messages should be ordered by creation date (newest first for pagination)
        $this->assertEquals($newMessage->id, $messages[0]['id']);
        $this->assertEquals($oldMessage->id, $messages[1]['id']);
    }
}