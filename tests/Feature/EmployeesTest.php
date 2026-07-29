<?php

use App\Models\Employee;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\EmployeesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\PositionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class, PositionsSeeder::class]);
});

function employeesActingUser(string $role): array
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token];
}

/**
 * @return array{0: \Illuminate\Testing\TestResponse, 1: array, 2: Unit}
 */
function createEmployeeViaApi(TestCase $test, string $token, array $overrides = []): array
{
    $unit = Unit::factory()->create(['type' => 'OTHER']);

    $payload = array_replace_recursive([
        'employee' => [
            'lastName' => 'Testov',
            'firstName' => 'Test',
            'middleName' => 'Testovich',
            'gender' => 'M',
            'pinfl' => (string) random_int(30000000000000, 39999999999999),
            'mobilePhone' => '+998 90 123 45 67',
            'corporateEmail' => 'test.'.uniqid().'@devon.uz',
            'primaryUnitUuid' => $unit->uuid,
            'positionId' => 'POS-SPECIALIST',
            'employmentType' => 'FULL_TIME',
            'hireDate' => '2024-01-15',
        ],
        'orderExtract' => [
            'fileName' => 'buyruq.pdf',
            'fileSize' => 1024,
            'mimeType' => 'application/pdf',
        ],
        'positionInstruction' => [
            'fileName' => 'lavozim.pdf',
            'fileSize' => 2048,
            'mimeType' => 'application/pdf',
        ],
        'password' => 'Welcome2026!',
        'role' => 'ROLE_EMPLOYEE',
    ], $overrides);

    $response = $test->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/employees', $payload);

    return [$response, $response->json(), $unit];
}

it('requires authentication and gates employees.view for all roles', function () {
    $this->getJson('/api/v1/employees')->assertStatus(401);

    [, $token] = employeesActingUser('ROLE_EMPLOYEE');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/employees')
        ->assertOk();
});

it('forbids ROLE_EMPLOYEE from creating, updating, or terminating employees (403)', function () {
    [, $token] = employeesActingUser('ROLE_EMPLOYEE');
    $unit = Unit::factory()->create();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/employees', [
            'employee' => [
                'lastName' => 'X', 'firstName' => 'Y', 'gender' => 'M',
                'pinfl' => '30000000000011', 'mobilePhone' => '+998901234567',
                'corporateEmail' => 'x@devon.uz', 'primaryUnitUuid' => $unit->uuid,
                'positionId' => 'POS-SPECIALIST', 'employmentType' => 'FULL_TIME', 'hireDate' => '2024-01-01',
            ],
            'orderExtract' => ['fileName' => 'a.pdf', 'fileSize' => 1, 'mimeType' => 'application/pdf'],
            'positionInstruction' => ['fileName' => 'b.pdf', 'fileSize' => 1, 'mimeType' => 'application/pdf'],
            'password' => 'Welcome2026!',
            'role' => 'ROLE_EMPLOYEE',
        ])
        ->assertStatus(403);

    $employee = Employee::factory()->create();
    $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/employees/{$employee->uuid}", ['mobilePhone' => '+998901234567'])
        ->assertStatus(403);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/employees/{$employee->uuid}/terminate", [
            'orderExtract' => ['fileName' => 'c.pdf', 'fileSize' => 1, 'mimeType' => 'application/pdf'],
        ])
        ->assertStatus(403);
});

it('creates an employee transactionally (user + employee + primary assignment) and writes a CREATE audit entry', function () {
    [$actor, $token] = employeesActingUser('ROLE_SUPER_ADMIN');

    [$response, $body, $unit] = createEmployeeViaApi($this, $token);

    $response->assertStatus(201);
    expect($body['employee']['fullNameGenerated'])->toBe('Testov Test Testovich')
        ->and($body['employee']['status'])->toBe('ACTIVE')
        ->and($body['employee']['primaryUnitUuid'])->toBe($unit->uuid)
        ->and($body['user']['mustChangePassword'])->toBeTrue()
        ->and($body['user']['roles'])->toBe(['ROLE_EMPLOYEE'])
        ->and($body['assignment']['isPrimary'])->toBeTrue()
        ->and($body['assignment']['type'])->toBe('PRIMARY')
        ->and($body['assignment']['workloadPercent'])->toBe(100);

    $this->assertDatabaseHas('employees', ['uuid' => $body['employee']['uuid']]);
    $this->assertDatabaseHas('users', ['uuid' => $body['user']['uuid'], 'employee_uuid' => $body['employee']['uuid']]);
    $this->assertDatabaseHas('employee_unit_assignments', ['employee_uuid' => $body['employee']['uuid'], 'is_primary' => true]);
    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'CREATE',
        'resource_type' => 'employee',
        'resource_uuid' => $body['employee']['uuid'],
    ]);
});

