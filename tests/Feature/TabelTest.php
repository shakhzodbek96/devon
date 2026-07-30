<?php

use App\Models\AttendanceRecord;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\Tabel;
use App\Models\TabelRegistryDispatch;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\PositionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Monthly timesheet approval chain — PLAN_tabel-davomat.md §1, §3
|--------------------------------------------------------------------------
|
| generate (idempotent) -> edit -> submit -> head reject -> re-edit ->
| re-submit -> head sign (ERI, shared signatures table) -> hr reject ->
| re-submit (head must re-decide, HR can't skip head) -> head sign -> hr
| accept -> registry dispatch only once EVERY active unit's tabel for the
| period is HR_ACCEPTED.
*/

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class, PositionsSeeder::class]);
});

/**
 * @return array{0: User, 1: string, 2: Employee}
 */
function tabelActingUser(string $role = 'ROLE_EMPLOYEE'): array
{
    // `Employee::factory()` defaults `primary_unit_uuid` to a freshly
    // created ACTIVE unit — that would silently inflate the "every ACTIVE
    // unit must be HR_ACCEPTED" registry-dispatch count in tests that
    // create several acting users. Park these test actors in an ARCHIVED
    // throwaway unit instead; the real fixture unit is created separately.
    $throwawayUnit = Unit::factory()->create(['status' => 'ARCHIVED']);
    $employee = Employee::factory()->create(['primary_unit_uuid' => $throwawayUnit->uuid]);
    $user = User::factory()->create(['employee_uuid' => $employee->uuid]);
    $employee->update(['user_uuid' => $user->uuid]);
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token, $employee->fresh()];
}

/** Same forgetGuards() workaround as TasksTest.php's asTask(). */
function asTabel(TestCase $test, string $token): TestCase
{
    app('auth')->forgetGuards();

    return $test->withHeader('Authorization', "Bearer {$token}");
}

function tabelUnit(?string $headEmployeeUuid = null): Unit
{
    return Unit::factory()->create([
        'level' => 0,
        'head_employee_uuid' => $headEmployeeUuid,
        'status' => 'ACTIVE',
    ]);
}

/**
 * Builds a unit headed by `$head`, with one member employee whose
 * `primary_unit_uuid` is that unit (drives `TabelService::generate`'s
 * employee roster).
 *
 * @return array{unit: Unit, head: array{0: User, 1: string, 2: Employee}, member: Employee}
 */
function tabelFixture(): array
{
    $head = tabelActingUser();
    $unit = tabelUnit($head[2]->uuid);
    $member = Employee::factory()->create(['primary_unit_uuid' => $unit->uuid, 'status' => 'ACTIVE']);

    return compact('unit', 'head', 'member');
}

it('generates a DRAFT tabel with entries for every day of the period, idempotently', function () {
    $f = tabelFixture();
    $period = Carbon::now()->addMonth()->format('Y-m');

    $response = asTabel($this, $f['head'][1])->postJson('/api/v1/tabels/generate', [
        'unitUuid' => $f['unit']->uuid,
        'period' => $period,
    ]);

    $response->assertStatus(201);
    $body = $response->json();
    expect($body['status'])->toBe('DRAFT')
        ->and($body['unitUuid'])->toBe($f['unit']->uuid)
        ->and($body['period'])->toBe($period);

    $daysInMonth = Carbon::createFromFormat('Y-m', $period)->daysInMonth;
    expect($body['entries'])->toHaveCount($daysInMonth);

    // Idempotent — calling generate again returns the SAME tabel, no
    // duplicate entries.
    $again = asTabel($this, $f['head'][1])->postJson('/api/v1/tabels/generate', [
        'unitUuid' => $f['unit']->uuid,
        'period' => $period,
    ])->assertStatus(201)->json();

    expect($again['uuid'])->toBe($body['uuid']);
    $this->assertDatabaseCount('tabels', 1);
    expect(\App\Models\TabelEntry::where('tabel_uuid', $body['uuid'])->count())->toBe($daysInMonth);
});

