<?php

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\Letter;
use App\Models\Notification;
use App\Models\Signature;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\PositionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Incoming/outgoing correspondence — PLAN_PHASE_G.md §2, §6 (BPMN 3.3 / BP-3)
|--------------------------------------------------------------------------
|
| Ports the frontend mock's letter test surface: the full BP-3 lifecycle
| (register -> route -> assign -> start -> execute -> accept -> sign ->
| dispatch), the comment-only -> CLOSED_NO_RESPONSE branch, the
| response-file -> ON_SIGNATURE/RESPONDED branches, dispatch creating an
| OUTGOING reply + closing the incoming letter, letter signing writing to
| the SHARED `signatures` table (resource_type='letter'), per-station
| authorization (Devonxona/Rahbar/unit-head/executor), overdue logic,
| notification fan-out, auto-numbering, and RBAC (auth gate).
*/

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class, PositionsSeeder::class]);
});

/**
 * @return array{0: User, 1: string, 2: Employee}
 */
function letterActingUser(string $role = 'ROLE_EMPLOYEE'): array
{
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_uuid' => $employee->uuid]);
    $employee->update(['user_uuid' => $user->uuid]);
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token, $employee->fresh()];
}

/** Same forgetGuards() workaround as DocumentsTest.php's asDoc(). */
function asLet(TestCase $test, string $token): TestCase
{
    app('auth')->forgetGuards();

    return $test->withHeader('Authorization', "Bearer {$token}");
}

function letterRootUnit(?string $headEmployeeUuid = null): Unit
{
    return Unit::factory()->create([
        'level' => 0,
        'head_employee_uuid' => $headEmployeeUuid,
        'status' => 'ACTIVE',
    ]);
}

function letterChildUnit(Unit $parent, ?string $headEmployeeUuid = null): Unit
{
    $uuid = (string) Str::uuid();

    return Unit::create([
        'uuid' => $uuid,
        'name_uz' => 'Bo\'lim '.substr($uuid, 0, 8),
        'code' => 'UNIT-'.strtoupper(Str::random(8)),
        'type' => 'DIVISION',
        'parent_uuid' => $parent->uuid,
        'level' => $parent->level + 1,
        'path' => $parent->path.$uuid.'/',
        'head_employee_uuid' => $headEmployeeUuid,
        'status' => 'ACTIVE',
    ]);
}

/**
 * Builds the standard BP-3 swim-lane fixture: a root unit headed by the
 * Rahbar, a child unit headed by the unit-head (bo'lim boshlig'i), and an
 * executor employee who is a member of the child unit (via
 * `primary_unit_uuid`, satisfying the assign-time subtree-membership
 * check).
 *
 * @return array{
 *   devonxona: array{0: User, 1: string, 2: Employee},
 *   rahbar: array{0: User, 1: string, 2: Employee},
 *   unitHead: array{0: User, 1: string, 2: Employee},
 *   executor: array{0: User, 1: string, 2: Employee},
 *   root: Unit,
 *   child: Unit,
 * }
 */
function letterFixture(): array
{
    $devonxona = letterActingUser('ROLE_DEVONXONA');
    $rahbar = letterActingUser();
    $unitHead = letterActingUser();
    $executorEmployee = Employee::factory()->create();

    $root = letterRootUnit($rahbar[2]->uuid);
    $child = letterChildUnit($root, $unitHead[2]->uuid);

    $executorEmployee->update(['primary_unit_uuid' => $child->uuid]);
    $executorUser = User::factory()->create(['employee_uuid' => $executorEmployee->uuid]);
    $executorEmployee->update(['user_uuid' => $executorUser->uuid]);
    $executorUser->assignRole('ROLE_EMPLOYEE');
    $executorToken = $executorUser->createToken('test')->plainTextToken;
    $executor = [$executorUser, $executorToken, $executorEmployee->fresh()];

    return compact('devonxona', 'rahbar', 'unitHead', 'executor', 'root', 'child');
}

/**
 * Registers an incoming letter and drives it through route -> assign
 * (stopping there, ASSIGNED) using the standard fixture. Returns the
 * letter uuid + fixture.
 */
