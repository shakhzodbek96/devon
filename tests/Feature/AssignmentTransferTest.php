<?php

use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\PositionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Assignment transfer (Flow 3) — PLAN_PHASE_D.md §1, §4
|--------------------------------------------------------------------------
|
| Ports the frontend mock's `transferEmployee` test surface: workload cap,
| close-old-assignment, PRIMARY promotion demoting the old primary,
| COMBINATION stacking without disturbing the existing primary, the
| `employees.primary_unit_uuid`/`position_id` sync, and the UNIT_TRANSFER
| audit shape. Helper names are prefixed `transfer*` to avoid clashing with
| the same-purpose helpers declared in EmployeesTest.php / UnitsTest.php —
| Pest loads every *Test.php file into one process, so two files cannot
| declare a same-named top-level function.
*/

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class, PositionsSeeder::class]);
});

function transferActingUser(string $role): array
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token];
}

/**
 * @return array{0: Employee, 1: Unit}
 */
function makeTransferEmployee(array $overrides = []): array
{
    $unit = Unit::factory()->create(['type' => 'OTHER']);
    $employee = Employee::factory()->create(array_merge([
        'primary_unit_uuid' => $unit->uuid,
        'position_id' => 'POS-SPECIALIST',
    ], $overrides));

    return [$employee, $unit];
}

it('requires authentication and gates employees.update for the transfer endpoint (403)', function () {
    [$employee] = makeTransferEmployee();
    $unit = Unit::factory()->create(['type' => 'OTHER']);

    $this->postJson("/api/v1/employees/{$employee->uuid}/transfer", [])->assertStatus(401);

    [, $token] = transferActingUser('ROLE_EMPLOYEE');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/employees/{$employee->uuid}/transfer", [
            'newUnitUuid' => $unit->uuid,
            'newPositionId' => 'POS-SPECIALIST',
            'startDate' => '2026-08-01',
            'workloadPercent' => 100,
            'type' => 'PRIMARY',
            'closeOldAssignment' => true,
        ])
        ->assertStatus(403);
});

it('transfers an employee to a new unit, closes the old primary, and syncs employees.primary_unit_uuid', function () {
    [$actor, $token] = transferActingUser('ROLE_SUPER_ADMIN');
    [$employee, $oldUnit] = makeTransferEmployee();
    $newUnit = Unit::factory()->create(['type' => 'OTHER']);

    // Seed the hire-time PRIMARY assignment (Phase C shape) that the
    // transfer will close.
    Assignment::create([
        'uuid' => (string) Str::uuid(),
        'employee_uuid' => $employee->uuid,
        'unit_uuid' => $oldUnit->uuid,
        'position_id' => 'POS-SPECIALIST',
        'is_primary' => true,
        'start_date' => '2024-01-15',
        'workload_percent' => 100,
        'type' => 'PRIMARY',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/employees/{$employee->uuid}/transfer", [
            'newUnitUuid' => $newUnit->uuid,
            'newPositionId' => 'POS-SPECIALIST',
            'startDate' => '2026-08-01',
            'workloadPercent' => 100,
            'type' => 'PRIMARY',
            'closeOldAssignment' => true,
            'reason' => 'Reorganizatsiya',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('unitUuid', $newUnit->uuid)
        ->assertJsonPath('isPrimary', true)
        ->assertJsonPath('type', 'PRIMARY');

    $this->assertDatabaseHas('employee_unit_assignments', [
        'employee_uuid' => $employee->uuid,
        'unit_uuid' => $oldUnit->uuid,
        'end_date' => '2026-08-01',
        'is_primary' => false,
    ]);
    $this->assertDatabaseHas('employee_unit_assignments', [
        'employee_uuid' => $employee->uuid,
        'unit_uuid' => $newUnit->uuid,
        'is_primary' => true,
        'end_date' => null,
    ]);
    $this->assertDatabaseHas('employees', [
        'uuid' => $employee->uuid,
        'primary_unit_uuid' => $newUnit->uuid,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'UNIT_TRANSFER',
        'resource_type' => 'employee',
        'resource_uuid' => $employee->uuid,
    ]);
    $audit = \App\Models\AuditLog::where('resource_uuid', $employee->uuid)
        ->where('action', 'UNIT_TRANSFER')->firstOrFail();
    expect($audit->changes)->toBe([
        'unit' => ['from' => $oldUnit->uuid, 'to' => $newUnit->uuid],
        'position' => ['from' => 'POS-SPECIALIST', 'to' => 'POS-SPECIALIST'],
    ])->and($audit->context)->toBe([
        'assignmentType' => 'PRIMARY',
        'workloadPercent' => 100,
        'closedOld' => true,
        'reason' => 'Reorganizatsiya',
    ]);
});

it('rejects a transfer that pushes total workload past 150% with code workload-exceeded', function () {
    [, $token] = transferActingUser('ROLE_SUPER_ADMIN');
    [$employee, $oldUnit] = makeTransferEmployee();
    $newUnit = Unit::factory()->create(['type' => 'OTHER']);

    Assignment::create([
        'uuid' => (string) Str::uuid(),
        'employee_uuid' => $employee->uuid,
        'unit_uuid' => $oldUnit->uuid,
        'position_id' => 'POS-SPECIALIST',
        'is_primary' => true,
        'start_date' => '2024-01-15',
        'workload_percent' => 100,
        'type' => 'PRIMARY',
    ]);

    // closeOldAssignment=false stacks the new 60% on top of the existing
    // active 100% → 160% > 150 cap.
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/employees/{$employee->uuid}/transfer", [
            'newUnitUuid' => $newUnit->uuid,
            'newPositionId' => 'POS-SPECIALIST',
            'startDate' => '2026-08-01',
            'workloadPercent' => 60,
            'type' => 'COMBINATION',
            'closeOldAssignment' => false,
        ]);

    $response->assertStatus(422)->assertJsonPath('code', 'workload-exceeded');
    $this->assertDatabaseMissing('employee_unit_assignments', ['unit_uuid' => $newUnit->uuid]);
});