it('rejects generate by someone who neither heads the unit nor holds attendance.manage', function () {
    $f = tabelFixture();
    [, $bystanderToken] = tabelActingUser();
    $period = Carbon::now()->addMonth()->format('Y-m');

    asTabel($this, $bystanderToken)->postJson('/api/v1/tabels/generate', [
        'unitUuid' => $f['unit']->uuid,
        'period' => $period,
    ])->assertStatus(422)->assertJsonPath('code', 'not-authorized');
});

/** Drives a tabel through the full workflow up to (but not including) the final hr-decide. Returns [uuid, fixture]. */
function tabelReadyForHrDecision(TestCase $test, array $f, string $period): string
{
    $created = asTabel($test, $f['head'][1])->postJson('/api/v1/tabels/generate', [
        'unitUuid' => $f['unit']->uuid,
        'period' => $period,
    ])->assertStatus(201)->json();
    $uuid = $created['uuid'];

    // Edit while DRAFT.
    $entry = $created['entries'][0];
    asTabel($test, $f['head'][1])->patchJson("/api/v1/tabels/{$uuid}/entries", [
        'entries' => [
            ['employeeUuid' => $entry['employeeUuid'], 'workDate' => $entry['workDate'], 'status' => 'VACATION'],
        ],
    ])->assertOk();

    // submit -> SENT_TO_HEAD -> head rejects -> HEAD_REJECTED.
    asTabel($test, $f['head'][1])->postJson("/api/v1/tabels/{$uuid}/submit")->assertOk()
        ->assertJsonPath('status', 'SENT_TO_HEAD');
    asTabel($test, $f['head'][1])->postJson("/api/v1/tabels/{$uuid}/head-decide", [
        'approve' => false,
        'note' => 'Notoʻgʻri toʻldirilgan',
    ])->assertOk()->assertJsonPath('status', 'HEAD_REJECTED');

    // Editable again while HEAD_REJECTED.
    asTabel($test, $f['head'][1])->patchJson("/api/v1/tabels/{$uuid}/entries", [
        'entries' => [
            ['employeeUuid' => $entry['employeeUuid'], 'workDate' => $entry['workDate'], 'status' => 'OK'],
        ],
    ])->assertOk();

    // re-submit -> SENT_TO_HEAD -> head signs (ERI) -> SENT_TO_HR.
    asTabel($test, $f['head'][1])->postJson("/api/v1/tabels/{$uuid}/submit")->assertOk();
    $cert = Certificate::factory()->create(['employee_uuid' => $f['head'][2]->uuid, 'status' => 'ACTIVE']);
    asTabel($test, $f['head'][1])->postJson("/api/v1/tabels/{$uuid}/head-decide", [
        'approve' => true,
        'certificateUuid' => $cert->uuid,
        'signatureHex' => bin2hex(random_bytes(16)),
    ])->assertOk()->assertJsonPath('status', 'SENT_TO_HR');

    return $uuid;
}