function registeredRoutedAssignedLetter(TestCase $test, bool $requiresSignature = false): array
{
    $f = letterFixture();

    $reg = asLet($test, $f['devonxona'][1])->postJson('/api/v1/letters', [
        'externalOrg' => 'Toshkent shahar hokimligi',
        'subject' => "Axborot tizimlari integratsiyasi to'g'risida so'rov",
        'channel' => 'POCHTA',
        'receivedAt' => now()->subDays(2)->format('Y-m-d'),
        'requiresSignature' => $requiresSignature,
        'fileMeta' => ['fileName' => 'skan.pdf', 'fileSize' => 1024, 'mimeType' => 'application/pdf'],
    ])->assertCreated()->json();

    asLet($test, $f['rahbar'][1])->postJson("/api/v1/letters/{$reg['uuid']}/route", [
        'unitUuid' => $f['child']->uuid,
    ])->assertOk();

    asLet($test, $f['unitHead'][1])->postJson("/api/v1/letters/{$reg['uuid']}/assign", [
        'employeeUuid' => $f['executor'][2]->uuid,
    ])->assertOk();

    return [$reg['uuid'], $f];
}

// === register ===

it('registers an INCOMING letter with auto-numbered K-2026/NNNN, Devonxona-only', function () {
    [$devonxona, $token] = letterActingUser('ROLE_DEVONXONA');

    $response = asLet($this, $token)->postJson('/api/v1/letters', [
        'externalOrg' => "O'zstandart agentligi",
        'subject' => "Ma'lumotlar almashinuvi reglamenti loyihasi",
        'channel' => 'EMAIL',
        'receivedAt' => now()->subDay()->format('Y-m-d'),
        'deadline' => now()->addDays(5)->format('Y-m-d'),
        'requiresSignature' => true,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('direction', 'INCOMING');
    $response->assertJsonPath('number', 'K-2026/0001');
    $response->assertJsonPath('status', 'REGISTERED');

    expect(Letter::count())->toBe(1);
    expect(AuditLog::where('action', 'LETTER_REGISTERED')->exists())->toBeTrue();
    // Registration is Devonxona's own act — audit-only, no notification yet.
    expect(Notification::count())->toBe(0);
});

it('auto-numbers letters sequentially as K-2026/NNNN', function () {
    [, $token] = letterActingUser('ROLE_DEVONXONA');

    for ($i = 0; $i < 2; $i++) {
        asLet($this, $token)->postJson('/api/v1/letters', [
            'externalOrg' => "Tashkilot {$i}",
            'subject' => "Mavzu {$i}",
            'channel' => 'EMAIL',
            'receivedAt' => now()->format('Y-m-d'),
            'requiresSignature' => false,
        ])->assertCreated();
    }

    expect(Letter::where('direction', 'INCOMING')->pluck('number')->sort()->values()->all())
        ->toBe(['K-2026/0001', 'K-2026/0002']);
});

it('rejects registration by a non-Devonxona actor', function () {
    [, $token] = letterActingUser();

    asLet($this, $token)->postJson('/api/v1/letters', [
        'externalOrg' => 'X', 'subject' => 'Y', 'channel' => 'EMAIL',
        'receivedAt' => now()->format('Y-m-d'), 'requiresSignature' => false,
    ])->assertStatus(422)->assertJsonPath('code', 'not-devonxona');
});

// === route ===

it('routes a REGISTERED letter to a unit, notifying the unit head', function () {
    $f = letterFixture();
    $reg = asLet($this, $f['devonxona'][1])->postJson('/api/v1/letters', [
        'externalOrg' => 'X', 'subject' => 'Y', 'channel' => 'EMAIL',
        'receivedAt' => now()->format('Y-m-d'), 'requiresSignature' => false,
    ])->json();

    $response = asLet($this, $f['rahbar'][1])->postJson("/api/v1/letters/{$reg['uuid']}/route", [
        'unitUuid' => $f['child']->uuid,
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'ROUTED');
    $response->assertJsonPath('routedToUnitUuid', $f['child']->uuid);

    expect(Notification::where('recipient_employee_uuid', $f['unitHead'][2]->uuid)
        ->where('type', 'LETTER_ROUTED')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'LETTER_ROUTED')->where('resource_uuid', $reg['uuid'])->first()?->context)
        ->toBe(['unit' => $f['child']->name_uz]);
});

it('rejects routing by a non-Rahbar actor', function () {
    $f = letterFixture();
    $reg = asLet($this, $f['devonxona'][1])->postJson('/api/v1/letters', [
        'externalOrg' => 'X', 'subject' => 'Y', 'channel' => 'EMAIL',
        'receivedAt' => now()->format('Y-m-d'), 'requiresSignature' => false,
    ])->json();

    asLet($this, $f['unitHead'][1])->postJson("/api/v1/letters/{$reg['uuid']}/route", [
        'unitUuid' => $f['child']->uuid,
    ])->assertStatus(422)->assertJsonPath('code', 'not-rahbar');
});

it('rejects routing a letter that is not REGISTERED', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this);

    asLet($this, $f['rahbar'][1])->postJson("/api/v1/letters/{$uuid}/route", [
        'unitUuid' => $f['child']->uuid,
    ])->assertStatus(422)->assertJsonPath('code', 'wrong-status');
});

