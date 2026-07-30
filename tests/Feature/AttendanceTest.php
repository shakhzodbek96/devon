<?php

use App\Models\Employee;
use App\Models\OfficeNetwork;
use App\Models\User;
use App\Models\WorkShift;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\PositionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Daily check-in/check-out — PLAN_tabel-davomat.md §0, §2, §3
|--------------------------------------------------------------------------
|
| Wi-Fi perimeter check (real CIDR match), late/early-exit calculation
| against a WorkShift, second-attempt-== auto-checkout, and rejection of a
| check-in against an already-closed day.
*/

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class, PositionsSeeder::class]);
    Carbon::setTestNow(null);
});

afterEach(function () {
    Carbon::setTestNow(null);
});

/**
 * @return array{0: User, 1: string, 2: Employee}
 */
function attendanceActingUser(string $role = 'ROLE_EMPLOYEE'): array
{
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_uuid' => $employee->uuid]);
    $employee->update(['user_uuid' => $user->uuid]);
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token, $employee->fresh()];
}

/** Same forgetGuards() workaround as TasksTest.php's asTask(). */
function asAttendance(TestCase $test, string $token): TestCase
{
    app('auth')->forgetGuards();

    return $test->withHeader('Authorization', "Bearer {$token}");
}

function globalOfficeNetwork(): OfficeNetwork
{
    return OfficeNetwork::factory()->create(['cidr' => '0.0.0.0/0', 'unit_uuid' => null, 'status' => 'ACTIVE']);
}

function globalWorkShift(): WorkShift
{
    return WorkShift::factory()->create([
        'unit_uuid' => null,
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'late_grace_min' => 10,
        'early_exit_grace_min' => 15,
    ]);
}

it('requires a linked employee to check in', function () {
    $user = User::factory()->create(['employee_uuid' => null]);
    $user->assignRole('ROLE_EMPLOYEE');
    $token = $user->createToken('test')->plainTextToken;

    asAttendance($this, $token)->postJson('/api/v1/attendance/check-in')->assertStatus(422);
});

it('checks in on time, matching the office network, with status OK', function () {
    globalOfficeNetwork();
    globalWorkShift();
    Carbon::setTestNow(Carbon::today()->setTime(9, 5));
    [, $token, $employee] = attendanceActingUser();

    $response = asAttendance($this, $token)->postJson('/api/v1/attendance/check-in');

    $response->assertStatus(201);
    $body = $response->json();
    expect($body['employeeUuid'])->toBe($employee->uuid)
        ->and($body['status'])->toBe('OK')
        ->and($body['networkOk'])->toBeTrue()
        ->and($body['checkOutAt'])->toBeNull();

    $this->assertDatabaseHas('attendance_records', [
        'employee_uuid' => $employee->uuid,
        'status' => 'OK',
        'source' => 'SELF_CHECKIN',
    ]);
});

it('marks LATE and computes lateMinutes when checking in after the grace period', function () {
    globalOfficeNetwork();
    globalWorkShift();
    Carbon::setTestNow(Carbon::today()->setTime(9, 25));
    [, $token] = attendanceActingUser();

    $body = asAttendance($this, $token)->postJson('/api/v1/attendance/check-in')
        ->assertStatus(201)->json();

    expect($body['status'])->toBe('LATE')
        ->and($body['lateMinutes'])->toBe(25);
});

it('rejects a check-in with no matching office network with code network-blocked', function () {
    // No OfficeNetwork rows at all — nothing matches.
    globalWorkShift();
    [, $token] = attendanceActingUser();

    asAttendance($this, $token)->postJson('/api/v1/attendance/check-in')
        ->assertStatus(422)->assertJsonPath('code', 'network-blocked');

    $this->assertDatabaseCount('attendance_records', 0);
});

it('treats a second check-in on an open record as an automatic check-out', function () {
    globalOfficeNetwork();
    globalWorkShift();
    Carbon::setTestNow(Carbon::today()->setTime(9, 0));
    [, $token, $employee] = attendanceActingUser();

    asAttendance($this, $token)->postJson('/api/v1/attendance/check-in')->assertStatus(201);

    Carbon::setTestNow(Carbon::today()->setTime(17, 0));
    $second = asAttendance($this, $token)->postJson('/api/v1/attendance/check-in')
        ->assertStatus(201)->json();

    expect($second['checkOutAt'])->not->toBeNull();
    $this->assertDatabaseHas('attendance_records', [
        'employee_uuid' => $employee->uuid,
        'work_date' => Carbon::today()->format('Y-m-d'),
    ]);
    expect(\App\Models\AttendanceRecord::where('employee_uuid', $employee->uuid)->count())->toBe(1);
});