it('rejects a duplicate PINFL among non-terminated employees with code pinfl-taken', function () {
    [, $token] = employeesActingUser('ROLE_SUPER_ADMIN');

    [$first] = createEmployeeViaApi($this, $token, ['employee' => ['pinfl' => '30000000000099']]);
    $first->assertStatus(201);

    [$second] = createEmployeeViaApi($this, $token, ['employee' => ['pinfl' => '30000000000099']]);
    $second->assertStatus(422)->assertJsonPath('code', 'pinfl-taken');
});

it('allows a freed PINFL from a TERMINATED employee to be re-issued', function () {
    [, $token] = employeesActingUser('ROLE_SUPER_ADMIN');

    [$first, $firstBody] = createEmployeeViaApi($this, $token, ['employee' => ['pinfl' => '30000000000077']]);
    $first->assertStatus(201);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/employees/{$firstBody['employee']['uuid']}/terminate", [
            'orderExtract' => ['fileName' => 'termination.pdf', 'fileSize' => 100, 'mimeType' => 'application/pdf'],
        ])
        ->assertOk();

    [$second] = createEmployeeViaApi($this, $token, ['employee' => ['pinfl' => '30000000000077']]);
    $second->assertStatus(201);
});

it('rejects a duplicate corporate email with code email-taken', function () {
    [, $token] = employeesActingUser('ROLE_SUPER_ADMIN');

    [$first, $firstBody] = createEmployeeViaApi($this, $token);
    $first->assertStatus(201);

    [$second] = createEmployeeViaApi($this, $token, [
        'employee' => ['corporateEmail' => $firstBody['employee']['corporateEmail'], 'pinfl' => '30000000000088'],
    ]);
    $second->assertStatus(422)->assertJsonPath('code', 'email-taken');
});

it('requires the hiring-order extract with code order-extract-missing', function () {
    [, $token] = employeesActingUser('ROLE_SUPER_ADMIN');

    [$response] = createEmployeeViaApi($this, $token, ['orderExtract' => ['fileName' => null]]);
    $response->assertStatus(422)->assertJsonPath('code', 'order-extract-missing');
});

it('requires the position instruction with code position-instruction-missing', function () {
    [, $token] = employeesActingUser('ROLE_SUPER_ADMIN');

    [$response] = createEmployeeViaApi($this, $token, ['positionInstruction' => ['fileName' => null]]);
    $response->assertStatus(422)->assertJsonPath('code', 'position-instruction-missing');
});

it('rejects an invalid PINFL shape with a standard validation error', function () {
    [, $token] = employeesActingUser('ROLE_SUPER_ADMIN');

    [$response] = createEmployeeViaApi($this, $token, ['employee' => ['pinfl' => 'not-a-pinfl']]);
    $response->assertStatus(422)->assertJsonValidationErrors('employee.pinfl');
});

it('updates an employee, recomputes fullNameGenerated, and audits UPDATE (pinfl stays locked)', function () {
    [$actor, $token] = employeesActingUser('ROLE_SUPER_ADMIN');

    [, $body] = createEmployeeViaApi($this, $token);
    $originalPinfl = $body['employee']['pinfl'];

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->patchJson("/api/v1/employees/{$body['employee']['uuid']}", [
            'firstName' => 'Yangi',
            'pinfl' => '99999999999999', // ignored — not in the allowed patch set
        ]);

    $response->assertOk()
        ->assertJsonPath('fullNameGenerated', 'Testov Yangi Testovich')
        ->assertJsonPath('pinfl', $originalPinfl);

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'UPDATE',
        'resource_type' => 'employee',
        'resource_uuid' => $body['employee']['uuid'],
    ]);
});