it('runs the full approval chain: edit -> submit -> head reject -> re-edit -> re-submit -> head sign -> hr reject -> re-submit -> head must re-sign -> hr accept', function () {
    $f = tabelFixture();
    $period = Carbon::now()->addMonth()->format('Y-m');

    $uuid = tabelReadyForHrDecision($this, $f, $period);

    $this->assertDatabaseHas('signatures', [
        'resource_type' => 'tabel',
        'resource_uuid' => $uuid,
        'employee_uuid' => $f['head'][2]->uuid,
    ]);

    [, $hrToken] = tabelActingUser('ROLE_HR_ADMIN');

    // HR rejects.
    asTabel($this, $hrToken)->postJson("/api/v1/tabels/{$uuid}/hr-decide", [
        'approve' => false,
        'note' => 'Yana tekshiring',
    ])->assertOk()->assertJsonPath('status', 'HR_REJECTED');

    // Calling hr-decide again directly (without going back through the
    // head) fails — the tabel is HR_REJECTED, not SENT_TO_HR.
    asTabel($this, $hrToken)->postJson("/api/v1/tabels/{$uuid}/hr-decide", ['approve' => true])
        ->assertStatus(422)->assertJsonPath('code', 'wrong-status');

    // Editable again while HR_REJECTED -> re-submit -> back to the HEAD,
    // who must sign again (HR cannot bypass the head).
    asTabel($this, $f['head'][1])->postJson("/api/v1/tabels/{$uuid}/submit")->assertOk()
        ->assertJsonPath('status', 'SENT_TO_HEAD');

    asTabel($this, $hrToken)->postJson("/api/v1/tabels/{$uuid}/hr-decide", ['approve' => true])
        ->assertStatus(422)->assertJsonPath('code', 'wrong-status');

    $cert = Certificate::factory()->create(['employee_uuid' => $f['head'][2]->uuid, 'status' => 'ACTIVE']);
    asTabel($this, $f['head'][1])->postJson("/api/v1/tabels/{$uuid}/head-decide", [
        'approve' => true,
        'certificateUuid' => $cert->uuid,
        'signatureHex' => bin2hex(random_bytes(16)),
    ])->assertOk()->assertJsonPath('status', 'SENT_TO_HR');

    // HR finally accepts.
    asTabel($this, $hrToken)->postJson("/api/v1/tabels/{$uuid}/hr-decide", ['approve' => true])
        ->assertOk()->assertJsonPath('status', 'HR_ACCEPTED');

    $this->assertDatabaseHas('tabels', ['uuid' => $uuid, 'status' => 'HR_ACCEPTED']);
});

it('rejects head-decide by someone other than the unit\'s own head with code not-rahbar', function () {
    $f = tabelFixture();
    $period = Carbon::now()->addMonth()->format('Y-m');
    $created = asTabel($this, $f['head'][1])->postJson('/api/v1/tabels/generate', [
        'unitUuid' => $f['unit']->uuid,
        'period' => $period,
    ])->assertStatus(201)->json();
    asTabel($this, $f['head'][1])->postJson("/api/v1/tabels/{$created['uuid']}/submit")->assertOk();

    [, $bystanderToken] = tabelActingUser();
    asTabel($this, $bystanderToken)->postJson("/api/v1/tabels/{$created['uuid']}/head-decide", [
        'approve' => false,
        'note' => 'x',
    ])->assertStatus(422)->assertJsonPath('code', 'not-rahbar');
});

it('rejects head-decide approve with no active certificate owned by the head with code cert-invalid', function () {
    $f = tabelFixture();
    $period = Carbon::now()->addMonth()->format('Y-m');
    $created = asTabel($this, $f['head'][1])->postJson('/api/v1/tabels/generate', [
        'unitUuid' => $f['unit']->uuid,
        'period' => $period,
    ])->assertStatus(201)->json();
    asTabel($this, $f['head'][1])->postJson("/api/v1/tabels/{$created['uuid']}/submit")->assertOk();

    asTabel($this, $f['head'][1])->postJson("/api/v1/tabels/{$created['uuid']}/head-decide", [
        'approve' => true,
        'certificateUuid' => (string) Str::uuid(),
        'signatureHex' => bin2hex(random_bytes(16)),
    ])->assertStatus(422)->assertJsonPath('code', 'cert-invalid');
});

it('rejects editing entries once the tabel has left the editable statuses', function () {
    $f = tabelFixture();
    $period = Carbon::now()->addMonth()->format('Y-m');
    $created = asTabel($this, $f['head'][1])->postJson('/api/v1/tabels/generate', [
        'unitUuid' => $f['unit']->uuid,
        'period' => $period,
    ])->assertStatus(201)->json();
    asTabel($this, $f['head'][1])->postJson("/api/v1/tabels/{$created['uuid']}/submit")->assertOk();

    $entry = $created['entries'][0];
    asTabel($this, $f['head'][1])->patchJson("/api/v1/tabels/{$created['uuid']}/entries", [
        'entries' => [
            ['employeeUuid' => $entry['employeeUuid'], 'workDate' => $entry['workDate'], 'status' => 'SICK_LEAVE'],
        ],
    ])->assertStatus(422)->assertJsonPath('code', 'not-editable');
});