it('marks EARLY_EXIT and computes earlyExitMinutes when checking out before the grace window', function () {
    globalOfficeNetwork();
    globalWorkShift();
    Carbon::setTestNow(Carbon::today()->setTime(9, 0));
    [, $token] = attendanceActingUser();
    asAttendance($this, $token)->postJson('/api/v1/attendance/check-in')->assertStatus(201);

    Carbon::setTestNow(Carbon::today()->setTime(17, 30));
    $body = asAttendance($this, $token)->postJson('/api/v1/attendance/check-out')
        ->assertStatus(200)->json();

    expect($body['status'])->toBe('EARLY_EXIT')
        ->and($body['earlyExitMinutes'])->toBe(30);
});

it('rejects a check-in attempt on an already-closed day with code already-closed', function () {
    globalOfficeNetwork();
    globalWorkShift();
    Carbon::setTestNow(Carbon::today()->setTime(9, 0));
    [, $token] = attendanceActingUser();
    asAttendance($this, $token)->postJson('/api/v1/attendance/check-in')->assertStatus(201);
    asAttendance($this, $token)->postJson('/api/v1/attendance/check-out')->assertStatus(200);

    asAttendance($this, $token)->postJson('/api/v1/attendance/check-in')
        ->assertStatus(422)->assertJsonPath('code', 'already-closed');
});

it('rejects a check-out with no active record with code already-checked-in', function () {
    [, $token] = attendanceActingUser();

    asAttendance($this, $token)->postJson('/api/v1/attendance/check-out')
        ->assertStatus(422)->assertJsonPath('code', 'already-checked-in');
});

it('lets an attendance.manage holder create a manual HR entry', function () {
    [, $hrToken] = attendanceActingUser('ROLE_HR_ADMIN');
    [, , $employee] = attendanceActingUser();

    $response = asAttendance($this, $hrToken)->postJson('/api/v1/attendance/manual', [
        'employeeUuid' => $employee->uuid,
        'workDate' => Carbon::today()->format('Y-m-d'),
        'status' => 'MANUAL',
        'note' => 'Komandirovka o\'rniga qo\'lda tuzatildi',
    ]);

    $response->assertStatus(201)->assertJsonPath('source', 'HR_MANUAL');
    $this->assertDatabaseHas('attendance_records', [
        'employee_uuid' => $employee->uuid,
        'source' => 'HR_MANUAL',
        'status' => 'MANUAL',
    ]);
});

it('forbids a plain employee from creating a manual HR entry (403)', function () {
    [, $token] = attendanceActingUser();

    asAttendance($this, $token)->postJson('/api/v1/attendance/manual', [
        'employeeUuid' => (string) Illuminate\Support\Str::uuid(),
        'workDate' => Carbon::today()->format('Y-m-d'),
        'status' => 'MANUAL',
    ])->assertStatus(403);
});

it('returns the acting employee\'s own monthly history', function () {
    globalOfficeNetwork();
    globalWorkShift();
    Carbon::setTestNow(Carbon::today()->setTime(9, 0));
    [, $token, $employee] = attendanceActingUser();
    asAttendance($this, $token)->postJson('/api/v1/attendance/check-in')->assertStatus(201);

    $month = Carbon::today()->format('Y-m');
    $body = asAttendance($this, $token)->getJson("/api/v1/attendance/me?month={$month}")
        ->assertOk()->json();

    expect($body)->toHaveCount(1)
        ->and($body[0]['employeeUuid'])->toBe($employee->uuid);
});

it('lets an attendance.view holder list records filtered by unit and date', function () {
    globalOfficeNetwork();
    globalWorkShift();
    Carbon::setTestNow(Carbon::today()->setTime(9, 0));
    [, $token, $employee] = attendanceActingUser();
    asAttendance($this, $token)->postJson('/api/v1/attendance/check-in')->assertStatus(201);

    [, $hrToken] = attendanceActingUser('ROLE_HR_ADMIN');
    $date = Carbon::today()->format('Y-m-d');

    $byUnit = asAttendance($this, $hrToken)->getJson("/api/v1/attendance?unitUuid={$employee->primary_unit_uuid}&date={$date}")
        ->assertOk()->json();

    expect($byUnit)->toHaveCount(1)
        ->and($byUnit[0]['employeeUuid'])->toBe($employee->uuid);
});

it('gates attendance monitoring routes behind authentication', function () {
    $this->getJson('/api/v1/attendance')->assertStatus(401);
    $this->postJson('/api/v1/attendance/check-in')->assertStatus(401);
});