it('promotes a new PRIMARY without closing the old row and demotes the previous primary', function () {
    [, $token] = transferActingUser('ROLE_SUPER_ADMIN');
    [$employee, $oldUnit] = makeTransferEmployee();
    $newUnit = Unit::factory()->create(['type' => 'OTHER']);

    $oldAssignmentUuid = (string) Str::uuid();
    Assignment::create([
        'uuid' => $oldAssignmentUuid,
        'employee_uuid' => $employee->uuid,
        'unit_uuid' => $oldUnit->uuid,
        'position_id' => 'POS-SPECIALIST',
        'is_primary' => true,
        'start_date' => '2024-01-15',
        'workload_percent' => 50,
        'type' => 'PRIMARY',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/employees/{$employee->uuid}/transfer", [
            'newUnitUuid' => $newUnit->uuid,
            'newPositionId' => 'POS-SPECIALIST',
            'startDate' => '2026-08-01',
            'workloadPercent' => 50,
            'type' => 'PRIMARY',
            'closeOldAssignment' => false,
        ]);

    $response->assertStatus(201)->assertJsonPath('isPrimary', true);

    // Old row stays open (no end_date) but loses is_primary.
    $this->assertDatabaseHas('employee_unit_assignments', [
        'uuid' => $oldAssignmentUuid,
        'end_date' => null,
        'is_primary' => false,
    ]);
    $this->assertDatabaseHas('employee_unit_assignments', [
        'employee_uuid' => $employee->uuid,
        'unit_uuid' => $newUnit->uuid,
        'is_primary' => true,
    ]);
    $this->assertDatabaseHas('employees', [
        'uuid' => $employee->uuid,
        'primary_unit_uuid' => $newUnit->uuid,
    ]);
});

it('adds a COMBINATION assignment alongside the existing primary without disturbing it', function () {
    [, $token] = transferActingUser('ROLE_SUPER_ADMIN');
    [$employee, $oldUnit] = makeTransferEmployee();
    $secondUnit = Unit::factory()->create(['type' => 'OTHER']);

    $primaryUuid = (string) Str::uuid();
    Assignment::create([
        'uuid' => $primaryUuid,
        'employee_uuid' => $employee->uuid,
        'unit_uuid' => $oldUnit->uuid,
        'position_id' => 'POS-SPECIALIST',
        'is_primary' => true,
        'start_date' => '2024-01-15',
        'workload_percent' => 80,
        'type' => 'PRIMARY',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/employees/{$employee->uuid}/transfer", [
            'newUnitUuid' => $secondUnit->uuid,
            'newPositionId' => 'POS-SPECIALIST',
            'startDate' => '2026-08-01',
            'workloadPercent' => 30,
            'type' => 'COMBINATION',
            'closeOldAssignment' => false,
        ]);

    $response->assertStatus(201)->assertJsonPath('isPrimary', false);

    // Original primary untouched — still primary, still open.
    $this->assertDatabaseHas('employee_unit_assignments', [
        'uuid' => $primaryUuid,
        'is_primary' => true,
        'end_date' => null,
    ]);
    $this->assertDatabaseHas('employee_unit_assignments', [
        'employee_uuid' => $employee->uuid,
        'unit_uuid' => $secondUnit->uuid,
        'is_primary' => false,
        'type' => 'COMBINATION',
    ]);
    // employees.primary_unit_uuid is untouched — combination never promotes.
    $this->assertDatabaseHas('employees', [
        'uuid' => $employee->uuid,
        'primary_unit_uuid' => $oldUnit->uuid,
    ]);
});

it('returns 404 for a non-existent employee uuid on transfer', function () {
    [, $token] = transferActingUser('ROLE_SUPER_ADMIN');
    $unit = Unit::factory()->create(['type' => 'OTHER']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/employees/'.((string) Str::uuid()).'/transfer', [
            'newUnitUuid' => $unit->uuid,
            'newPositionId' => 'POS-SPECIALIST',
            'startDate' => '2026-08-01',
            'workloadPercent' => 100,
            'type' => 'PRIMARY',
            'closeOldAssignment' => true,
        ])
        ->assertStatus(404);
});