// === assign ===

it('assigns a ROUTED letter to an executor within the routed unit subtree, notifying them', function () {
    $f = letterFixture();
    $reg = asLet($this, $f['devonxona'][1])->postJson('/api/v1/letters', [
        'externalOrg' => 'X', 'subject' => 'Y', 'channel' => 'EMAIL',
        'receivedAt' => now()->format('Y-m-d'), 'requiresSignature' => false,
    ])->json();
    asLet($this, $f['rahbar'][1])->postJson("/api/v1/letters/{$reg['uuid']}/route", ['unitUuid' => $f['child']->uuid])->assertOk();

    $response = asLet($this, $f['unitHead'][1])->postJson("/api/v1/letters/{$reg['uuid']}/assign", [
        'employeeUuid' => $f['executor'][2]->uuid,
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'ASSIGNED');
    $response->assertJsonPath('assignedEmployeeUuid', $f['executor'][2]->uuid);
    expect(Notification::where('recipient_employee_uuid', $f['executor'][2]->uuid)
        ->where('type', 'LETTER_ASSIGNED')->exists())->toBeTrue();
});

it('rejects assignment by someone who does not head the routed unit or an ancestor', function () {
    $f = letterFixture();
    $reg = asLet($this, $f['devonxona'][1])->postJson('/api/v1/letters', [
        'externalOrg' => 'X', 'subject' => 'Y', 'channel' => 'EMAIL',
        'receivedAt' => now()->format('Y-m-d'), 'requiresSignature' => false,
    ])->json();
    asLet($this, $f['rahbar'][1])->postJson("/api/v1/letters/{$reg['uuid']}/route", ['unitUuid' => $f['child']->uuid])->assertOk();

    asLet($this, $f['executor'][1])->postJson("/api/v1/letters/{$reg['uuid']}/assign", [
        'employeeUuid' => $f['executor'][2]->uuid,
    ])->assertStatus(422)->assertJsonPath('code', 'not-unit-head');
});

it('allows the Rahbar (ancestor head) to assign, not only the direct unit head', function () {
    $f = letterFixture();
    $reg = asLet($this, $f['devonxona'][1])->postJson('/api/v1/letters', [
        'externalOrg' => 'X', 'subject' => 'Y', 'channel' => 'EMAIL',
        'receivedAt' => now()->format('Y-m-d'), 'requiresSignature' => false,
    ])->json();
    asLet($this, $f['rahbar'][1])->postJson("/api/v1/letters/{$reg['uuid']}/route", ['unitUuid' => $f['child']->uuid])->assertOk();

    asLet($this, $f['rahbar'][1])->postJson("/api/v1/letters/{$reg['uuid']}/assign", [
        'employeeUuid' => $f['executor'][2]->uuid,
    ])->assertOk()->assertJsonPath('status', 'ASSIGNED');
});

// === start / execute ===

it('starts execution (ASSIGNED -> IN_PROGRESS) only by the assigned executor', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this);

    asLet($this, $f['unitHead'][1])->postJson("/api/v1/letters/{$uuid}/start")
        ->assertStatus(422)->assertJsonPath('code', 'not-executor');

    asLet($this, $f['executor'][1])->postJson("/api/v1/letters/{$uuid}/start")
        ->assertOk()->assertJsonPath('status', 'IN_PROGRESS');

    expect(AuditLog::where('action', 'LETTER_EXECUTED')->where('resource_uuid', $uuid)
        ->where('context->phase', 'started')->exists())->toBeTrue();
});

