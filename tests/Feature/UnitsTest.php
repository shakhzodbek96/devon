<?php

use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Tests\TestCase;

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
});

function unitsActingUser(string $role): array
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token];
}

function createUnitViaApi(TestCase $test, string $token, array $overrides = []): array
{
    $payload = array_merge([
        'nameUz' => 'Test Bo\'limi '.uniqid(),
        'code' => 'T-'.strtoupper(uniqid()),
        'type' => 'OTHER',
        'parentUuid' => null,
    ], $overrides);

    $response = $test->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/units', $payload);

    return [$response, $response->json()];
}

it('requires authentication and gates units.view for all roles', function () {
    $this->getJson('/api/v1/units')->assertStatus(401);

    [, $token] = unitsActingUser('ROLE_EMPLOYEE');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/units')
        ->assertOk();
});

it('forbids ROLE_EMPLOYEE from creating or updating units (403)', function () {
    [, $token] = unitsActingUser('ROLE_EMPLOYEE');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/units', ['nameUz' => 'X', 'code' => 'X-1', 'type' => 'OTHER', 'parentUuid' => null])
        ->assertStatus(403);

    $unit = Unit::factory()->create();
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/units/{$unit->uuid}", ['nameUz' => 'Y'])
        ->assertStatus(403);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/units/{$unit->uuid}")
        ->assertStatus(403);
});

it('creates a root unit and writes a CREATE audit entry', function () {
    [$actor, $token] = unitsActingUser('ROLE_SUPER_ADMIN');

    [$response, $body] = createUnitViaApi($this, $token, ['nameUz' => 'Yangi Departament', 'code' => 'YD-01']);

    $response->assertStatus(201);
    expect($body['nameUz'])->toBe('Yangi Departament')
        ->and($body['level'])->toBe(0)
        ->and($body['path'])->toBe('/'.$body['uuid'].'/')
        ->and($body['status'])->toBe('ACTIVE')
        ->and($body['parentUuid'])->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'CREATE',
        'resource_type' => 'unit',
        'resource_uuid' => $body['uuid'],
    ]);
});

it('creates a child unit with recomputed level and path', function () {
    [, $token] = unitsActingUser('ROLE_SUPER_ADMIN');

    [, $parent] = createUnitViaApi($this, $token, ['nameUz' => 'Ota Bo\'linma', 'code' => 'PARENT-01']);
    [$response, $child] = createUnitViaApi($this, $token, [
        'nameUz' => 'Bola Bo\'linma', 'code' => 'CHILD-01', 'parentUuid' => $parent['uuid'],
    ]);

    $response->assertStatus(201);
    expect($child['level'])->toBe(1)
        ->and($child['path'])->toBe($parent['path'].$child['uuid'].'/')
        ->and($child['parentUuid'])->toBe($parent['uuid']);
});

it('rejects a duplicate sibling name with code duplicate-name', function () {
    [, $token] = unitsActingUser('ROLE_SUPER_ADMIN');

    createUnitViaApi($this, $token, ['nameUz' => 'Bir Xil Nom', 'code' => 'DUP-01']);
    [$response] = createUnitViaApi($this, $token, ['nameUz' => 'bir xil nom', 'code' => 'DUP-02']);

    $response->assertStatus(422)->assertJsonPath('code', 'duplicate-name');
});

it('rejects an unknown parent with code invalid-parent', function () {
    [, $token] = unitsActingUser('ROLE_SUPER_ADMIN');

    [$response] = createUnitViaApi($this, $token, [
        'nameUz' => 'Yetim Bo\'linma', 'code' => 'ORPHAN-01', 'parentUuid' => (string) Illuminate\Support\Str::uuid(),
    ]);

    $response->assertStatus(422)->assertJsonPath('code', 'invalid-parent');
});

it('rejects a chain deeper than MAX_UNIT_DEPTH (7) with code max-depth', function () {
    [, $token] = unitsActingUser('ROLE_SUPER_ADMIN');

    $parentUuid = null;
    // Build levels 0..6 (7 nodes) — the last one sits at level 6.
    for ($i = 0; $i < 7; $i++) {
        [$response, $unit] = createUnitViaApi($this, $token, [
            'nameUz' => "Chuqurlik {$i}", 'code' => "DEPTH-{$i}", 'parentUuid' => $parentUuid,
        ]);
        $response->assertStatus(201);
        $parentUuid = $unit['uuid'];
    }

    // An 8th level (level 7) exceeds MAX_UNIT_DEPTH.
    [$response] = createUnitViaApi($this, $token, [
        'nameUz' => 'Chuqurlik 7', 'code' => 'DEPTH-7', 'parentUuid' => $parentUuid,
    ]);
    $response->assertStatus(422)->assertJsonPath('code', 'max-depth');
});

it('rejects a self-parent and a descendant-parent with code cycle', function () {
    [, $token] = unitsActingUser('ROLE_SUPER_ADMIN');

    [, $a] = createUnitViaApi($this, $token, ['nameUz' => 'A Bo\'linma', 'code' => 'CYC-A']);
    [, $b] = createUnitViaApi($this, $token, ['nameUz' => 'B Bo\'linma', 'code' => 'CYC-B', 'parentUuid' => $a['uuid']]);

    // Self-parent.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/units/{$a['uuid']}", ['parentUuid' => $a['uuid']])
        ->assertStatus(422)
        ->assertJsonPath('code', 'cycle');

    // A's own descendant (B) cannot become A's parent.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/units/{$a['uuid']}", ['parentUuid' => $b['uuid']])
        ->assertStatus(422)
        ->assertJsonPath('code', 'cycle');
});