it('terminates an employee, requires the order extract, and flips status to TERMINATED', function () {
    [$actor, $token] = employeesActingUser('ROLE_SUPER_ADMIN');

    [, $body] = createEmployeeViaApi($this, $token);
    $uuid = $body['employee']['uuid'];

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/employees/{$uuid}/terminate", [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'termination-extract-missing');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/employees/{$uuid}/terminate", [
            'orderExtract' => ['fileName' => 'termination.pdf', 'fileSize' => 500, 'mimeType' => 'application/pdf'],
        ]);

    $response->assertOk()->assertJsonPath('status', 'TERMINATED');
    $this->assertDatabaseHas('employees', ['uuid' => $uuid, 'status' => 'TERMINATED']);
    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'UPDATE',
        'resource_type' => 'employee',
        'resource_uuid' => $uuid,
    ]);
});

it('filters the employee list by search (name, email, pinfl)', function () {
    [, $token] = employeesActingUser('ROLE_SUPER_ADMIN');

    [, $body] = createEmployeeViaApi($this, $token, [
        'employee' => ['lastName' => 'Uniqova', 'firstName' => 'Uniq', 'pinfl' => '30000000000066'],
    ]);

    $byName = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/employees?search=Uniqova');
    $byName->assertOk();
    expect($byName->json())->toHaveCount(1)
        ->and($byName->json()[0]['uuid'])->toBe($body['employee']['uuid']);

    $byEmail = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/employees?search='.urlencode($body['employee']['corporateEmail']));
    $byEmail->assertOk();
    expect($byEmail->json())->toHaveCount(1);

    $byPinfl = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/employees?search=30000000000066');
    $byPinfl->assertOk();
    expect($byPinfl->json())->toHaveCount(1);
});

it('lists assignments for an employee', function () {
    [, $token] = employeesActingUser('ROLE_SUPER_ADMIN');

    [, $body] = createEmployeeViaApi($this, $token);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/employees/{$body['employee']['uuid']}/assignments");

    $response->assertOk();
    expect($response->json())->toHaveCount(1)
        ->and($response->json()[0]['employeeUuid'])->toBe($body['employee']['uuid'])
        ->and($response->json()[0]['type'])->toBe('PRIMARY');
});

it('validates PINFL uniqueness via GET /validate/pinfl/{pinfl}', function () {
    [, $token] = employeesActingUser('ROLE_SUPER_ADMIN');

    [, $body] = createEmployeeViaApi($this, $token, ['employee' => ['pinfl' => '30000000000055']]);

    $taken = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/validate/pinfl/30000000000055');
    $taken->assertOk()->assertJsonPath('taken', true);

    $free = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/validate/pinfl/30000000000056');
    $free->assertOk()->assertJsonPath('taken', false);
});

it('submits a profile-change request for the acting user\'s own employee and audits PROFILE_CHANGE_REQUESTED', function () {
    $employee = Employee::factory()->create(['mobile_phone' => '+998 90 111 11 11']);
    $user = User::factory()->create(['employee_uuid' => $employee->uuid]);
    $user->assignRole('ROLE_EMPLOYEE');
    $token = $user->createToken('test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/me/profile-change-request', [
            'fields' => [
                'mobilePhone' => ['from' => '+998 90 111 11 11', 'to' => '+998 90 222 22 22'],
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('employeeUuid', $employee->uuid)
        ->assertJsonPath('status', 'PENDING');

    $this->assertDatabaseHas('profile_change_requests', ['employee_uuid' => $employee->uuid, 'status' => 'PENDING']);
    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $user->uuid,
        'action' => 'PROFILE_CHANGE_REQUESTED',
        'resource_type' => 'profile-request',
    ]);
});

it('forbids a plain ROLE_EMPLOYEE from listing or deciding profile-change requests (403)', function () {
    [, $token] = employeesActingUser('ROLE_EMPLOYEE');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/profile-change-requests')
        ->assertStatus(403);
});

it('approves a profile-change request and applies the field patch to the employee', function () {
    [$actor, $adminToken] = employeesActingUser('ROLE_SUPER_ADMIN');

    $employee = Employee::factory()->create(['personal_email' => null]);
    $user = User::factory()->create(['employee_uuid' => $employee->uuid]);
    $user->assignRole('ROLE_EMPLOYEE');
    $userToken = $user->createToken('test')->plainTextToken;

    $submit = $this->withHeader('Authorization', "Bearer {$userToken}")
        ->postJson('/api/v1/me/profile-change-request', [
            'fields' => ['personalEmail' => ['from' => null, 'to' => 'new@example.com']],
        ]);
    $requestUuid = $submit->json('uuid');

    // Sanctum's guard memoizes the resolved user on the guard instance,
    // which the test app container reuses across HTTP calls within one
    // test — without forgetting it, the next call would still resolve as
    // the previous (non-admin) actor regardless of the new bearer token.
    Auth::forgetGuards();

    $decide = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/v1/profile-change-requests/{$requestUuid}/decide", ['decision' => 'APPROVED']);

    $decide->assertOk()->assertJsonPath('status', 'APPROVED');
    $this->assertDatabaseHas('employees', ['uuid' => $employee->uuid, 'personal_email' => 'new@example.com']);
    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'PROFILE_CHANGE_APPROVED',
        'resource_type' => 'profile-request',
        'resource_uuid' => $requestUuid,
    ]);
});