it('submits a response-file execution (BPMN 7.2), notifying the unit head', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this);

    $response = asLet($this, $f['executor'][1])->postJson("/api/v1/letters/{$uuid}/execute", [
        'responseFileMeta' => ['fileName' => 'javob.pdf', 'fileSize' => 2048, 'mimeType' => 'application/pdf'],
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'EXECUTED');
    expect($response->json('responseFileMeta.fileName'))->toBe('javob.pdf');
    expect(Notification::where('recipient_employee_uuid', $f['unitHead'][2]->uuid)
        ->where('type', 'LETTER_EXECUTED')->exists())->toBeTrue();
});

it('submits a comment-only execution (BPMN 7.1)', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this);

    $response = asLet($this, $f['executor'][1])->postJson("/api/v1/letters/{$uuid}/execute", [
        'executionComment' => "Ma'lumot uchun qabul qilindi.",
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'EXECUTED');
    $response->assertJsonPath('executionComment', "Ma'lumot uchun qabul qilindi.");
});

it('rejects a comment-only execution with an empty/blank comment', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this);

    asLet($this, $f['executor'][1])->postJson("/api/v1/letters/{$uuid}/execute", [
        'executionComment' => '   ',
    ])->assertStatus(422)->assertJsonPath('code', 'comment-required');
});

it('rejects an execution submission with neither a comment nor a response', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this);

    asLet($this, $f['executor'][1])->postJson("/api/v1/letters/{$uuid}/execute", [])
        ->assertStatus(422)->assertJsonPath('code', 'missing-response');
});

it('rejects execution submission by someone other than the assigned executor', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this);

    asLet($this, $f['unitHead'][1])->postJson("/api/v1/letters/{$uuid}/execute", [
        'executionComment' => 'x',
    ])->assertStatus(422)->assertJsonPath('code', 'not-executor');
});

// === accept ===

it('accepts a comment-only execution, closing the letter as CLOSED_NO_RESPONSE', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this, requiresSignature: true);
    asLet($this, $f['executor'][1])->postJson("/api/v1/letters/{$uuid}/execute", [
        'executionComment' => 'Xabardor qilindi.',
    ])->assertOk();

    $response = asLet($this, $f['unitHead'][1])->postJson("/api/v1/letters/{$uuid}/accept");

    $response->assertOk();
    $response->assertJsonPath('status', 'CLOSED_NO_RESPONSE');
    expect($response->json('closedAt'))->not->toBeNull();
    expect(Notification::where('recipient_employee_uuid', $f['executor'][2]->uuid)
        ->where('type', 'LETTER_ACCEPTED')->exists())->toBeTrue();
    expect(Notification::where('recipient_employee_uuid', $f['devonxona'][2]->uuid)
        ->where('type', 'LETTER_ACCEPTED')->exists())->toBeTrue();
});

it('accepts a response with requiresSignature, moving to ON_SIGNATURE and notifying the Rahbar', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this, requiresSignature: true);
    asLet($this, $f['executor'][1])->postJson("/api/v1/letters/{$uuid}/execute", [
        'responseFileMeta' => ['fileName' => 'javob.pdf', 'fileSize' => 100, 'mimeType' => 'application/pdf'],
    ])->assertOk();

    $response = asLet($this, $f['unitHead'][1])->postJson("/api/v1/letters/{$uuid}/accept");

    $response->assertOk();
    $response->assertJsonPath('status', 'ON_SIGNATURE');
    expect(Notification::where('recipient_employee_uuid', $f['rahbar'][2]->uuid)
        ->where('type', 'LETTER_SIGN_REQUESTED')->exists())->toBeTrue();
});