it('dispatches the registry ONLY once every ACTIVE unit\'s tabel for the period is HR_ACCEPTED', function () {
    $period = Carbon::now()->addMonth()->format('Y-m');
    $fixtureA = tabelFixture();
    $fixtureB = tabelFixture();

    [, $hrToken] = tabelActingUser('ROLE_HR_ADMIN');

    $uuidA = tabelReadyForHrDecision($this, $fixtureA, $period);
    asTabel($this, $hrToken)->postJson("/api/v1/tabels/{$uuidA}/hr-decide", ['approve' => true])
        ->assertOk()->assertJsonPath('status', 'HR_ACCEPTED');

    // Only unit A is HR_ACCEPTED — unit B has no tabel at all yet for this
    // period — no dispatch.
    expect(TabelRegistryDispatch::where('period', $period)->exists())->toBeFalse();

    $uuidB = tabelReadyForHrDecision($this, $fixtureB, $period);
    asTabel($this, $hrToken)->postJson("/api/v1/tabels/{$uuidB}/hr-decide", ['approve' => true])
        ->assertOk()->assertJsonPath('status', 'HR_ACCEPTED');

    // Now EVERY active unit (A and B) has an HR_ACCEPTED tabel for the
    // period — dispatch fires.
    $this->assertDatabaseHas('tabel_registry_dispatches', ['period' => $period]);

    $registry = asTabel($this, $hrToken)->getJson("/api/v1/tabel-registry/{$period}")
        ->assertOk()->json();
    expect($registry['dispatched'])->toBeTrue()
        ->and($registry['units'])->toHaveCount(2);
});

it('gates the tabel-registry endpoint behind attendance.view-registry', function () {
    $period = Carbon::now()->format('Y-m');
    [, $employeeToken] = tabelActingUser();

    asTabel($this, $employeeToken)->getJson("/api/v1/tabel-registry/{$period}")->assertStatus(403);

    [, $hrToken] = tabelActingUser('ROLE_HR_ADMIN');
    asTabel($this, $hrToken)->getJson("/api/v1/tabel-registry/{$period}")->assertOk();
});

it('gates every tabel route behind authentication', function () {
    $this->getJson('/api/v1/tabels')->assertStatus(401);
    $this->postJson('/api/v1/tabels/generate')->assertStatus(401);
});

it('generates the requested month even when today is a day the target month does not have', function () {
    $this->travelTo(Carbon::parse('2026-01-31 09:00:00'));

    $f = tabelFixture();
    $response = asTabel($this, $f['head'][1])->postJson('/api/v1/tabels/generate', [
        'unitUuid' => $f['unit']->uuid,
        'period' => '2026-02',
    ])->assertStatus(201);

    $dates = collect($response->json('entries'))->pluck('workDate');

    expect($dates)->toHaveCount(28)
        ->and($dates->min())->toBe('2026-02-01')
        ->and($dates->max())->toBe('2026-02-28');

    $this->travelBack();
});

it('returns the requested month of personal attendance even from a 31st', function () {
    [, $token, $employee] = tabelActingUser();

    $this->travelTo(Carbon::parse('2026-03-31 09:00:00'));

    AttendanceRecord::create([
        'uuid' => (string) Str::uuid(),
        'employee_uuid' => $employee->uuid,
        'work_date' => '2026-02-10',
        'check_in_at' => now(),
        'status' => 'OK',
        'network_ok' => true,
        'source' => 'SELF_CHECKIN',
    ]);

    $response = asTabel($this, $token)->getJson('/api/v1/attendance/me?month=2026-02')->assertOk();
    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.workDate'))->toBe('2026-02-10');

    // A garbage month must not 500.
    asTabel($this, $token)->getJson('/api/v1/attendance/me?month=nonsense')->assertOk();

    $this->travelBack();
});
