<?php

use App\Models\ConferenceRoom;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Conference-room registry — PLAN_conference-room.md §3
|--------------------------------------------------------------------------
|
| CRUD + RBAC: `index` open to any authenticated user, mutations gated by
| `conference-rooms.manage` (ROLE_SUPER_ADMIN / ROLE_HR_ADMIN only).
*/

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
});

function roomActingUser(string $role): array
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token];
}

function createRoomViaApi(TestCase $test, string $token, array $overrides = []): array
{
    $payload = array_merge([
        'name' => 'Test zali '.uniqid(),
        'capacity' => 10,
        'location' => '1-qavat',
    ], $overrides);

    $response = $test->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/conference-rooms', $payload);

    return [$response, $response->json()];
}

it('requires authentication for conference-rooms.index', function () {
    $this->getJson('/api/v1/conference-rooms')->assertStatus(401);

    [, $token] = roomActingUser('ROLE_EMPLOYEE');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/conference-rooms')
        ->assertOk();
});

it('forbids ROLE_EMPLOYEE from creating, updating, or archiving rooms (403)', function () {
    [, $token] = roomActingUser('ROLE_EMPLOYEE');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/conference-rooms', ['name' => 'X', 'capacity' => 5])
        ->assertStatus(403);

    $room = ConferenceRoom::factory()->create();
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/conference-rooms/{$room->uuid}", ['name' => 'Y'])
        ->assertStatus(403);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/conference-rooms/{$room->uuid}")
        ->assertStatus(403);
});

it('creates a conference room and writes a CREATE audit entry', function () {
    [$actor, $token] = roomActingUser('ROLE_SUPER_ADMIN');

    [$response, $body] = createRoomViaApi($this, $token, ['name' => 'Katta zal', 'capacity' => 20, 'location' => '3-qavat']);

    $response->assertStatus(201);
    expect($body['name'])->toBe('Katta zal')
        ->and($body['capacity'])->toBe(20)
        ->and($body['location'])->toBe('3-qavat')
        ->and($body['status'])->toBe('ACTIVE');

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'CREATE',
        'resource_type' => 'conference-room',
        'resource_uuid' => $body['uuid'],
    ]);
});

it('updates a conference room and audits UPDATE', function () {
    [$actor, $token] = roomActingUser('ROLE_SUPER_ADMIN');

    [, $room] = createRoomViaApi($this, $token, ['name' => 'Original', 'capacity' => 8]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/conference-rooms/{$room['uuid']}", ['capacity' => 12])
        ->assertOk()
        ->assertJsonPath('capacity', 12)
        ->assertJsonPath('name', 'Original');

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'UPDATE',
        'resource_type' => 'conference-room',
        'resource_uuid' => $room['uuid'],
    ]);
});

it('archives a conference room (soft) and audits ARCHIVE', function () {
    [$actor, $token] = roomActingUser('ROLE_SUPER_ADMIN');

    [, $room] = createRoomViaApi($this, $token);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/conference-rooms/{$room['uuid']}")
        ->assertStatus(204);

    $this->assertDatabaseHas('conference_rooms', ['uuid' => $room['uuid'], 'status' => 'ARCHIVED']);
    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'ARCHIVE',
        'resource_type' => 'conference-room',
        'resource_uuid' => $room['uuid'],
    ]);
});

it('allows ROLE_HR_ADMIN to create and update rooms', function () {
    [, $token] = roomActingUser('ROLE_HR_ADMIN');

    [$response, $room] = createRoomViaApi($this, $token, ['name' => 'HR Yaratgan', 'capacity' => 15]);
    $response->assertStatus(201);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/conference-rooms/{$room['uuid']}", ['name' => 'HR Yangilagan'])
        ->assertOk();
});