it('accepts a response without requiresSignature, moving straight to RESPONDED and notifying Devonxona', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this, requiresSignature: false);
    asLet($this, $f['executor'][1])->postJson("/api/v1/letters/{$uuid}/execute", [
        'responseFileMeta' => ['fileName' => 'javob.pdf', 'fileSize' => 100, 'mimeType' => 'application/pdf'],
    ])->assertOk();

    $response = asLet($this, $f['unitHead'][1])->postJson("/api/v1/letters/{$uuid}/accept");

    $response->assertOk();
    $response->assertJsonPath('status', 'RESPONDED');
    expect(Notification::where('recipient_employee_uuid', $f['devonxona'][2]->uuid)
        ->where('type', 'LETTER_ACCEPTED')->exists())->toBeTrue();
});

it('rejects acceptance by someone who is not the unit head or an ancestor head', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this);
    asLet($this, $f['executor'][1])->postJson("/api/v1/letters/{$uuid}/execute", [
        'executionComment' => 'x',
    ])->assertOk();

    asLet($this, $f['executor'][1])->postJson("/api/v1/letters/{$uuid}/accept")
        ->assertStatus(422)->assertJsonPath('code', 'not-unit-head');
});

// === overdue / listing ===

it('flags a letter overdue only when the deadline has passed and it is not terminal', function () {
    [, $token] = letterActingUser('ROLE_DEVONXONA');
    $past = asLet($this, $token)->postJson('/api/v1/letters', [
        'externalOrg' => 'X', 'subject' => 'Overdue', 'channel' => 'EMAIL',
        'receivedAt' => now()->subDays(5)->format('Y-m-d'),
        'deadline' => now()->subDay()->format('Y-m-d'),
        'requiresSignature' => false,
    ])->json();
    asLet($this, $token)->postJson('/api/v1/letters', [
        'externalOrg' => 'X', 'subject' => 'Not overdue', 'channel' => 'EMAIL',
        'receivedAt' => now()->format('Y-m-d'),
        'deadline' => now()->addDays(5)->format('Y-m-d'),
        'requiresSignature' => false,
    ])->assertCreated();

    $response = asLet($this, $token)->getJson('/api/v1/letters?overdueOnly=1');
    $response->assertOk();
    expect(collect($response->json())->pluck('uuid')->all())->toBe([$past['uuid']]);
});

it('lists and filters letters by direction/status/search', function () {
    [, $token] = letterActingUser('ROLE_DEVONXONA');
    asLet($this, $token)->postJson('/api/v1/letters', [
        'externalOrg' => 'Markaziy bank', 'subject' => 'Hamkorlik', 'channel' => 'EMAIL',
        'receivedAt' => now()->format('Y-m-d'), 'requiresSignature' => false,
    ])->assertCreated();
    asLet($this, $token)->postJson('/api/v1/letters', [
        'externalOrg' => 'Vazirlik', 'subject' => 'Seminar', 'channel' => 'EMAIL',
        'receivedAt' => now()->format('Y-m-d'), 'requiresSignature' => false,
    ])->assertCreated();

    $byDirection = asLet($this, $token)->getJson('/api/v1/letters?direction=INCOMING')->json();
    expect($byDirection)->toHaveCount(2);

    $byStatus = asLet($this, $token)->getJson('/api/v1/letters?status=REGISTERED')->json();
    expect($byStatus)->toHaveCount(2);

    $bySearch = asLet($this, $token)->getJson('/api/v1/letters?search=markaziy')->json();
    expect($bySearch)->toHaveCount(1);
    expect($bySearch[0]['subject'])->toBe('Hamkorlik');
});

it('gates every letter route behind authentication', function () {
    $this->postJson('/api/v1/letters', [])->assertStatus(401);
    $this->getJson('/api/v1/letters')->assertStatus(401);
    $this->postJson('/api/v1/letters/00000000-0000-4000-8000-000000000000/route', [])->assertStatus(401);
});

// === sign (shared `signatures` table) ===

/**
 * Drives a letter all the way to ON_SIGNATURE (response file, requires
 * signature) using the standard fixture.
 */
function letterAwaitingSignature(TestCase $test): array
{
    [$uuid, $f] = registeredRoutedAssignedLetter($test, requiresSignature: true);
    asLet($test, $f['executor'][1])->postJson("/api/v1/letters/{$uuid}/execute", [
        'responseFileMeta' => ['fileName' => 'javob.pdf', 'fileSize' => 100, 'mimeType' => 'application/pdf'],
    ])->assertOk();
    asLet($test, $f['unitHead'][1])->postJson("/api/v1/letters/{$uuid}/accept")
        ->assertOk()->assertJsonPath('status', 'ON_SIGNATURE');

    return [$uuid, $f];
}

