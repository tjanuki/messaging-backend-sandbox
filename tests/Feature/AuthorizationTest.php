<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $otherUser;
    protected $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->adminUser = User::factory()->create();
    }

    public function test_user_can_only_access_their_own_conversations()
    {
        Sanctum::actingAs($this->user);

        // Create conversation for this user
        $userConversation = Conversation::factory()->create();
        $userConversation->participants()->attach($this->user->id);

        // Create conversation for other user
        $otherConversation = Conversation::factory()->create();
        $otherConversation->participants()->attach($this->otherUser->id);

        // User can access their conversation
        $response = $this->getJson("/api/conversations/{$userConversation->id}");
        $response->assertStatus(200);

        // User cannot access other's conversation
        $response = $this->getJson("/api/conversations/{$otherConversation->id}");
        $response->assertStatus(403);
    }

    public function test_user_can_only_send_messages_to_conversations_they_participate_in()
    {
        Sanctum::actingAs($this->user);

        // Conversation user participates in
        $participantConversation = Conversation::factory()->create();
        $participantConversation->participants()->attach($this->user->id);

        // Conversation user doesn't participate in
        $nonParticipantConversation = Conversation::factory()->create();
        $nonParticipantConversation->participants()->attach($this->otherUser->id);

        // Can send message to conversation they participate in
        $response = $this->postJson("/api/conversations/{$participantConversation->id}/messages", [
            'content' => 'Hello world',
            'type' => 'text'
        ]);
        $response->assertStatus(201);

        // Cannot send message to conversation they don't participate in
        $response = $this->postJson("/api/conversations/{$nonParticipantConversation->id}/messages", [
            'content' => 'Hello world',
            'type' => 'text'
        ]);
        $response->assertStatus(403);
    }

    public function test_user_can_only_edit_their_own_messages()
    {
        Sanctum::actingAs($this->user);

        $conversation = Conversation::factory()->create();
        $conversation->participants()->attach([$this->user->id, $this->otherUser->id]);

        // User's own message
        $userMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->user->id
        ]);

        // Other user's message
        $otherMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->otherUser->id
        ]);

        // Can edit own message
        $response = $this->putJson("/api/messages/{$userMessage->id}", [
            'content' => 'Updated content'
        ]);
        $response->assertStatus(200);

        // Cannot edit other's message
        $response = $this->putJson("/api/messages/{$otherMessage->id}", [
            'content' => 'Updated content'
        ]);
        $response->assertStatus(403);
    }

    public function test_user_can_only_delete_their_own_messages()
    {
        Sanctum::actingAs($this->user);

        $conversation = Conversation::factory()->create();
        $conversation->participants()->attach([$this->user->id, $this->otherUser->id]);

        // User's own message
        $userMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->user->id
        ]);

        // Other user's message
        $otherMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->otherUser->id
        ]);

        // Can delete own message
        $response = $this->deleteJson("/api/messages/{$userMessage->id}");
        $response->assertStatus(204);

        // Cannot delete other's message
        $response = $this->deleteJson("/api/messages/{$otherMessage->id}");
        $response->assertStatus(403);
    }

    public function test_only_conversation_creator_can_delete_conversation()
    {
        // User is creator
        Sanctum::actingAs($this->user);

        $userConversation = Conversation::factory()->create([
            'created_by' => $this->user->id
        ]);
        $userConversation->participants()->attach($this->user->id);

        $otherConversation = Conversation::factory()->create([
            'created_by' => $this->otherUser->id
        ]);
        $otherConversation->participants()->attach([$this->user->id, $this->otherUser->id]);

        // Can delete conversation they created
        $response = $this->deleteJson("/api/conversations/{$userConversation->id}");
        $response->assertStatus(204);

        // Cannot delete conversation created by others
        $response = $this->deleteJson("/api/conversations/{$otherConversation->id}");
        $response->assertStatus(403);
    }

    public function test_only_admin_can_update_group_conversation_details()
    {
        $conversation = Conversation::factory()->create([
            'type' => 'group',
            'created_by' => $this->adminUser->id
        ]);

        // Admin user
        $conversation->participants()->attach($this->adminUser->id, ['is_admin' => true]);
        // Regular user
        $conversation->participants()->attach($this->user->id, ['is_admin' => false]);

        // Admin can update
        Sanctum::actingAs($this->adminUser);
        $response = $this->putJson("/api/conversations/{$conversation->id}", [
            'name' => 'Updated Group Name'
        ]);
        $response->assertStatus(200);

        // Regular user cannot update
        Sanctum::actingAs($this->user);
        $response = $this->putJson("/api/conversations/{$conversation->id}", [
            'name' => 'Another Update'
        ]);
        $response->assertStatus(403);
    }

    public function test_only_admin_can_add_participants_to_group()
    {
        $conversation = Conversation::factory()->create([
            'type' => 'group',
            'created_by' => $this->adminUser->id
        ]);

        $conversation->participants()->attach($this->adminUser->id, ['is_admin' => true]);
        $conversation->participants()->attach($this->user->id, ['is_admin' => false]);

        $newUser = User::factory()->create();

        // Admin can add participants
        Sanctum::actingAs($this->adminUser);
        $response = $this->postJson("/api/conversations/{$conversation->id}/participants", [
            'user_id' => $newUser->id
        ]);
        $response->assertStatus(200);

        // Regular user cannot add participants
        $anotherNewUser = User::factory()->create();
        Sanctum::actingAs($this->user);
        $response = $this->postJson("/api/conversations/{$conversation->id}/participants", [
            'user_id' => $anotherNewUser->id
        ]);
        $response->assertStatus(403);
    }

    public function test_only_admin_can_remove_participants_from_group()
    {
        $conversation = Conversation::factory()->create([
            'type' => 'group',
            'created_by' => $this->adminUser->id
        ]);

        $conversation->participants()->attach($this->adminUser->id, ['is_admin' => true]);
        $conversation->participants()->attach($this->user->id, ['is_admin' => false]);
        $conversation->participants()->attach($this->otherUser->id, ['is_admin' => false]);

        // Admin can remove participants
        Sanctum::actingAs($this->adminUser);
        $response = $this->deleteJson("/api/conversations/{$conversation->id}/participants/{$this->otherUser->id}");
        $response->assertStatus(200);

        // Regular user cannot remove participants
        Sanctum::actingAs($this->user);
        $response = $this->deleteJson("/api/conversations/{$conversation->id}/participants/{$this->otherUser->id}");
        $response->assertStatus(403);
    }

    public function test_user_can_add_reactions_to_messages_in_their_conversations()
    {
        $conversation = Conversation::factory()->create();
        $conversation->participants()->attach([$this->user->id, $this->otherUser->id]);

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->otherUser->id
        ]);

        Sanctum::actingAs($this->user);

        // Can add reaction to message in conversation they participate in
        $response = $this->postJson("/api/messages/{$message->id}/reactions", [
            'emoji' => '👍'
        ]);
        $response->assertStatus(201);
    }

    public function test_user_cannot_add_reactions_to_messages_in_conversations_they_dont_participate_in()
    {
        $conversation = Conversation::factory()->create();
        $conversation->participants()->attach($this->otherUser->id);

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->otherUser->id
        ]);

        Sanctum::actingAs($this->user);

        // Cannot add reaction to message in conversation they don't participate in
        $response = $this->postJson("/api/messages/{$message->id}/reactions", [
            'emoji' => '👍'
        ]);
        $response->assertStatus(403);
    }

    public function test_user_can_only_remove_their_own_reactions()
    {
        $conversation = Conversation::factory()->create();
        $conversation->participants()->attach([$this->user->id, $this->otherUser->id]);

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->otherUser->id
        ]);

        // Add reactions from both users
        $message->reactions()->create([
            'user_id' => $this->user->id,
            'emoji' => '👍'
        ]);

        $message->reactions()->create([
            'user_id' => $this->otherUser->id,
            'emoji' => '👍'
        ]);

        Sanctum::actingAs($this->user);

        // Can remove own reaction
        $response = $this->deleteJson("/api/messages/{$message->id}/reactions", [
            'emoji' => '👍'
        ]);
        $response->assertStatus(204);

        // Verify only user's reaction was removed
        $this->assertDatabaseMissing('message_reactions', [
            'message_id' => $message->id,
            'user_id' => $this->user->id,
            'emoji' => '👍'
        ]);

        $this->assertDatabaseHas('message_reactions', [
            'message_id' => $message->id,
            'user_id' => $this->otherUser->id,
            'emoji' => '👍'
        ]);
    }

    public function test_direct_conversation_cannot_have_name_updated()
    {
        $conversation = Conversation::factory()->create([
            'type' => 'direct',
            'created_by' => $this->user->id
        ]);
        $conversation->participants()->attach($this->user->id);

        Sanctum::actingAs($this->user);

        $response = $this->putJson("/api/conversations/{$conversation->id}", [
            'name' => 'New Name'
        ]);

        $response->assertStatus(403);
    }

    public function test_user_can_leave_group_conversation()
    {
        $conversation = Conversation::factory()->create([
            'type' => 'group',
            'created_by' => $this->otherUser->id
        ]);

        $conversation->participants()->attach([
            $this->otherUser->id => ['is_admin' => true],
            $this->user->id => ['is_admin' => false]
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->deleteJson("/api/conversations/{$conversation->id}/participants/{$this->user->id}");
        $response->assertStatus(200);

        $this->assertFalse(
            $conversation->participants()->where('user_id', $this->user->id)->exists()
        );
    }

    public function test_user_cannot_view_messages_after_leaving_conversation()
    {
        $conversation = Conversation::factory()->create();
        $conversation->participants()->attach([$this->user->id, $this->otherUser->id]);

        // Create a message while user is participant
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->user->id
        ]);

        // User leaves conversation
        $conversation->participants()->detach($this->user->id);

        Sanctum::actingAs($this->user);

        // Cannot access conversation messages after leaving
        $response = $this->getJson("/api/conversations/{$conversation->id}/messages");
        $response->assertStatus(403);
    }
}