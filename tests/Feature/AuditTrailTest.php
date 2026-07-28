<?php

use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
});

function auditActingUser(string $role): array
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token];
}

it('writes a LOGIN audit entry on successful login', function () {
    $user = User::factory()->create(['email' => 'auditlogin@devon.uz', 'password' => 'Demo2026!']);
    $user->assignRole('ROLE_SUPER_ADMIN');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'auditlogin@devon.uz',
        'password' => 'Demo2026!',
    ])->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $user->uuid,
        'action' => 'LOGIN',
        'resource_type' => 'user',
        'resource_uuid' => $user->uuid,
    ]);
});

it('writes a LOGOUT audit entry on logout', function () {
    [$user, $token] = auditActingUser('ROLE_SUPER_ADMIN');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(204);

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $user->uuid,
        'action' => 'LOGOUT',
        'resource_type' => 'user',
    ]);
});

it('writes a PASSWORD_CHANGED audit entry on change-password', function () {
    [$user, $token] = auditActingUser('ROLE_SUPER_ADMIN');
    $user->update(['password' => 'Demo2026!']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Demo2026!',
            'new_password' => 'NewPassw0rd!23',
        ])->assertStatus(204);

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $user->uuid,
        'action' => 'PASSWORD_CHANGED',
        'resource_type' => 'user',
    ]);
});

it('writes a CREATE audit entry when an admin creates a user', function () {
    [$admin, $token] = auditActingUser('ROLE_SUPER_ADMIN');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/users', [
            'email' => 'audited-new@devon.uz',
            'fullName' => 'Audited New',
            'roles' => ['ROLE_EMPLOYEE'],
        ])->assertStatus(201);

    $newUuid = $response->json('user.uuid');

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $admin->uuid,
        'action' => 'CREATE',
        'resource_type' => 'user',
        'resource_uuid' => $newUuid,
    ]);
});

it('writes an UPDATE audit entry when an admin updates a user', function () {
    [$admin, $token] = auditActingUser('ROLE_SUPER_ADMIN');
    $target = User::factory()->create();
    $target->assignRole('ROLE_EMPLOYEE');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/users/{$target->uuid}", ['status' => 'SUSPENDED'])
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $admin->uuid,
        'action' => 'UPDATE',
        'resource_type' => 'user',
        'resource_uuid' => $target->uuid,
    ]);
});

it('never sets updated_at and blocks direct model updates/deletes (append-only)', function () {
    [$user] = auditActingUser('ROLE_SUPER_ADMIN');
    $entry = \App\Support\AuditLogger::log($user, 'LOGIN', 'user', $user->uuid, $user->email);

    expect($entry->getAttributes())->not->toHaveKey('updated_at');

    expect(fn () => $entry->update(['action' => 'LOGOUT']))->toThrow(RuntimeException::class);
    expect(fn () => $entry->delete())->toThrow(RuntimeException::class);
});

// NOTE: each test below authenticates as exactly ONE user for its whole
// body. Laravel's Sanctum `RequestGuard` caches the first *resolved*
// (non-null) user for the lifetime of the guard instance — reusing it for
// every subsequent simulated request in the same test regardless of which
// Bearer token is sent next. Mixing several different users' tokens inside
// one `it()` silently tests the wrong actor for every call after the
// first; every existing test in this suite (UserManagementTest etc.)
// already follows the one-actor-per-test convention for this reason.

it('forbids ROLE_EMPLOYEE from reading audit (403)', function () {
    [, $employeeToken] = auditActingUser('ROLE_EMPLOYEE');

    $this->withHeader('Authorization', "Bearer {$employeeToken}")
        ->getJson('/api/v1/audit')
        ->assertStatus(403);
});

it('returns audit entries newest-first with filters, as ROLE_SUPER_ADMIN', function () {
    [$admin, $token] = auditActingUser('ROLE_SUPER_ADMIN');

    \App\Support\AuditLogger::log($admin, 'LOGIN', 'user', $admin->uuid, $admin->email);
    \App\Support\AuditLogger::log($admin, 'LOGOUT', 'user', $admin->uuid, $admin->email);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/audit')
        ->assertOk();

    $rows = $response->json();
    expect(count($rows))->toBeGreaterThanOrEqual(2);
    // Newest-first.
    expect($rows[0]['action'])->toBe('LOGOUT');
    expect($rows[0])->toHaveKeys(['uuid', 'actorUuid', 'actorName', 'action', 'resourceType', 'resourceUuid', 'resourceLabel', 'createdAt']);

    $filtered = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/audit?resourceType=user')
        ->assertOk()
        ->json();
    expect(count($filtered))->toBeGreaterThanOrEqual(2)
        ->and(collect($filtered)->every(fn ($r) => $r['resourceType'] === 'user'))->toBeTrue();

    $limited = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/audit?limit=1')
        ->assertOk()
        ->json();
    expect($limited)->toHaveCount(1);

    $byActor = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/audit?actorUuid={$admin->uuid}")
        ->assertOk()
        ->json();
    expect(collect($byActor)->every(fn ($r) => $r['actorUuid'] === $admin->uuid))->toBeTrue();
});

it('allows ROLE_AUDITOR to read audit', function () {
    [, $auditorToken] = auditActingUser('ROLE_AUDITOR');

    $this->withHeader('Authorization', "Bearer {$auditorToken}")
        ->getJson('/api/v1/audit')
        ->assertOk();
});

it('allows ROLE_HR_ADMIN to read audit', function () {
    [, $hrAdminToken] = auditActingUser('ROLE_HR_ADMIN');

    $this->withHeader('Authorization', "Bearer {$hrAdminToken}")
        ->getJson('/api/v1/audit')
        ->assertOk();
});

it('forbids ROLE_UNIT_HEAD from reading audit (403)', function () {
    [, $unitHeadToken] = auditActingUser('ROLE_UNIT_HEAD');

    $this->withHeader('Authorization', "Bearer {$unitHeadToken}")
        ->getJson('/api/v1/audit')
        ->assertStatus(403);
});