it('signs an ON_SIGNATURE letter with the Rahbar\'s ACTIVE certificate, writing to the shared signatures table', function () {
    [$uuid, $f] = letterAwaitingSignature($this);
    $cert = Certificate::factory()->create(['employee_uuid' => $f['rahbar'][2]->uuid, 'status' => 'ACTIVE']);

    $response = asLet($this, $f['rahbar'][1])->postJson("/api/v1/letters/{$uuid}/sign", [
        'certificateUuid' => $cert->uuid,
        'signatureHex' => str_repeat('ab', 64),
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'RESPONDED');

    $signature = Signature::where('resource_uuid', $uuid)->first();
    expect($signature)->not->toBeNull();
    expect($signature->resource_type)->toBe('letter');
    expect($signature->employee_uuid)->toBe($f['rahbar'][2]->uuid);
    expect($signature->certificate_uuid)->toBe($cert->uuid);
    expect($signature->algorithm)->toBe('RSA-PKCS7');

    expect(AuditLog::where('action', 'LETTER_SIGNED')->where('resource_uuid', $uuid)->first()?->context)
        ->toBe(['certificateSerial' => $cert->serial_number]);
    expect(Notification::where('recipient_employee_uuid', $f['devonxona'][2]->uuid)
        ->where('type', 'LETTER_ACCEPTED')->exists())->toBeTrue();
});

it('rejects signing by a non-Rahbar actor', function () {
    [$uuid, $f] = letterAwaitingSignature($this);
    $cert = Certificate::factory()->create(['employee_uuid' => $f['unitHead'][2]->uuid, 'status' => 'ACTIVE']);

    asLet($this, $f['unitHead'][1])->postJson("/api/v1/letters/{$uuid}/sign", [
        'certificateUuid' => $cert->uuid,
        'signatureHex' => 'abcd',
    ])->assertStatus(422)->assertJsonPath('code', 'not-rahbar');
});

it('rejects a certificate that is not ACTIVE or not owned by the signer', function () {
    [$uuid, $f] = letterAwaitingSignature($this);
    $revoked = Certificate::factory()->create(['employee_uuid' => $f['rahbar'][2]->uuid, 'status' => 'REVOKED']);

    asLet($this, $f['rahbar'][1])->postJson("/api/v1/letters/{$uuid}/sign", [
        'certificateUuid' => $revoked->uuid,
        'signatureHex' => 'abcd',
    ])->assertStatus(422)->assertJsonPath('code', 'cert-invalid');

    $notOwned = Certificate::factory()->create(['status' => 'ACTIVE']);
    asLet($this, $f['rahbar'][1])->postJson("/api/v1/letters/{$uuid}/sign", [
        'certificateUuid' => $notOwned->uuid,
        'signatureHex' => 'abcd',
    ])->assertStatus(422)->assertJsonPath('code', 'cert-invalid');
});

it('rejects signing a letter that is not ON_SIGNATURE', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this);
    $cert = Certificate::factory()->create(['employee_uuid' => $f['rahbar'][2]->uuid, 'status' => 'ACTIVE']);

    asLet($this, $f['rahbar'][1])->postJson("/api/v1/letters/{$uuid}/sign", [
        'certificateUuid' => $cert->uuid,
        'signatureHex' => 'abcd',
    ])->assertStatus(422)->assertJsonPath('code', 'wrong-status');
});

// === dispatch ===

