<?php

use App\Models\User;
use App\Models\UserSession;
use App\Http\Middleware\ForceUpdateMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([ForceUpdateMiddleware::class]);
});

test('login generates access_token, refresh_token and active UserSession', function () {
    $user = User::factory()->create([
        'email' => 'testuser@example.com',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/v1/login-email', [
        'email' => 'testuser@example.com',
        'password' => 'password123',
    ], [
        'X-Device-Platform' => 'ios',
        'X-Device-Name' => 'iPhone 15 Pro',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('status', true)
        ->assertJsonStructure(['status', 'access_token', 'refresh_token', 'session_id', 'user']);

    $refreshToken = $response->json('refresh_token');

    $this->assertDatabaseHas('user_sessions', [
        'user_id' => $user->id,
        'refresh_token' => $refreshToken,
        'device_platform' => 'ios',
        'device_name' => 'iPhone 15 Pro',
        'is_active' => true,
    ]);
});

test('refresh token endpoint rotates tokens successfully', function () {
    $user = User::factory()->create([
        'email' => 'refreshtest@example.com',
        'password' => bcrypt('password123'),
    ]);

    $loginResponse = $this->postJson('/api/v1/login-email', [
        'email' => 'refreshtest@example.com',
        'password' => 'password123',
    ]);

    $oldRefreshToken = $loginResponse->json('refresh_token');

    $refreshResponse = $this->postJson('/api/v1/refresh-token', [
        'refresh_token' => $oldRefreshToken,
    ]);

    $refreshResponse->assertStatus(200)
        ->assertJsonPath('status', true)
        ->assertJsonStructure(['access_token', 'refresh_token', 'session_id']);

    $newRefreshToken = $refreshResponse->json('refresh_token');
    expect($newRefreshToken)->not->toBe($oldRefreshToken);

    // Assert old session was rotated (is_active = false, revoked_at set)
    $oldSession = UserSession::where('refresh_token', $oldRefreshToken)->first();
    expect($oldSession->is_active)->toBeFalse();
    expect($oldSession->revoked_at)->not->toBeNull();

    // Assert new session is active
    $newSession = UserSession::where('refresh_token', $newRefreshToken)->first();
    expect($newSession->is_active)->toBeTrue();
});

test('refresh token reuse attempt triggers security revocation of user sessions', function () {
    $user = User::factory()->create();

    $session = UserSession::create([
        'user_id' => $user->id,
        'refresh_token' => 'rotated_token_123',
        'is_active' => false,
        'revoked_at' => now(),
        'expires_at' => now()->addDays(30),
    ]);

    $activeSession = UserSession::create([
        'user_id' => $user->id,
        'refresh_token' => 'active_token_456',
        'is_active' => true,
        'expires_at' => now()->addDays(30),
    ]);

    $response = $this->postJson('/api/v1/refresh-token', [
        'refresh_token' => 'rotated_token_123',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('status', false)
        ->assertJsonPath('msg', 'Session has been revoked due to suspicious activity');

    // Assert the active session was revoked as security safeguard
    expect($activeSession->fresh()->is_active)->toBeFalse();
});

test('expired refresh token is rejected', function () {
    $user = User::factory()->create();

    UserSession::create([
        'user_id' => $user->id,
        'refresh_token' => 'expired_token_123',
        'is_active' => true,
        'expires_at' => now()->subDay(),
    ]);

    $response = $this->postJson('/api/v1/refresh-token', [
        'refresh_token' => 'expired_token_123',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('status', false)
        ->assertJsonPath('msg', 'Session has expired');
});

test('logout deactivates session and revokes access token', function () {
    $user = User::factory()->create([
        'email' => 'logouttest@example.com',
        'password' => bcrypt('password123'),
    ]);

    $loginResponse = $this->postJson('/api/v1/login-email', [
        'email' => 'logouttest@example.com',
        'password' => 'password123',
    ]);

    $accessToken = $loginResponse->json('access_token');
    $refreshToken = $loginResponse->json('refresh_token');

    $logoutResponse = $this->withHeader('Authorization', "Bearer {$accessToken}")
        ->postJson('/api/v1/logout', [
            'refresh_token' => $refreshToken,
        ]);

    $logoutResponse->assertStatus(200)
        ->assertJsonPath('status', true)
        ->assertJsonPath('msg', 'Successfully logged out');

    // Assert session is deactivated
    $session = UserSession::where('refresh_token', $refreshToken)->first();
    expect($session->is_active)->toBeFalse();
    expect($session->revoked_at)->not->toBeNull();

    // Assert Sanctum token was deleted from DB
    $tokenId = explode('|', $accessToken)[0];
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

    // Reset auth state in test application container to force guard re-evaluation
    app('auth')->forgetGuards();

    // Assert request with revoked access token fails with 401
    $meResponse = $this->withHeader('Authorization', "Bearer {$accessToken}")
        ->getJson('/api/v1/me');
    $meResponse->assertStatus(401);
});

test('logout-all revokes all sessions and all personal access tokens', function () {
    $user = User::factory()->create([
        'email' => 'logoutall@example.com',
        'password' => bcrypt('password123'),
    ]);

    $token1 = $user->createToken('token1')->plainTextToken;
    $token2 = $user->createToken('token2')->plainTextToken;

    UserSession::create([
        'user_id' => $user->id,
        'refresh_token' => 'session_token_1',
        'is_active' => true,
        'expires_at' => now()->addDays(30),
    ]);

    UserSession::create([
        'user_id' => $user->id,
        'refresh_token' => 'session_token_2',
        'is_active' => true,
        'expires_at' => now()->addDays(30),
    ]);

    $logoutAllResponse = $this->withHeader('Authorization', "Bearer {$token1}")
        ->postJson('/api/v1/logout-all');

    $logoutAllResponse->assertStatus(200)
        ->assertJsonPath('status', true)
        ->assertJsonPath('msg', 'Successfully logged out from all devices');

    // Assert all sessions are inactive
    $activeCount = UserSession::where('user_id', $user->id)->where('is_active', true)->count();
    expect($activeCount)->toBe(0);

    // Assert all tokens are revoked
    expect($user->tokens()->count())->toBe(0);
});

test('authenticated user can list active sessions and revoke a specific session', function () {
    $user = User::factory()->create();

    $session1 = UserSession::create([
        'user_id' => $user->id,
        'refresh_token' => 'session_1',
        'device_name' => 'Chrome MacOS',
        'is_active' => true,
        'expires_at' => now()->addDays(30),
    ]);

    $session2 = UserSession::create([
        'user_id' => $user->id,
        'refresh_token' => 'session_2',
        'device_name' => 'Android Phone',
        'is_active' => true,
        'expires_at' => now()->addDays(30),
    ]);

    Sanctum::actingAs($user);

    // List sessions
    $sessionsResponse = $this->getJson('/api/v1/sessions');
    $sessionsResponse->assertStatus(200)
        ->assertJsonPath('status', true)
        ->assertJsonCount(2, 'sessions');

    // Revoke session1
    $revokeResponse = $this->deleteJson("/api/v1/sessions/{$session1->id}/revoke");
    $revokeResponse->assertStatus(200)
        ->assertJsonPath('status', true)
        ->assertJsonPath('msg', 'Session revoked successfully');

    expect($session1->fresh()->is_active)->toBeFalse();

    // Verify list only has 1 active session remaining
    $sessionsResponse2 = $this->getJson('/api/v1/sessions');
    $sessionsResponse2->assertStatus(200)
        ->assertJsonCount(1, 'sessions');
});
