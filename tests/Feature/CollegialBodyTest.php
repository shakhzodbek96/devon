<?php

use App\Models\CollegialBody;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Collegial-body registry — PLAN_kollegial-organ.md §2.2, §4
|--------------------------------------------------------------------------
|
| CRUD + member management + RBAC: `index` open to any authenticated user,
| mutations gated by `collegial.manage` (ROLE_SUPER_ADMIN / ROLE_HR_ADMIN
| only).
*/

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
});

function collegialActingUser(string $role): array
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token];
}

function createBodyViaApi(TestCase $test, string $token, array $overrides = []): array
{
    $payload = array_merge([
        'name' => 'Test Qo\'mitasi '.uniqid(),
        'chairmanEmployeeUuid' => Employee::factory()->create()->uuid,
        'secretaryEmployeeUuid' => Employee::factory()->create()->uuid,
        'quorumMin' => 2,
        'memberEmployeeUuids' => [],
    ], $overrides);

    $response = $test->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/collegial-bodies', $payload);

    return [$response, $response->json()];
}

it('requires authentication for collegial-bodies.index', function () {
    $this->getJson('/api/v1/collegial-bodies')->assertStatus(401);

    [, $token] = collegialActingUser('ROLE_EMPLOYEE');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/collegial-bodies')
        ->assertOk();
});

it('forbids ROLE_EMPLOYEE from creating, updating, or archiving bodies (403)', function () {
    [, $token] = collegialActingUser('ROLE_EMPLOYEE');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/collegial-bodies', [
            'name' => 'X',
            'chairmanEmployeeUuid' => Employee::factory()->create()->uuid,
            'secretaryEmployeeUuid' => Employee::factory()->create()->uuid,
            'quorumMin' => 1,
        ])
        ->assertStatus(403);

    $body = CollegialBody::factory()->create();
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/collegial-bodies/{$body->uuid}", ['name' => 'Y'])
        ->assertStatus(403);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/collegial-bodies/{$body->uuid}")
        ->assertStatus(403);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/collegial-bodies/{$body->uuid}/members", ['employeeUuid' => Employee::factory()->create()->uuid])
        ->assertStatus(403);
});

it('creates a collegial body with members and writes a CREATE audit entry', function () {
    [$actor, $token] = collegialActingUser('ROLE_SUPER_ADMIN');
    $members = Employee::factory()->count(4)->create()->pluck('uuid')->all();

    [$response, $body] = createBodyViaApi($this, $token, [
        'name' => 'Boshqaruv Qo\'mitasi',
        'quorumMin' => 3,
        'memberEmployeeUuids' => $members,
    ]);

    $response->assertStatus(201);
    expect($body['name'])->toBe('Boshqaruv Qo\'mitasi')
        ->and($body['quorumMin'])->toBe(3)
        ->and($body['status'])->toBe('ACTIVE')
        ->and($body['memberEmployeeUuids'])->toHaveCount(4);

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'CREATE',
        'resource_type' => 'collegial-body',
        'resource_uuid' => $body['uuid'],
    ]);
});

it('updates a collegial body and audits UPDATE', function () {
    [$actor, $token] = collegialActingUser('ROLE_SUPER_ADMIN');

    [, $body] = createBodyViaApi($this, $token, ['name' => 'Original']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/collegial-bodies/{$body['uuid']}", ['quorumMin' => 5])
        ->assertOk()
        ->assertJsonPath('quorumMin', 5)
        ->assertJsonPath('name', 'Original');

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'UPDATE',
        'resource_type' => 'collegial-body',
        'resource_uuid' => $body['uuid'],
    ]);
});

it('archives a collegial body (soft) and audits ARCHIVE', function () {
    [$actor, $token] = collegialActingUser('ROLE_SUPER_ADMIN');

    [, $body] = createBodyViaApi($this, $token);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/collegial-bodies/{$body['uuid']}")
        ->assertStatus(204);

    $this->assertDatabaseHas('collegial_bodies', ['uuid' => $body['uuid'], 'status' => 'ARCHIVED']);
    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'ARCHIVE',
        'resource_type' => 'collegial-body',
        'resource_uuid' => $body['uuid'],
    ]);
});

it('adds and removes a member from the roster', function () {
    [, $token] = collegialActingUser('ROLE_SUPER_ADMIN');
    [, $body] = createBodyViaApi($this, $token);
    $newMember = Employee::factory()->create()->uuid;

    $addResponse = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/collegial-bodies/{$body['uuid']}/members", ['employeeUuid' => $newMember])
        ->assertOk();
    expect($addResponse->json('memberEmployeeUuids'))->toContain($newMember);

    $this->assertDatabaseHas('collegial_body_members', [
        'collegial_body_uuid' => $body['uuid'],
        'employee_uuid' => $newMember,
    ]);

    $removeResponse = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/collegial-bodies/{$body['uuid']}/members/{$newMember}")
        ->assertOk();
    expect($removeResponse->json('memberEmployeeUuids'))->not->toContain($newMember);

    $this->assertDatabaseMissing('collegial_body_members', [
        'collegial_body_uuid' => $body['uuid'],
        'employee_uuid' => $newMember,
    ]);
});

it('rejects removing a non-member with not-body-member', function () {
    [, $token] = collegialActingUser('ROLE_SUPER_ADMIN');
    [, $body] = createBodyViaApi($this, $token);
    $stranger = Employee::factory()->create()->uuid;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/collegial-bodies/{$body['uuid']}/members/{$stranger}")
        ->assertStatus(422)
        ->assertJsonPath('code', 'not-body-member');
});

it('allows ROLE_HR_ADMIN to create and manage bodies', function () {
    [, $token] = collegialActingUser('ROLE_HR_ADMIN');

    [$response, $body] = createBodyViaApi($this, $token, ['name' => 'HR Yaratgan']);
    $response->assertStatus(201);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/collegial-bodies/{$body['uuid']}", ['name' => 'HR Yangilagan'])
        ->assertOk();
});
