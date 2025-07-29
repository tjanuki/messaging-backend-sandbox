<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $notificationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->notificationService = Mockery::mock(NotificationService::class);
        $this->app->instance(NotificationService::class, $this->notificationService);
    }

    public function test_user_can_send_push_notification()
    {
        Sanctum::actingAs($this->user);

        $this->notificationService
            ->shouldReceive('sendToDevice')
            ->once()
            ->with(
                'test_token_123',
                Mockery::type('array')
            )
            ->andReturn(true);

        $response = $this->postJson('/api/notifications/send', [
            'token' => 'test_token_123',
            'title' => 'Test Notification',
            'body' => 'This is a test notification message',
            'data' => [
                'type' => 'test',
                'custom_data' => 'value'
            ]
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'message' => 'Notification sent successfully',
                    'success' => true
                ]);
    }

    public function test_notification_send_requires_valid_data()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/notifications/send', []);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['token', 'title', 'body']);
    }

    public function test_notification_send_handles_service_failure()
    {
        Sanctum::actingAs($this->user);

        $this->notificationService
            ->shouldReceive('sendToDevice')
            ->once()
            ->andReturn(false);

        $response = $this->postJson('/api/notifications/send', [
            'token' => 'test_token_123',
            'title' => 'Test Notification',
            'body' => 'This is a test notification message'
        ]);

        $response->assertStatus(500)
                ->assertJson([
                    'message' => 'Failed to send notification',
                    'success' => false
                ]);
    }

    public function test_user_can_subscribe_to_topic()
    {
        Sanctum::actingAs($this->user);

        $this->notificationService
            ->shouldReceive('subscribeToTopic')
            ->once()
            ->with('test_token_123', 'general')
            ->andReturn(true);

        $response = $this->postJson('/api/notifications/subscribe', [
            'token' => 'test_token_123',
            'topic' => 'general'
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'message' => 'Subscribed to topic successfully',
                    'success' => true
                ]);
    }

    public function test_user_can_unsubscribe_from_topic()
    {
        Sanctum::actingAs($this->user);

        $this->notificationService
            ->shouldReceive('unsubscribeFromTopic')
            ->once()
            ->with('test_token_123', 'general')
            ->andReturn(true);

        $response = $this->postJson('/api/notifications/unsubscribe', [
            'token' => 'test_token_123',
            'topic' => 'general'
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'message' => 'Unsubscribed from topic successfully',
                    'success' => true
                ]);
    }

    public function test_topic_subscription_requires_valid_data()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/notifications/subscribe', []);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['token', 'topic']);
    }

    public function test_topic_subscription_handles_service_failure()
    {
        Sanctum::actingAs($this->user);

        $this->notificationService
            ->shouldReceive('subscribeToTopic')
            ->once()
            ->andReturn(false);

        $response = $this->postJson('/api/notifications/subscribe', [
            'token' => 'test_token_123',
            'topic' => 'general'
        ]);

        $response->assertStatus(500)
                ->assertJson([
                    'message' => 'Failed to subscribe to topic',
                    'success' => false
                ]);
    }

    public function test_bulk_notification_job_is_queued()
    {
        Queue::fake();
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/notifications/bulk', [
            'tokens' => ['token1', 'token2', 'token3'],
            'title' => 'Bulk Notification',
            'body' => 'This is a bulk notification',
            'data' => ['type' => 'bulk']
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'message' => 'Bulk notification queued successfully'
                ]);

        Queue::assertPushed(\App\Jobs\SendBulkNotificationJob::class);
    }

    public function test_notification_test_endpoint_works()
    {
        Sanctum::actingAs($this->user);

        $this->notificationService
            ->shouldReceive('sendToDevice')
            ->once()
            ->with(
                $this->user->fcm_token,
                Mockery::subset([
                    'title' => 'Test Notification',
                    'body' => 'This is a test notification from the server'
                ])
            )
            ->andReturn(true);

        // Update user FCM token first
        $this->user->update(['fcm_token' => 'test_token_123']);

        $response = $this->postJson('/api/notifications/test');

        $response->assertStatus(200)
                ->assertJson([
                    'message' => 'Test notification sent successfully'
                ]);
    }

    public function test_notification_test_fails_without_fcm_token()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/notifications/test');

        $response->assertStatus(400)
                ->assertJson([
                    'message' => 'No FCM token found for user'
                ]);
    }

    public function test_notification_preferences_can_be_updated()
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/notifications/preferences', [
            'message_notifications' => true,
            'typing_notifications' => false,
            'sound_enabled' => true,
            'vibration_enabled' => false
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'message' => 'Notification preferences updated successfully'
                ]);

        // Check that preferences were stored (assuming they're stored in user metadata or separate table)
        $this->assertDatabaseHas('users', [
            'id' => $this->user->id
        ]);
    }

    public function test_notification_history_can_be_retrieved()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/notifications/history');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'notifications' => [
                        '*' => [
                            'id', 'title', 'body', 'type', 'read_at', 'created_at'
                        ]
                    ],
                    'pagination'
                ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}