it('rejects a profile-change request without applying any patch', function () {
    [, $adminToken] = employeesActingUser('ROLE_SUPER_ADMIN');

    $employee = Employee::factory()->create(['personal_email' => null]);
    $user = User::factory()->create(['employee_uuid' => $employee->uuid]);
    $user->assignRole('ROLE_EMPLOYEE');
    $userToken = $user->createToken('test')->plainTextToken;

    $submit = $this->withHeader('Authorization', "Bearer {$userToken}")
        ->postJson('/api/v1/me/profile-change-request', [
            'fields' => ['personalEmail' => ['from' => null, 'to' => 'new@example.com']],
        ]);
    $requestUuid = $submit->json('uuid');

    Auth::forgetGuards();

    $decide = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/v1/profile-change-requests/{$requestUuid}/decide", [
            'decision' => 'REJECTED',
            'rejectionReason' => 'Not now',
        ]);

    $decide->assertOk()
        ->assertJsonPath('status', 'REJECTED')
        ->assertJsonPath('rejectionReason', 'Not now');
    $this->assertDatabaseHas('employees', ['uuid' => $employee->uuid, 'personal_email' => null]);
});

it('lists profile-change requests filtered by status', function () {
    [, $adminToken] = employeesActingUser('ROLE_SUPER_ADMIN');

    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_uuid' => $employee->uuid]);
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/me/profile-change-request', [
            'fields' => ['mobilePhone' => ['from' => 'a', 'to' => 'b']],
        ])->assertStatus(201);

    Auth::forgetGuards();

    $pending = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/profile-change-requests?status=PENDING');
    $pending->assertOk();
    expect($pending->json())->toHaveCount(1);

    $approved = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/profile-change-requests?status=APPROVED');
    $approved->assertOk();
    expect($approved->json())->toHaveCount(0);
});

it('seeds the 5 POV personas with the exact mock uuids and links the admin employee', function () {
    $this->seed([AdminUserSeeder::class, UnitsSeeder::class, EmployeesSeeder::class]);

    $personas = [
        'HR_ADMIN' => ['00000000-0000-4000-8000-00000000a001', 'admin@devon.uz'],
        'RAHBAR' => ['00000000-0000-4000-8000-00000000a002', 'bekzod.karimov@devon.uz'],
        'BOLIM_BOSHLIGI' => ['00000000-0000-4000-8000-00000000a003', 'akmal.akhmedov@devon.uz'],
        'DEVONXONA' => ['00000000-0000-4000-8000-00000000a004', 'nilufar.yusupova@devon.uz'],
        'XODIM' => ['00000000-0000-4000-8000-00000000a005', 'dilnoza.sobirova@devon.uz'],
    ];

    foreach ($personas as $key => [$uuid, $email]) {
        $employee = Employee::where('uuid', $uuid)->first();
        expect($employee)->not->toBeNull("persona {$key} missing")
            ->and($employee->corporate_email)->toBe($email)
            ->and($employee->status)->toBe('ACTIVE');
    }

    $admin = User::where('email', 'admin@devon.uz')->first();
    expect($admin->employee_uuid)->toBe('00000000-0000-4000-8000-00000000a001')
        ->and(User::where('email', 'admin@devon.uz')->count())->toBe(1);
});