it('moves a unit, recomputes its + descendants level/path, and audits UNIT_TRANSFER', function () {
    [$actor, $token] = unitsActingUser('ROLE_SUPER_ADMIN');

    [, $a] = createUnitViaApi($this, $token, ['nameUz' => 'Move A', 'code' => 'MOVE-A']);
    [, $b] = createUnitViaApi($this, $token, ['nameUz' => 'Move B', 'code' => 'MOVE-B', 'parentUuid' => $a['uuid']]);
    [, $c] = createUnitViaApi($this, $token, ['nameUz' => 'Move C', 'code' => 'MOVE-C', 'parentUuid' => $b['uuid']]);
    [, $d] = createUnitViaApi($this, $token, ['nameUz' => 'Move D', 'code' => 'MOVE-D']);

    // Move B (with descendant C) under D.
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/units/{$b['uuid']}", ['parentUuid' => $d['uuid']])
        ->assertOk();

    $movedB = $response->json();
    expect($movedB['level'])->toBe(1)
        ->and($movedB['path'])->toBe($d['path'].$b['uuid'].'/');

    $freshC = Unit::where('uuid', $c['uuid'])->first();
    expect($freshC->level)->toBe(2)
        ->and($freshC->path)->toBe($d['path'].$b['uuid'].'/'.$c['uuid'].'/');

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'UNIT_TRANSFER',
        'resource_type' => 'unit',
        'resource_uuid' => $b['uuid'],
    ]);
});

it('updates a plain field without changing parent and audits UPDATE (not UNIT_TRANSFER)', function () {
    [$actor, $token] = unitsActingUser('ROLE_SUPER_ADMIN');

    [, $unit] = createUnitViaApi($this, $token, ['nameUz' => 'Original Nomi', 'code' => 'UPD-01']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/units/{$unit['uuid']}", ['nameUz' => 'Yangilangan Nomi'])
        ->assertOk()
        ->assertJsonPath('nameUz', 'Yangilangan Nomi');

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'UPDATE',
        'resource_type' => 'unit',
        'resource_uuid' => $unit['uuid'],
    ]);
    $this->assertDatabaseMissing('audit_logs', [
        'resource_uuid' => $unit['uuid'],
        'action' => 'UNIT_TRANSFER',
    ]);
});

it('archives a unit (soft) and audits ARCHIVE', function () {
    [$actor, $token] = unitsActingUser('ROLE_SUPER_ADMIN');

    [, $unit] = createUnitViaApi($this, $token, ['nameUz' => 'Arxivlanadigan', 'code' => 'ARC-01']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/units/{$unit['uuid']}")
        ->assertStatus(204);

    $this->assertDatabaseHas('structural_units', ['uuid' => $unit['uuid'], 'status' => 'ARCHIVED']);
    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'ARCHIVE',
        'resource_type' => 'unit',
        'resource_uuid' => $unit['uuid'],
    ]);
});

it('returns 404 for a non-existent unit uuid', function () {
    [, $token] = unitsActingUser('ROLE_SUPER_ADMIN');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/units/'.((string) Illuminate\Support\Str::uuid()))
        ->assertStatus(404);
});

it('assigns a real employee as unit head via PATCH headEmployeeUuid (no auto ROLE_UNIT_HEAD grant)', function () {
    // PLAN_PHASE_D.md §2 — the mock never auto-grants ROLE_UNIT_HEAD when a
    // unit's headEmployeeUuid is set (seed.ts only derives it from the
    // holder's position at seed time; `updateUnit` itself does nothing
    // role-related), so the backend mirrors that: PATCH persists the uuid
    // only, no roles table write.
    [$actor, $token] = unitsActingUser('ROLE_SUPER_ADMIN');

    [, $unit] = createUnitViaApi($this, $token, ['nameUz' => 'Rahbarli Bo\'linma', 'code' => 'HEAD-01']);
    $employee = Employee::factory()->create();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/units/{$unit['uuid']}", ['headEmployeeUuid' => $employee->uuid]);

    $response->assertOk()->assertJsonPath('headEmployeeUuid', $employee->uuid);
    $this->assertDatabaseHas('structural_units', [
        'uuid' => $unit['uuid'],
        'head_employee_uuid' => $employee->uuid,
    ]);

    $employeeUser = User::factory()->create(['employee_uuid' => $employee->uuid]);
    expect($employeeUser->fresh()->roles()->where('name', 'ROLE_UNIT_HEAD')->exists())->toBeFalse();

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'UPDATE',
        'resource_type' => 'unit',
        'resource_uuid' => $unit['uuid'],
    ]);
});

it('forbids ROLE_EMPLOYEE from setting a unit head (403)', function () {
    // Built directly via the factory (no prior authenticated HTTP call) —
    // Sanctum's guard memoizes the resolved user on the guard instance
    // within one test, so making an admin request first and then switching
    // tokens would still resolve as the admin (see EmployeesTest.php).
    $unit = Unit::factory()->create();
    $employee = Employee::factory()->create();

    [, $token] = unitsActingUser('ROLE_EMPLOYEE');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/units/{$unit->uuid}", ['headEmployeeUuid' => $employee->uuid])
        ->assertStatus(403);
});

it('allows ROLE_HR_ADMIN to create and update units', function () {
    [, $token] = unitsActingUser('ROLE_HR_ADMIN');

    [$response, $unit] = createUnitViaApi($this, $token, ['nameUz' => 'HR Yaratgan', 'code' => 'HRADM-01']);
    $response->assertStatus(201);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/units/{$unit['uuid']}", ['nameUz' => 'HR Yangilagan'])
        ->assertOk();
});