it('dispatches a RESPONDED letter: creates an OUTGOING CH-2026/NNNN reply and closes the incoming letter', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this, requiresSignature: false);
    asLet($this, $f['executor'][1])->postJson("/api/v1/letters/{$uuid}/execute", [
        'responseFileMeta' => ['fileName' => 'javob.pdf', 'fileSize' => 100, 'mimeType' => 'application/pdf'],
    ])->assertOk();
    asLet($this, $f['unitHead'][1])->postJson("/api/v1/letters/{$uuid}/accept")
        ->assertOk()->assertJsonPath('status', 'RESPONDED');

    $response = asLet($this, $f['devonxona'][1])->postJson("/api/v1/letters/{$uuid}/dispatch", [
        'channel' => 'POCHTA',
    ]);

    $response->assertOk();
    $response->assertJsonPath('incoming.status', 'CLOSED');
    $response->assertJsonPath('outgoing.direction', 'OUTGOING');
    $response->assertJsonPath('outgoing.number', 'CH-2026/0001');
    $response->assertJsonPath('outgoing.status', 'DISPATCHED');
    $response->assertJsonPath('outgoing.linkedIncomingUuid', $uuid);

    $incoming = Letter::where('uuid', $uuid)->firstOrFail();
    expect($incoming->status)->toBe('CLOSED');
    expect($incoming->closed_at)->not->toBeNull();

    $outgoing = Letter::where('linked_incoming_uuid', $uuid)->firstOrFail();
    expect($outgoing->number)->toBe('CH-2026/0001');
    expect($outgoing->external_org)->toBe($incoming->external_org);

    expect(AuditLog::where('action', 'LETTER_DISPATCHED')->where('resource_uuid', $uuid)->exists())->toBeTrue();
    expect(AuditLog::where('action', 'LETTER_DISPATCHED')->where('resource_uuid', $outgoing->uuid)->exists())->toBeTrue();
    expect(AuditLog::where('action', 'LETTER_CLOSED')->where('resource_uuid', $uuid)->exists())->toBeTrue();
    expect(Notification::where('recipient_employee_uuid', $f['executor'][2]->uuid)
        ->where('type', 'LETTER_DISPATCHED')->exists())->toBeTrue();
});

it('rejects dispatch by a non-Devonxona actor', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this, requiresSignature: false);
    asLet($this, $f['executor'][1])->postJson("/api/v1/letters/{$uuid}/execute", [
        'responseFileMeta' => ['fileName' => 'javob.pdf', 'fileSize' => 100, 'mimeType' => 'application/pdf'],
    ])->assertOk();
    asLet($this, $f['unitHead'][1])->postJson("/api/v1/letters/{$uuid}/accept")->assertOk();

    asLet($this, $f['unitHead'][1])->postJson("/api/v1/letters/{$uuid}/dispatch", ['channel' => 'EMAIL'])
        ->assertStatus(422)->assertJsonPath('code', 'not-devonxona');
});

it('rejects dispatch of a letter that is not RESPONDED', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this);

    asLet($this, $f['devonxona'][1])->postJson("/api/v1/letters/{$uuid}/dispatch", ['channel' => 'EMAIL'])
        ->assertStatus(422)->assertJsonPath('code', 'wrong-status');
});

it('exposes the letter detail with signatures, linked outgoing/incoming, unit + names', function () {
    [$uuid, $f] = registeredRoutedAssignedLetter($this, requiresSignature: false);
    asLet($this, $f['executor'][1])->postJson("/api/v1/letters/{$uuid}/execute", [
        'responseFileMeta' => ['fileName' => 'javob.pdf', 'fileSize' => 100, 'mimeType' => 'application/pdf'],
    ])->assertOk();
    asLet($this, $f['unitHead'][1])->postJson("/api/v1/letters/{$uuid}/accept")->assertOk();
    $dispatched = asLet($this, $f['devonxona'][1])->postJson("/api/v1/letters/{$uuid}/dispatch", ['channel' => 'POCHTA'])->json();

    $incomingDetail = asLet($this, $f['devonxona'][1])->getJson("/api/v1/letters/{$uuid}")->assertOk()->json();
    expect($incomingDetail['linkedOutgoing']['uuid'])->toBe($dispatched['outgoing']['uuid']);
    expect($incomingDetail['routedToUnitName'])->toBe($f['child']->name_uz);
    expect($incomingDetail['assignedEmployeeName'])->toBe($f['executor'][2]->full_name_generated);
    expect($incomingDetail['registeredByName'])->toBe($f['devonxona'][2]->full_name_generated);

    $outgoingDetail = asLet($this, $f['devonxona'][1])
        ->getJson("/api/v1/letters/{$dispatched['outgoing']['uuid']}")->assertOk()->json();
    expect($outgoingDetail['linkedIncoming']['uuid'])->toBe($uuid);
});
