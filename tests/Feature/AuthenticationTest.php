<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_requires_authentication_for_protected_routes()
    {
        $protectedRoutes = [
            ['GET', '/api/me'],
            ['PUT', '/api/me'],
            ['POST', '/api/logout'],
            ['POST', '/api/fcm-token'],
            ['GET', '/api/conversations'],
            ['POST', '/api/conversations'],
            ['POST', '/api/notifications/send'],
            ['POST', '/api/user/status']
        ];

        foreach ($protectedRoutes as [$method, $route]) {
            $response = $this->json($method, $route);
            
            $this->assertEquals(401, $response->getStatusCode(), 
                "Route {$method} {$route} should require authentication");
        }
    }

    public function test_invalid_token_returns_401()
    {
        $response = $this->withHeader('Authorization', 'Bearer invalid-token')
                        ->getJson('/api/me');

        $response->assertStatus(401);
    }

    public function test_expired_token_returns_401()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');
        
        // Manually expire the token
        $token->accessToken->update(['expires_at' => now()->subDay()]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
                        ->getJson('/api/me');

        $response->assertStatus(401);
    }

    public function test_valid_token_allows_access()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/me');

        $response->assertStatus(200)
                ->assertJson([
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email
                ]);
    }

    public function test_token_is_created_on_successful_login()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123')
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123'
        ]);

        $response->assertStatus(200)
                ->assertJsonStructure(['token', 'user']);

        // Verify token exists in database
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class
        ]);
    }

    public function test_token_is_deleted_on_logout()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
                        ->postJson('/api/logout');

        $response->assertStatus(200);

        // Verify token is deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id
        ]);
    }

    public function test_multiple_tokens_can_exist_for_user()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123')
        ]);

        // Login multiple times to create multiple tokens
        $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123'
        ]);

        $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123'
        ]);

        // Verify multiple tokens exist
        $this->assertEquals(2, $user->tokens()->count());
    }

    public function test_token_abilities_are_set_correctly()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['*']);

        $this->assertTrue($token->accessToken->can('*'));
    }

    public function test_rate_limiting_is_applied_to_login_attempts()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123')
        ]);

        // Make multiple failed login attempts
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/login', [
                'email' => 'test@example.com',
                'password' => 'wrongpassword'
            ]);
        }

        // The next attempt should be rate limited
        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword'
        ]);

        $response->assertStatus(429); // Too Many Requests
    }

    public function test_csrf_protection_is_disabled_for_api_routes()
    {
        // API routes should not require CSRF tokens
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ]);

        // Should not fail due to CSRF token missing
        $this->assertNotEquals(419, $response->getStatusCode());
    }

    public function test_cors_headers_are_present()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ]);

        $response->assertHeader('Access-Control-Allow-Origin');
    }

    public function test_user_online_status_is_updated_on_login()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'is_online' => false
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123'
        ]);

        $response->assertStatus(200);

        $this->assertTrue($user->fresh()->is_online);
        $this->assertNotNull($user->fresh()->last_seen);
    }

    public function test_user_online_status_is_updated_on_logout()
    {
        $user = User::factory()->create(['is_online' => true]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/logout');

        $response->assertStatus(200);

        $this->assertFalse($user->fresh()->is_online);
        $this->assertNotNull($user->fresh()->last_seen);
    }

    public function test_password_must_meet_requirements()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => '123', // Too short
            'password_confirmation' => '123'
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['password']);
    }

    public function test_email_must_be_valid_format()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'invalid-email',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_creates_user_with_hashed_password()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertNotEquals('password123', $user->password);
    }
}