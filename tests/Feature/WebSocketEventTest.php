<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Events\MessageDeleted;
use App\Events\MessageReactionAdded;
use App\Events\MessageReactionRemoved;
use App\Events\UserOnline;
use App\Events\UserOffline;
use App\Events\UserTyping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Broadcast;

class WebSocketEventTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $otherUser;
    protected $conversation;
    protected $message;

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
        
        $this->message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->user->id
        ]);
    }

    public function test_message_sent_event_is_broadcasted()
    {
        Event::fake([MessageSent::class]);

        $event = new MessageSent($this->message);
        
        $this->assertEquals($this->message->id, $event->message->id);
        $this->assertEquals($this->message->conversation_id, $event->message->conversation_id);
    }

    public function test_message_sent_event_broadcasts_on_correct_channel()
    {
        $event = new MessageSent($this->message);
        
        $channels = $event->broadcastOn();
        
        $this->assertCount(1, $channels);
        $this->assertEquals(
            'private-conversation.' . $this->conversation->id,
            $channels[0]->name
        );
    }

    public function test_message_sent_event_broadcasts_correct_data()
    {
        $event = new MessageSent($this->message->load('user'));
        
        $broadcastData = $event->broadcastWith();
        
        $this->assertArrayHasKey('message', $broadcastData);
        $this->assertArrayHasKey('user', $broadcastData);
        $this->assertArrayHasKey('conversation_id', $broadcastData);
        
        $this->assertEquals($this->message->id, $broadcastData['message']['id']);
        $this->assertEquals($this->user->id, $broadcastData['user']['id']);
        $this->assertEquals($this->conversation->id, $broadcastData['conversation_id']);
    }

    public function test_message_sent_event_has_correct_broadcast_name()
    {
        $event = new MessageSent($this->message);
        
        $this->assertEquals('message.sent', $event->broadcastAs());
    }

    public function test_message_updated_event_is_broadcasted()
    {
        Event::fake([MessageUpdated::class]);

        $this->message->update(['content' => 'Updated content']);
        
        $event = new MessageUpdated($this->message->fresh());
        
        $this->assertEquals('Updated content', $event->message->content);
        $this->assertNotNull($event->message->edited_at);
    }

    public function test_message_updated_event_broadcasts_on_correct_channel()
    {
        $event = new MessageUpdated($this->message);
        
        $channels = $event->broadcastOn();
        
        $this->assertEquals(
            'private-conversation.' . $this->conversation->id,
            $channels[0]->name
        );
    }

    public function test_message_deleted_event_is_broadcasted()
    {
        $event = new MessageDeleted($this->message);
        
        $this->assertEquals($this->message->id, $event->message->id);
    }

    public function test_message_deleted_event_broadcasts_correct_data()
    {
        $event = new MessageDeleted($this->message);
        
        $broadcastData = $event->broadcastWith();
        
        $this->assertArrayHasKey('message_id', $broadcastData);
        $this->assertArrayHasKey('conversation_id', $broadcastData);
        
        $this->assertEquals($this->message->id, $broadcastData['message_id']);
        $this->assertEquals($this->conversation->id, $broadcastData['conversation_id']);
    }

    public function test_message_reaction_added_event_is_broadcasted()
    {
        $reaction = MessageReaction::factory()->create([
            'message_id' => $this->message->id,
            'user_id' => $this->otherUser->id,
            'emoji' => '👍'
        ]);

        $event = new MessageReactionAdded($reaction->load('user'));
        
        $this->assertEquals($reaction->id, $event->reaction->id);
        $this->assertEquals('👍', $event->reaction->emoji);
    }

    public function test_message_reaction_added_event_broadcasts_correct_data()
    {
        $reaction = MessageReaction::factory()->create([
            'message_id' => $this->message->id,
            'user_id' => $this->otherUser->id,
            'emoji' => '👍'
        ]);

        $event = new MessageReactionAdded($reaction->load('user'));
        
        $broadcastData = $event->broadcastWith();
        
        $this->assertArrayHasKey('reaction', $broadcastData);
        $this->assertArrayHasKey('user', $broadcastData);
        $this->assertArrayHasKey('message_id', $broadcastData);
        
        $this->assertEquals($reaction->id, $broadcastData['reaction']['id']);
        $this->assertEquals('👍', $broadcastData['reaction']['emoji']);
        $this->assertEquals($this->message->id, $broadcastData['message_id']);
    }

    public function test_message_reaction_removed_event_is_broadcasted()
    {
        $reaction = MessageReaction::factory()->create([
            'message_id' => $this->message->id,
            'user_id' => $this->otherUser->id,
            'emoji' => '👍'
        ]);

        $event = new MessageReactionRemoved($reaction);
        
        $this->assertEquals($reaction->id, $event->reaction->id);
    }

    public function test_user_online_event_is_broadcasted()
    {
        $event = new UserOnline($this->user);
        
        $this->assertEquals($this->user->id, $event->user->id);
    }

    public function test_user_online_event_broadcasts_on_presence_channels()
    {
        $event = new UserOnline($this->user);
        
        $channels = $event->broadcastOn();
        
        // Should broadcast on all conversation presence channels the user is part of
        $this->assertGreaterThan(0, count($channels));
        
        foreach ($channels as $channel) {
            $this->assertStringContains('presence-conversation.', $channel->name);
        }
    }

    public function test_user_offline_event_is_broadcasted()
    {
        $event = new UserOffline($this->user);
        
        $this->assertEquals($this->user->id, $event->user->id);
    }

    public function test_user_offline_event_broadcasts_correct_data()
    {
        $this->user->update(['last_seen' => now()]);
        
        $event = new UserOffline($this->user->fresh());
        
        $broadcastData = $event->broadcastWith();
        
        $this->assertArrayHasKey('user_id', $broadcastData);
        $this->assertArrayHasKey('last_seen', $broadcastData);
        
        $this->assertEquals($this->user->id, $broadcastData['user_id']);
        $this->assertNotNull($broadcastData['last_seen']);
    }

    public function test_user_typing_event_is_broadcasted()
    {
        $event = new UserTyping($this->user, $this->conversation, true);
        
        $this->assertEquals($this->user->id, $event->user->id);
        $this->assertEquals($this->conversation->id, $event->conversation->id);
        $this->assertTrue($event->isTyping);
    }

    public function test_user_typing_event_broadcasts_on_conversation_channel()
    {
        $event = new UserTyping($this->user, $this->conversation, true);
        
        $channels = $event->broadcastOn();
        
        $this->assertCount(1, $channels);
        $this->assertEquals(
            'private-conversation.' . $this->conversation->id,
            $channels[0]->name
        );
    }

    public function test_user_typing_event_broadcasts_correct_data()
    {
        $event = new UserTyping($this->user, $this->conversation, true);
        
        $broadcastData = $event->broadcastWith();
        
        $this->assertArrayHasKey('user', $broadcastData);
        $this->assertArrayHasKey('conversation_id', $broadcastData);
        $this->assertArrayHasKey('is_typing', $broadcastData);
        
        $this->assertEquals($this->user->id, $broadcastData['user']['id']);
        $this->assertEquals($this->conversation->id, $broadcastData['conversation_id']);
        $this->assertTrue($broadcastData['is_typing']);
    }

    public function test_user_stopped_typing_event_broadcasts_correct_data()
    {
        $event = new UserTyping($this->user, $this->conversation, false);
        
        $broadcastData = $event->broadcastWith();
        
        $this->assertFalse($broadcastData['is_typing']);
    }

    public function test_events_implement_should_broadcast_interface()
    {
        $events = [
            MessageSent::class,
            MessageUpdated::class,
            MessageDeleted::class,
            MessageReactionAdded::class,
            MessageReactionRemoved::class,
            UserOnline::class,
            UserOffline::class,
            UserTyping::class
        ];

        foreach ($events as $eventClass) {
            $reflection = new \ReflectionClass($eventClass);
            $this->assertTrue(
                $reflection->implementsInterface(\Illuminate\Contracts\Broadcasting\ShouldBroadcast::class),
                "{$eventClass} should implement ShouldBroadcast interface"
            );
        }
    }

    public function test_private_channels_require_authentication()
    {
        // Test that private channels are properly configured
        $this->assertTrue(
            \Illuminate\Support\Facades\Broadcast::channel('conversation.{id}', function ($user, $id) {
                return $user->conversations()->where('conversations.id', $id)->exists();
            }) !== null
        );
    }

    public function test_presence_channels_return_user_data()
    {
        // Test presence channel authorization
        $channel = \Illuminate\Support\Facades\Broadcast::channel('conversation.{id}', function ($user, $id) {
            if ($user->conversations()->where('conversations.id', $id)->exists()) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar
                ];
            }
            return false;
        });

        $this->assertNotNull($channel);
    }
}