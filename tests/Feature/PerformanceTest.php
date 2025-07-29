<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\MessageService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected $users;
    protected $conversations;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test data for performance testing
        $this->users = User::factory()->count(100)->create();
        $this->conversations = Conversation::factory()->count(20)->create();
        
        // Attach users to conversations
        foreach ($this->conversations as $conversation) {
            $randomUsers = $this->users->random(rand(5, 15));
            $conversation->participants()->attach($randomUsers->pluck('id'));
        }
    }

    public function test_message_retrieval_performance_with_large_dataset()
    {
        $conversation = $this->conversations->first();
        $user = $conversation->participants->first();
        
        // Create large number of messages
        Message::factory()->count(5000)->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id
        ]);

        Sanctum::actingAs($user);

        $startTime = microtime(true);
        
        $response = $this->getJson("/api/conversations/{$conversation->id}/messages?limit=50");
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        $response->assertStatus(200);
        
        // Should retrieve messages within acceptable time (< 500ms for 5000 messages)
        $this->assertLessThan(500, $executionTime, 
            "Message retrieval took {$executionTime}ms, should be under 500ms");
        
        // Should return exactly 50 messages
        $this->assertCount(50, $response->json('messages'));
    }

    public function test_concurrent_message_sending_performance()
    {
        $conversation = $this->conversations->first();
        $users = $conversation->participants->take(10);
        
        Event::fake(); // Prevent real broadcasting for performance testing
        
        $startTime = microtime(true);
        
        // Simulate 10 users sending messages simultaneously
        foreach ($users as $user) {
            Sanctum::actingAs($user);
            
            $this->postJson("/api/conversations/{$conversation->id}/messages", [
                'content' => "Concurrent message from user {$user->id}",
                'type' => 'text'
            ])->assertStatus(201);
        }
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;

        // Should handle 10 concurrent messages within 2 seconds
        $this->assertLessThan(2000, $executionTime, 
            "Concurrent message sending took {$executionTime}ms, should be under 2000ms");
        
        // Verify all messages were saved
        $this->assertEquals(10, 
            Message::where('conversation_id', $conversation->id)->count());
    }

    public function test_conversation_list_performance_with_many_conversations()
    {
        $user = $this->users->first();
        
        // Add user to many conversations
        foreach ($this->conversations as $conversation) {
            $conversation->participants()->attach($user->id);
            
            // Add some messages to each conversation
            Message::factory()->count(rand(1, 5))->create([
                'conversation_id' => $conversation->id,
                'user_id' => $this->users->random()->id
            ]);
        }

        Sanctum::actingAs($user);

        $startTime = microtime(true);
        
        $response = $this->getJson('/api/conversations');
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;

        $response->assertStatus(200);
        
        // Should load conversation list within acceptable time
        $this->assertLessThan(1000, $executionTime, 
            "Conversation list loading took {$executionTime}ms, should be under 1000ms");
        
        // Should return all conversations for the user
        $this->assertCount(20, $response->json());
    }

    public function test_database_query_optimization()
    {
        $conversation = $this->conversations->first();
        $user = $conversation->participants->first();
        
        // Create messages with reactions
        $messages = Message::factory()->count(100)->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id
        ]);

        foreach ($messages->take(50) as $message) {
            $message->reactions()->createMany([
                ['user_id' => $this->users->random()->id, 'emoji' => '👍'],
                ['user_id' => $this->users->random()->id, 'emoji' => '❤️'],
            ]);
        }

        Sanctum::actingAs($user);

        // Enable query logging
        DB::enableQueryLog();
        
        $response = $this->getJson("/api/conversations/{$conversation->id}/messages?limit=20");
        
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200);
        
        // Should use efficient queries (not too many N+1 queries)
        $this->assertLessThan(10, count($queries), 
            "Too many database queries (" . count($queries) . "), possible N+1 problem");
    }

    public function test_memory_usage_with_large_datasets()
    {
        $conversation = $this->conversations->first();
        $user = $conversation->participants->first();
        
        // Create large dataset
        Message::factory()->count(1000)->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id
        ]);

        Sanctum::actingAs($user);

        $memoryBefore = memory_get_usage(true);
        
        $response = $this->getJson("/api/conversations/{$conversation->id}/messages?limit=100");
        
        $memoryAfter = memory_get_usage(true);
        $memoryUsed = ($memoryAfter - $memoryBefore) / 1024 / 1024; // Convert to MB

        $response->assertStatus(200);
        
        // Should not use excessive memory (< 50MB for 100 messages)
        $this->assertLessThan(50, $memoryUsed, 
            "Memory usage was {$memoryUsed}MB, should be under 50MB");
    }

    public function test_cache_performance_improvement()
    {
        $conversation = $this->conversations->first();
        $user = $conversation->participants->first();
        
        Sanctum::actingAs($user);

        // First request (no cache)
        $startTime = microtime(true);
        $response1 = $this->getJson("/api/conversations/{$conversation->id}");
        $endTime = microtime(true);
        $timeWithoutCache = ($endTime - $startTime) * 1000;

        $response1->assertStatus(200);

        // Second request (with cache if implemented)
        $startTime = microtime(true);
        $response2 = $this->getJson("/api/conversations/{$conversation->id}");
        $endTime = microtime(true);
        $timeWithCache = ($endTime - $startTime) * 1000;

        $response2->assertStatus(200);

        // Cached request should be faster or at least not significantly slower
        $performanceImprovement = $timeWithoutCache - $timeWithCache;
        $this->assertGreaterThanOrEqual(-50, $performanceImprovement, 
            "Cached request should not be significantly slower");
    }

    public function test_typing_indicator_cleanup_performance()
    {
        // Create many expired typing indicators
        $conversation = $this->conversations->first();
        
        foreach ($this->users->take(50) as $user) {
            $conversation->typingIndicators()->create([
                'user_id' => $user->id,
                'is_typing' => true,
                'expires_at' => now()->subMinutes(5) // Expired
            ]);
        }

        $startTime = microtime(true);
        
        // Run cleanup command
        $this->artisan('typing:cleanup');
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;

        // Cleanup should be fast even with many records
        $this->assertLessThan(1000, $executionTime, 
            "Typing indicator cleanup took {$executionTime}ms, should be under 1000ms");
        
        // All expired indicators should be cleaned up
        $this->assertEquals(0, 
            $conversation->typingIndicators()
                        ->where('expires_at', '<', now())
                        ->count());
    }

    public function test_bulk_notification_processing_performance()
    {
        $users = $this->users->take(100);
        $tokens = $users->pluck('fcm_token')->filter()->toArray();
        
        if (empty($tokens)) {
            // Add FCM tokens to users
            foreach ($users as $user) {
                $user->update(['fcm_token' => 'token_' . $user->id]);
            }
            $tokens = $users->pluck('fcm_token')->toArray();
        }

        $notificationData = [
            'title' => 'Performance Test',
            'body' => 'Testing bulk notification performance',
            'data' => ['type' => 'test']
        ];

        $startTime = microtime(true);
        
        // Process bulk notifications
        $job = new \App\Jobs\SendBulkNotificationJob($tokens, $notificationData);
        $job->handle(app(\App\Services\NotificationService::class));
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;

        // Should process 100 notifications within reasonable time
        $this->assertLessThan(5000, $executionTime, 
            "Bulk notification processing took {$executionTime}ms, should be under 5000ms");
    }

    public function test_message_search_performance()
    {
        $conversation = $this->conversations->first();
        $user = $conversation->participants->first();
        
        // Create messages with searchable content
        $searchTerms = ['hello', 'world', 'test', 'performance', 'search'];
        
        for ($i = 0; $i < 1000; $i++) {
            Message::factory()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'content' => 'Message ' . $i . ' contains ' . $searchTerms[array_rand($searchTerms)]
            ]);
        }

        Sanctum::actingAs($user);

        $startTime = microtime(true);
        
        $response = $this->getJson("/api/conversations/{$conversation->id}/messages/search?q=hello");
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;

        // Search should be reasonably fast even with 1000 messages
        $this->assertLessThan(1000, $executionTime, 
            "Message search took {$executionTime}ms, should be under 1000ms");
    }

    public function test_user_status_update_performance()
    {
        $usersToUpdate = $this->users->take(50);
        
        $startTime = microtime(true);
        
        // Update status for many users simultaneously
        foreach ($usersToUpdate as $user) {
            Sanctum::actingAs($user);
            
            $response = $this->postJson('/api/user/status', [
                'is_online' => true
            ]);
            
            $response->assertStatus(200);
        }
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;

        // Should handle multiple status updates efficiently
        $this->assertLessThan(3000, $executionTime, 
            "User status updates took {$executionTime}ms, should be under 3000ms");
        
        // Verify all users were updated
        $onlineCount = User::whereIn('id', $usersToUpdate->pluck('id'))
                          ->where('is_online', true)
                          ->count();
        
        $this->assertEquals(50, $onlineCount);
    }

    public function test_conversation_participant_management_performance()
    {
        $conversation = $this->conversations->first();
        $admin = $conversation->participants->first();
        $conversation->participants()->updateExistingPivot($admin->id, ['is_admin' => true]);
        
        $usersToAdd = $this->users->whereNotIn('id', 
            $conversation->participants->pluck('id'))->take(20);

        Sanctum::actingAs($admin);

        $startTime = microtime(true);
        
        // Add multiple participants
        foreach ($usersToAdd as $user) {
            $response = $this->postJson("/api/conversations/{$conversation->id}/participants", [
                'user_id' => $user->id
            ]);
            
            $response->assertStatus(200);
        }
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;

        // Should handle participant additions efficiently
        $this->assertLessThan(2000, $executionTime, 
            "Participant management took {$executionTime}ms, should be under 2000ms");
        
        // Verify all participants were added
        $participantCount = $conversation->participants()->count();
        $this->assertGreaterThanOrEqual(20, $participantCount);
    }

    public function test_api_rate_limiting_performance()
    {
        $user = $this->users->first();
        Sanctum::actingAs($user);

        $startTime = microtime(true);
        $successfulRequests = 0;
        
        // Make many requests quickly to test rate limiting
        for ($i = 0; $i < 100; $i++) {
            $response = $this->getJson('/api/me');
            
            if ($response->getStatusCode() === 200) {
                $successfulRequests++;
            }
        }
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;

        // Rate limiting should not significantly impact performance
        $this->assertLessThan(5000, $executionTime, 
            "Rate limiting check took {$executionTime}ms, should be under 5000ms");
        
        // Some requests should succeed (at least the first few before rate limiting kicks in)
        $this->assertGreaterThan(0, $successfulRequests);
    }

    protected function tearDown(): void
    {
        // Clean up large datasets to prevent memory issues
        DB::table('messages')->truncate();
        DB::table('message_reactions')->truncate();
        DB::table('typing_indicators')->truncate();
        
        parent::tearDown();
    }
}