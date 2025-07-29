<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ConversationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
    }

    public function test_user_can_get_their_conversations()
    {
        Sanctum::actingAs($this->user);

        // Create a conversation with the user as participant
        $conversation = Conversation::factory()->create();
        $conversation->participants()->attach($this->user->id);

        $response = $this->getJson('/api/conversations');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    '*' => [
                        'id', 'name', 'type', 'created_by', 'created_at',
                        'participants' => ['*' => ['id', 'name', 'email']],
                        'last_message'
                    ]
                ]);
    }

    public function test_user_can_create_direct_conversation()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/conversations', [
            'type' => 'direct',
            'participant_ids' => [$this->otherUser->id]
        ]);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'id', 'name', 'type', 'created_by', 'created_at',
                    'participants' => ['*' => ['id', 'name', 'email']]
                ]);

        $this->assertDatabaseHas('conversations', [
            'type' => 'direct',
            'created_by' => $this->user->id
        ]);
    }

    public function test_user_can_create_group_conversation()
    {
        Sanctum::actingAs($this->user);

        $user3 = User::factory()->create();

        $response = $this->postJson('/api/conversations', [
            'name' => 'Test Group',
            'type' => 'group',
            'participant_ids' => [$this->otherUser->id, $user3->id]
        ]);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'id', 'name', 'type', 'created_by', 'created_at',
                    'participants' => ['*' => ['id', 'name', 'email']]
                ]);

        $this->assertDatabaseHas('conversations', [
            'name' => 'Test Group',
            'type' => 'group',
            'created_by' => $this->user->id
        ]);
    }

    public function test_user_can_get_conversation_details()
    {
        Sanctum::actingAs($this->user);

        $conversation = Conversation::factory()->create();
        $conversation->participants()->attach([
            $this->user->id,
            $this->otherUser->id
        ]);

        $response = $this->getJson("/api/conversations/{$conversation->id}");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'id', 'name', 'type', 'created_by', 'created_at',
                    'participants' => ['*' => ['id', 'name', 'email']],
                    'messages' => ['*' => ['id', 'content', 'user_id', 'created_at']]
                ]);
    }

    public function test_user_cannot_access_conversation_they_are_not_part_of()
    {
        Sanctum::actingAs($this->user);

        $conversation = Conversation::factory()->create();
        $conversation->participants()->attach($this->otherUser->id);

        $response = $this->getJson("/api/conversations/{$conversation->id}");

        $response->assertStatus(403);
    }

    public function test_user_can_update_group_conversation_name()
    {
        Sanctum::actingAs($this->user);

        $conversation = Conversation::factory()->create([
            'type' => 'group',
            'name' => 'Old Name',
            'created_by' => $this->user->id
        ]);
        $conversation->participants()->attach($this->user->id, ['is_admin' => true]);

        $response = $this->putJson("/api/conversations/{$conversation->id}", [
            'name' => 'New Group Name'
        ]);

        $response->assertStatus(200)
                ->assertJson(['name' => 'New Group Name']);

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'name' => 'New Group Name'
        ]);
    }

    public function test_non_admin_cannot_update_group_conversation()
    {
        Sanctum::actingAs($this->user);

        $conversation = Conversation::factory()->create([
            'type' => 'group',
            'created_by' => $this->otherUser->id
        ]);
        $conversation->participants()->attach($this->user->id, ['is_admin' => false]);

        $response = $this->putJson("/api/conversations/{$conversation->id}", [
            'name' => 'New Group Name'
        ]);

        $response->assertStatus(403);
    }

    public function test_user_can_add_participant_to_group()
    {
        Sanctum::actingAs($this->user);

        $conversation = Conversation::factory()->create([
            'type' => 'group',
            'created_by' => $this->user->id
        ]);
        $conversation->participants()->attach($this->user->id, ['is_admin' => true]);

        $newUser = User::factory()->create();

        $response = $this->postJson("/api/conversations/{$conversation->id}/participants", [
            'user_id' => $newUser->id
        ]);

        $response->assertStatus(200)
                ->assertJson(['message' => 'Participant added successfully']);

        $this->assertTrue($conversation->participants()->where('user_id', $newUser->id)->exists());
    }

    public function test_user_can_remove_participant_from_group()
    {
        Sanctum::actingAs($this->user);

        $conversation = Conversation::factory()->create([
            'type' => 'group',
            'created_by' => $this->user->id
        ]);
        $conversation->participants()->attach([
            $this->user->id => ['is_admin' => true],
            $this->otherUser->id => ['is_admin' => false]
        ]);

        $response = $this->deleteJson("/api/conversations/{$conversation->id}/participants/{$this->otherUser->id}");

        $response->assertStatus(200)
                ->assertJson(['message' => 'Participant removed successfully']);

        $this->assertFalse($conversation->participants()->where('user_id', $this->otherUser->id)->exists());
    }

    public function test_user_can_delete_conversation_they_created()
    {
        Sanctum::actingAs($this->user);

        $conversation = Conversation::factory()->create([
            'created_by' => $this->user->id
        ]);
        $conversation->participants()->attach($this->user->id);

        $response = $this->deleteJson("/api/conversations/{$conversation->id}");

        $response->assertStatus(204);

        $this->assertSoftDeleted('conversations', ['id' => $conversation->id]);
    }

    public function test_user_cannot_delete_conversation_they_did_not_create()
    {
        Sanctum::actingAs($this->user);

        $conversation = Conversation::factory()->create([
            'created_by' => $this->otherUser->id
        ]);
        $conversation->participants()->attach($this->user->id);

        $response = $this->deleteJson("/api/conversations/{$conversation->id}");

        $response->assertStatus(403);
    }
}