<?php

use App\Models\User;
use App\Models\Maintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('public endpoint - fetch state', function () {
    $response = $this->getJson('/api/v1/maintenance/state');
    $response->assertStatus(200)
             ->assertJsonStructure([
                 'success',
                 'message',
                 'data'
             ]);
});

test('public endpoint - fetch schedule', function () {
    $response = $this->getJson('/api/v1/maintenance/schedule');
    $response->assertStatus(200)
             ->assertJsonStructure([
                 'success',
                 'message',
                 'data' => [
                     'schedule',
                     'next'
                 ]
             ]);
});

test('public endpoint - fetch history', function () {
    $response = $this->getJson('/api/v1/maintenance/history');
    $response->assertStatus(200)
             ->assertJsonStructure([
                 'success',
                 'message',
                 'data'
             ]);
});

test('unauthenticated user cannot access protected endpoints', function () {
    $this->postJson('/api/v1/maintenance/create', [])->assertStatus(401);
    $this->putJson('/api/v1/maintenance/update/some-id', [])->assertStatus(401);
    $this->deleteJson('/api/v1/maintenance/delete/some-id')->assertStatus(401);
    $this->getJson('/api/v1/maintenance/all')->assertStatus(401);
});

test('authenticated user can manage maintenance events', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // 1. Create Maintenance
    $startTime = now()->addDays(1)->toIso8601String();
    $endTime = now()->addDays(1)->addHours(2)->toIso8601String();
    
    $payload = [
        'title' => 'Scheduled Upgrade',
        'description' => 'Upgrading database server',
        'startTime' => $startTime,
        'endTime' => $endTime,
        'priority' => 'high',
        'app_type' => 'planeventz',
        'scope' => 'global',
        'is_emergency' => false,
        'notify' => true,
        'notifyBeforeMinutes' => 15,
        'gracePeriodMinutes' => 5,
        'allowWhitelist' => true,
        'affectedServices' => ['auth', 'api']
    ];

    $response = $this->postJson('/api/v1/maintenance/create', $payload);
    $response->assertStatus(201)
             ->assertJsonPath('success', true)
             ->assertJsonPath('data.title', 'Scheduled Upgrade');

    $maintenanceId = $response->json('data.id');
    expect($maintenanceId)->not->toBeNull();

    // 2. Fetch All
    $fetchAllResponse = $this->getJson('/api/v1/maintenance/all');
    $fetchAllResponse->assertStatus(200)
                     ->assertJsonPath('success', true);
    
    // 3. Update Maintenance
    $updatePayload = [
        'title' => 'Updated Scheduled Upgrade',
        'priority' => 'critical'
    ];
    $updateResponse = $this->putJson("/api/v1/maintenance/update/{$maintenanceId}", $updatePayload);
    $updateResponse->assertStatus(200)
                   ->assertJsonPath('success', true)
                   ->assertJsonPath('data.title', 'Updated Scheduled Upgrade')
                   ->assertJsonPath('data.priority', 'critical');

    // 4. Check that state and schedule reflect the new upcoming event
    $scheduleResponse = $this->getJson('/api/v1/maintenance/schedule');
    $scheduleResponse->assertStatus(200)
                     ->assertJsonPath('data.next.id', $maintenanceId);

    // 5. Delete (soft delete)
    $deleteResponse = $this->deleteJson("/api/v1/maintenance/delete/{$maintenanceId}");
    $deleteResponse->assertStatus(200)
                   ->assertJsonPath('success', true);

    // Verify it is excluded from all and schedule
    $fetchAllResponse2 = $this->getJson('/api/v1/maintenance/all');
    $ids = collect($fetchAllResponse2->json('data'))->pluck('id');
    expect($ids->contains($maintenanceId))->toBeFalse();
});
