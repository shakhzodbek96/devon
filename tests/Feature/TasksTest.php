<?php

use App\Models\AuditLog;
use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Task;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\PositionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Task delegation / Kanban — PLAN_PHASE_H.md §2, §6 (BP-2)
|--------------------------------------------------------------------------
|
| Ports the frontend mock's task test surface: the full BP-2 lifecycle
| (create -> start -> clarification -> submit -> review accept/return/
| reject), subtree authorization at create time, clarification NOT
| changing status, round increment on return, late-submission detection,
| per-task authorization (assigner/assignee), stats, listing/filters, and
| RBAC (auth gate).
*/

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class, PositionsSeeder::class]);
});

/**
 * @return array{0: User, 1: string, 2: Employee}
 */
function taskActingUser(string $role = 'ROLE_EMPLOYEE'): array
{
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_uuid' => $employee->uuid]);
    $employee->update(['user_uuid' => $user->uuid]);
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token, $employee->fresh()];
}

/** Same forgetGuards() workaround as LettersTest.php's asLet(). */
function asTask(TestCase $test, string $token): TestCase
{
    app('auth')->forgetGuards();

    return $test->withHeader('Authorization', "Bearer {$token}");
}

function taskRootUnit(?string $headEmployeeUuid = null): Unit
{
    return Unit::factory()->create([
        'level' => 0,
        'head_employee_uuid' => $headEmployeeUuid,
        'status' => 'ACTIVE',
    ]);
}

function taskChildUnit(Unit $parent, ?string $headEmployeeUuid = null): Unit
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
 * Builds the standard BP-2 fixture: a root unit headed by the manager
 * (assigner), a child unit the assignee has an open PRIMARY assignment in
 * (in-scope), and a separate, unrelated root unit + employee (out-of-scope
 * assignee — belongs to neither the manager's unit nor any descendant).
 *
 * @return array{
 *   manager: array{0: User, 1: string, 2: Employee},
 *   assignee: array{0: User, 1: string, 2: Employee},
 *   outsider: array{0: User, 1: string, 2: Employee},
 *   root: Unit,
 *   child: Unit,
 * }
 */
function taskFixture(): array
{
    $manager = taskActingUser();
    $root = taskRootUnit($manager[2]->uuid);
    $child = taskChildUnit($root);

    $assigneeEmployee = Employee::factory()->create();
    Assignment::create([
        'uuid' => (string) Str::uuid(),
        'employee_uuid' => $assigneeEmployee->uuid,
        'unit_uuid' => $child->uuid,
        'position_id' => 'POS-SPECIALIST',
        'is_primary' => true,
        'start_date' => now()->subYear()->format('Y-m-d'),
        'workload_percent' => 100,
        'type' => 'PRIMARY',
    ]);
    $assigneeUser = User::factory()->create(['employee_uuid' => $assigneeEmployee->uuid]);
    $assigneeEmployee->update(['user_uuid' => $assigneeUser->uuid]);
    $assigneeUser->assignRole('ROLE_EMPLOYEE');
    $assigneeToken = $assigneeUser->createToken('test')->plainTextToken;
    $assignee = [$assigneeUser, $assigneeToken, $assigneeEmployee->fresh()];

    // Outsider: a completely unrelated unit tree — not a descendant of $root.
    $outsiderEmployee = Employee::factory()->create();
    $outsiderRoot = taskRootUnit();
    Assignment::create([
        'uuid' => (string) Str::uuid(),
        'employee_uuid' => $outsiderEmployee->uuid,
        'unit_uuid' => $outsiderRoot->uuid,
        'position_id' => 'POS-SPECIALIST',
        'is_primary' => true,
        'start_date' => now()->subYear()->format('Y-m-d'),
        'workload_percent' => 100,
        'type' => 'PRIMARY',
    ]);
    $outsiderUser = User::factory()->create(['employee_uuid' => $outsiderEmployee->uuid]);
    $outsiderEmployee->update(['user_uuid' => $outsiderUser->uuid]);
    $outsiderUser->assignRole('ROLE_EMPLOYEE');
    $outsiderToken = $outsiderUser->createToken('test')->plainTextToken;
    $outsider = [$outsiderUser, $outsiderToken, $outsiderEmployee->fresh()];

    return compact('manager', 'assignee', 'outsider', 'root', 'child');
}

/** Creates a task via the standard fixture. Returns [uuid, fixture]. */
function createdTask(TestCase $test, array $f, array $overrides = []): array
{
    $payload = array_merge([
        'title' => 'API endpointlarini hujjatlashtirish',
        'description' => "OpenAPI spetsifikatsiyasini tayyorlang.",
        'priority' => 'STANDARD',
        'assigneeUuid' => $f['assignee'][2]->uuid,
        'deadline' => now()->addDays(5)->format('Y-m-d'),
    ], $overrides);

    $res = asTask($test, $f['manager'][1])->postJson('/api/v1/tasks', $payload)
        ->assertCreated()->json();

    return [$res['uuid'], $f];
}

/** Drives a task to IN_PROGRESS (started by the assignee). */
function startedTask(TestCase $test, array $f, array $overrides = []): array
{
    [$uuid, $f] = createdTask($test, $f, $overrides);
    asTask($test, $f['assignee'][1])->postJson("/api/v1/tasks/{$uuid}/start")->assertOk();

    return [$uuid, $f];
}

/** Drives a task to UNDER_REVIEW (submitted by the assignee). */
function submittedTask(TestCase $test, array $f, array $overrides = []): array
{
    [$uuid, $f] = startedTask($test, $f, $overrides);
    asTask($test, $f['assignee'][1])->postJson("/api/v1/tasks/{$uuid}/submit", [
        'summary' => 'Ish yakunlandi.',
    ])->assertOk();

    return [$uuid, $f];
}

// === create ===

it('creates a task with auto-numbered TOP-2026/NNNN, assigner + assignee wiring', function () {
    $f = taskFixture();

    $response = asTask($this, $f['manager'][1])->postJson('/api/v1/tasks', [
        'title' => 'API endpointlarini hujjatlashtirish',
        'description' => "OpenAPI spetsifikatsiyasini tayyorlang.",
        'priority' => 'HIGH',
        'assigneeUuid' => $f['assignee'][2]->uuid,
        'deadline' => now()->addDays(5)->format('Y-m-d'),
    ]);

    $response->assertCreated();
    $response->assertJsonPath('number', 'TOP-2026/0001');
    $response->assertJsonPath('status', 'NEW');
    $response->assertJsonPath('round', 1);
    $response->assertJsonPath('assignerUuid', $f['manager'][2]->uuid);
    $response->assertJsonPath('assigneeUuid', $f['assignee'][2]->uuid);
    expect($response->json('comments'))->toBe([]);

    expect(Task::count())->toBe(1);
    expect(AuditLog::where('action', 'TASK_CREATED')->exists())->toBeTrue();
    expect(Notification::where('recipient_employee_uuid', $f['assignee'][2]->uuid)
        ->where('type', 'TASK_ASSIGNED')->exists())->toBeTrue();
});

it('auto-numbers tasks sequentially as TOP-2026/NNNN', function () {
    $f = taskFixture();

    for ($i = 0; $i < 2; $i++) {
        asTask($this, $f['manager'][1])->postJson('/api/v1/tasks', [
            'title' => "Task {$i}",
            'description' => 'Desc',
            'priority' => 'STANDARD',
            'assigneeUuid' => $f['assignee'][2]->uuid,
            'deadline' => now()->addDays(5)->format('Y-m-d'),
        ])->assertCreated();
    }

    expect(Task::pluck('number')->sort()->values()->all())->toBe(['TOP-2026/0001', 'TOP-2026/0002']);
});

it('rejects creating a task for an assignee outside the assigner\'s org subtree', function () {
    $f = taskFixture();

    asTask($this, $f['manager'][1])->postJson('/api/v1/tasks', [
        'title' => 'X', 'description' => 'Y', 'priority' => 'STANDARD',
        'assigneeUuid' => $f['outsider'][2]->uuid,
        'deadline' => now()->addDays(5)->format('Y-m-d'),
    ])->assertStatus(422)->assertJsonPath('code', 'out-of-scope');
});

it('rejects self-assignment', function () {
    $f = taskFixture();

    asTask($this, $f['manager'][1])->postJson('/api/v1/tasks', [
        'title' => 'X', 'description' => 'Y', 'priority' => 'STANDARD',
        'assigneeUuid' => $f['manager'][2]->uuid,
        'deadline' => now()->addDays(5)->format('Y-m-d'),
    ])->assertStatus(422)->assertJsonPath('code', 'self-assign');
});

// === update ===

it('lets the assigner edit title/description/priority/deadline while non-terminal', function () {
    $f = taskFixture();
    [$uuid, $f] = createdTask($this, $f);

    $response = asTask($this, $f['manager'][1])->patchJson("/api/v1/tasks/{$uuid}", [
        'title' => 'Yangilangan sarlavha',
        'deadline' => now()->addDays(10)->format('Y-m-d'),
    ]);

    $response->assertOk();
    $response->assertJsonPath('title', 'Yangilangan sarlavha');
    expect(AuditLog::where('action', 'TASK_UPDATED')->where('resource_uuid', $uuid)->first()?->context)
        ->toBe(['kind' => 'deadline']);
});

it('rejects editing by someone other than the assigner', function () {
    $f = taskFixture();
    [$uuid, $f] = createdTask($this, $f);

    asTask($this, $f['assignee'][1])->patchJson("/api/v1/tasks/{$uuid}", ['title' => 'X'])
        ->assertStatus(422)->assertJsonPath('code', 'not-assigner');
});

it('rejects editing a terminal task', function () {
    $f = taskFixture();
    [$uuid, $f] = submittedTask($this, $f);
    asTask($this, $f['manager'][1])->postJson("/api/v1/tasks/{$uuid}/review", ['decision' => 'ACCEPT'])->assertOk();

    asTask($this, $f['manager'][1])->patchJson("/api/v1/tasks/{$uuid}", ['title' => 'X'])
        ->assertStatus(422)->assertJsonPath('code', 'wrong-status');
});

// === start ===

it('starts a NEW task (assignee-only), setting startedAt', function () {
    $f = taskFixture();
    [$uuid, $f] = createdTask($this, $f);

    asTask($this, $f['manager'][1])->postJson("/api/v1/tasks/{$uuid}/start")
        ->assertStatus(422)->assertJsonPath('code', 'not-assignee');

    $response = asTask($this, $f['assignee'][1])->postJson("/api/v1/tasks/{$uuid}/start");
    $response->assertOk();
    $response->assertJsonPath('status', 'IN_PROGRESS');
    expect($response->json('startedAt'))->not->toBeNull();
    expect(AuditLog::where('action', 'TASK_STARTED')->where('resource_uuid', $uuid)->exists())->toBeTrue();
});

it('rejects starting a task that is not NEW', function () {
    $f = taskFixture();
    [$uuid, $f] = startedTask($this, $f);

    asTask($this, $f['assignee'][1])->postJson("/api/v1/tasks/{$uuid}/start")
        ->assertStatus(422)->assertJsonPath('code', 'wrong-status');
});

// === clarification (does NOT change status) ===

it('lets the assignee request clarification without changing status, notifying the assigner', function () {
    $f = taskFixture();
    [$uuid, $f] = startedTask($this, $f);

    $response = asTask($this, $f['assignee'][1])->postJson("/api/v1/tasks/{$uuid}/clarification-request", [
        'body' => "Test muhiti uchun alohida server kerakmi?",
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'IN_PROGRESS');
    expect($response->json('comments'))->toHaveCount(1);
    expect($response->json('comments.0.kind'))->toBe('CLARIFICATION_REQUEST');
    expect(Notification::where('recipient_employee_uuid', $f['manager'][2]->uuid)
        ->where('type', 'TASK_CLARIFICATION_REQUESTED')->exists())->toBeTrue();
});

it('lets the assigner answer clarification without changing status, notifying the assignee', function () {
    $f = taskFixture();
    [$uuid, $f] = startedTask($this, $f);
    asTask($this, $f['assignee'][1])->postJson("/api/v1/tasks/{$uuid}/clarification-request", ['body' => 'Savol?'])->assertOk();

    $response = asTask($this, $f['manager'][1])->postJson("/api/v1/tasks/{$uuid}/clarification-answer", [
        'body' => 'Javob.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'IN_PROGRESS');
    expect($response->json('comments'))->toHaveCount(2);
    expect($response->json('comments.1.kind'))->toBe('CLARIFICATION_REPLY');
    expect(Notification::where('recipient_employee_uuid', $f['assignee'][2]->uuid)
        ->where('type', 'TASK_CLARIFICATION_ANSWERED')->exists())->toBeTrue();
});

it('rejects an empty/blank clarification body', function () {
    $f = taskFixture();
    [$uuid, $f] = startedTask($this, $f);

    asTask($this, $f['assignee'][1])->postJson("/api/v1/tasks/{$uuid}/clarification-request", ['body' => '   '])
        ->assertStatus(422)->assertJsonPath('code', 'reason-required');
});

it('rejects clarification request by someone other than the assignee', function () {
    $f = taskFixture();
    [$uuid, $f] = startedTask($this, $f);

    asTask($this, $f['manager'][1])->postJson("/api/v1/tasks/{$uuid}/clarification-request", ['body' => 'X'])
        ->assertStatus(422)->assertJsonPath('code', 'not-assignee');
});

// === submit ===

it('submits a deliverable (IN_PROGRESS -> UNDER_REVIEW), notifying the assigner', function () {
    $f = taskFixture();
    [$uuid, $f] = startedTask($this, $f);

    $response = asTask($this, $f['assignee'][1])->postJson("/api/v1/tasks/{$uuid}/submit", [
        'summary' => 'Redis kesh qatlami joriy etildi.',
        'file' => ['fileName' => 'natija.pdf', 'fileSize' => 2048, 'mimeType' => 'application/pdf', 'uploadedAt' => now()->toIso8601String()],
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'UNDER_REVIEW');
    expect($response->json('deliverable.summary'))->toBe('Redis kesh qatlami joriy etildi.');
    expect($response->json('submittedAt'))->not->toBeNull();
    expect($response->json('lateSubmission'))->toBeFalse();
    expect(Notification::where('recipient_employee_uuid', $f['manager'][2]->uuid)
        ->where('type', 'TASK_SUBMITTED')->exists())->toBeTrue();
});

it('flags late_submission true when submitted past the deadline', function () {
    $f = taskFixture();
    [$uuid, $f] = startedTask($this, $f, ['deadline' => now()->subDays(2)->format('Y-m-d')]);

    $response = asTask($this, $f['assignee'][1])->postJson("/api/v1/tasks/{$uuid}/submit", [
        'summary' => 'Kech topshirildi.',
    ]);

    $response->assertOk();
    expect($response->json('lateSubmission'))->toBeTrue();
});

it('replaces the deliverable on re-submission while already UNDER_REVIEW', function () {
    $f = taskFixture();
    [$uuid, $f] = submittedTask($this, $f);

    $response = asTask($this, $f['assignee'][1])->postJson("/api/v1/tasks/{$uuid}/submit", [
        'summary' => 'Yangilangan natija.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'UNDER_REVIEW');
    expect($response->json('deliverable.summary'))->toBe('Yangilangan natija.');
    expect(AuditLog::where('action', 'TASK_SUBMITTED')->where('resource_uuid', $uuid)
        ->where('context->replaced', true)->exists())->toBeTrue();
});

it('rejects submission by someone other than the assignee', function () {
    $f = taskFixture();
    [$uuid, $f] = startedTask($this, $f);

    asTask($this, $f['manager'][1])->postJson("/api/v1/tasks/{$uuid}/submit", ['summary' => 'X'])
        ->assertStatus(422)->assertJsonPath('code', 'not-assignee');
});

it('rejects submission of a NEW (not-yet-started) task', function () {
    $f = taskFixture();
    [$uuid, $f] = createdTask($this, $f);

    asTask($this, $f['assignee'][1])->postJson("/api/v1/tasks/{$uuid}/submit", ['summary' => 'X'])
        ->assertStatus(422)->assertJsonPath('code', 'wrong-status');
});

// === review ===

it('accepts a deliverable (UNDER_REVIEW -> DONE), notifying the assignee', function () {
    $f = taskFixture();
    [$uuid, $f] = submittedTask($this, $f);

    $response = asTask($this, $f['manager'][1])->postJson("/api/v1/tasks/{$uuid}/review", [
        'decision' => 'ACCEPT',
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'DONE');
    expect($response->json('acceptedAt'))->not->toBeNull();
    expect(Notification::where('recipient_employee_uuid', $f['assignee'][2]->uuid)
        ->where('type', 'TASK_ACCEPTED')->exists())->toBeTrue();
});

it('accepts with a note, storing reviewNote and a NOTE comment', function () {
    $f = taskFixture();
    [$uuid, $f] = submittedTask($this, $f);

    $response = asTask($this, $f['manager'][1])->postJson("/api/v1/tasks/{$uuid}/review", [
        'decision' => 'ACCEPT_WITH_NOTE',
        'text' => "Yaxshi bajarilgan, biroq kechikkan.",
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'DONE');
    $response->assertJsonPath('reviewNote', "Yaxshi bajarilgan, biroq kechikkan.");
    $noteComments = collect($response->json('comments'))->where('kind', 'NOTE');
    expect($noteComments)->toHaveCount(1);
});

it('returns a deliverable for rework (UNDER_REVIEW -> IN_PROGRESS), incrementing round', function () {
    $f = taskFixture();
    [$uuid, $f] = submittedTask($this, $f);
    expect(Task::where('uuid', $uuid)->value('round'))->toBe(1);

    $response = asTask($this, $f['manager'][1])->postJson("/api/v1/tasks/{$uuid}/review", [
        'decision' => 'RETURN',
        'text' => 'Rollback skripti yetishmayapti.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'IN_PROGRESS');
    $response->assertJsonPath('round', 2);
    $returnComments = collect($response->json('comments'))->where('kind', 'RETURN_FEEDBACK');
    expect($returnComments)->toHaveCount(1);
    expect(Notification::where('recipient_employee_uuid', $f['assignee'][2]->uuid)
        ->where('type', 'TASK_RETURNED')->exists())->toBeTrue();

    // round increments again on a second return -> resubmit cycle.
    asTask($this, $f['assignee'][1])->postJson("/api/v1/tasks/{$uuid}/submit", ['summary' => 'Qayta yubordim.'])->assertOk();
    $second = asTask($this, $f['manager'][1])->postJson("/api/v1/tasks/{$uuid}/review", [
        'decision' => 'RETURN', 'text' => 'Yana kamchilik bor.',
    ]);
    $second->assertJsonPath('round', 3);
});

it('rejects a deliverable (UNDER_REVIEW -> REJECTED), notifying the assignee', function () {
    $f = taskFixture();
    [$uuid, $f] = submittedTask($this, $f);

    $response = asTask($this, $f['manager'][1])->postJson("/api/v1/tasks/{$uuid}/review", [
        'decision' => 'REJECT',
        'text' => 'Bir nechta mijoz hali ham eski versiyadan foydalanmoqda.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'REJECTED');
    expect($response->json('rejectedAt'))->not->toBeNull();
    $rejectComments = collect($response->json('comments'))->where('kind', 'REJECT_REASON');
    expect($rejectComments)->toHaveCount(1);
    expect(Notification::where('recipient_employee_uuid', $f['assignee'][2]->uuid)
        ->where('type', 'TASK_REJECTED')->exists())->toBeTrue();
});

it('requires a reason for RETURN and REJECT decisions', function () {
    $f = taskFixture();
    [$uuid, $f] = submittedTask($this, $f);

    asTask($this, $f['manager'][1])->postJson("/api/v1/tasks/{$uuid}/review", ['decision' => 'RETURN'])
        ->assertStatus(422)->assertJsonPath('code', 'reason-required');
    asTask($this, $f['manager'][1])->postJson("/api/v1/tasks/{$uuid}/review", ['decision' => 'REJECT'])
        ->assertStatus(422)->assertJsonPath('code', 'reason-required');
});

it('rejects review by someone other than the assigner', function () {
    $f = taskFixture();
    [$uuid, $f] = submittedTask($this, $f);

    asTask($this, $f['assignee'][1])->postJson("/api/v1/tasks/{$uuid}/review", ['decision' => 'ACCEPT'])
        ->assertStatus(422)->assertJsonPath('code', 'not-assigner');
});

it('rejects reviewing a task that is not UNDER_REVIEW', function () {
    $f = taskFixture();
    [$uuid, $f] = startedTask($this, $f);

    asTask($this, $f['manager'][1])->postJson("/api/v1/tasks/{$uuid}/review", ['decision' => 'ACCEPT'])
        ->assertStatus(422)->assertJsonPath('code', 'wrong-status');
});

// === stats / listing / overdue ===

it('computes task stats scoped to tasks the actor assigned', function () {
    $f = taskFixture();
    [$doneUuid, $f] = submittedTask($this, $f);
    asTask($this, $f['manager'][1])->postJson("/api/v1/tasks/{$doneUuid}/review", ['decision' => 'ACCEPT'])->assertOk();
    createdTask($this, $f, ['title' => 'Second']);

    $response = asTask($this, $f['manager'][1])->getJson('/api/v1/me/task-stats');

    $response->assertOk();
    expect($response->json('byStatus.DONE'))->toBe(1);
    expect($response->json('byStatus.NEW'))->toBe(1);
    expect($response->json('loadPerAssignee.0.assigneeUuid'))->toBe($f['assignee'][2]->uuid);
    expect($response->json('loadPerAssignee.0.openCount'))->toBe(1);
});

it('lists tasks scoped by box (assigned-by-me / assigned-to-me) and filters', function () {
    $f = taskFixture();
    createdTask($this, $f, ['title' => 'High one', 'priority' => 'HIGH']);
    createdTask($this, $f, ['title' => 'Standard one', 'priority' => 'STANDARD']);

    $byMe = asTask($this, $f['manager'][1])->getJson('/api/v1/tasks?box=assigned-by-me')->assertOk()->json();
    expect($byMe)->toHaveCount(2);

    $toMe = asTask($this, $f['assignee'][1])->getJson('/api/v1/tasks?box=assigned-to-me')->assertOk()->json();
    expect($toMe)->toHaveCount(2);

    $filtered = asTask($this, $f['manager'][1])->getJson('/api/v1/tasks?box=assigned-by-me&priority=HIGH')->assertOk()->json();
    expect($filtered)->toHaveCount(1);
    expect($filtered[0]['title'])->toBe('High one');

    $searched = asTask($this, $f['manager'][1])->getJson('/api/v1/tasks?box=assigned-by-me&search=standard')->assertOk()->json();
    expect($searched)->toHaveCount(1);
});

it('flags a task overdue only when the deadline has passed and it is not terminal', function () {
    $f = taskFixture();
    [$overdueUuid, $f] = createdTask($this, $f, ['deadline' => now()->subDay()->format('Y-m-d')]);
    createdTask($this, $f, ['title' => 'Not overdue', 'deadline' => now()->addDays(5)->format('Y-m-d')]);

    $response = asTask($this, $f['manager'][1])->getJson('/api/v1/tasks?box=assigned-by-me&overdueOnly=1');
    $response->assertOk();
    expect(collect($response->json())->pluck('uuid')->all())->toBe([$overdueUuid]);
});

// === detail ===

it('exposes the task detail with resolved names and comment authors', function () {
    $f = taskFixture();
    [$uuid, $f] = startedTask($this, $f);
    asTask($this, $f['assignee'][1])->postJson("/api/v1/tasks/{$uuid}/clarification-request", ['body' => 'Savol?'])->assertOk();

    $detail = asTask($this, $f['manager'][1])->getJson("/api/v1/tasks/{$uuid}")->assertOk()->json();

    expect($detail['assignerName'])->toBe($f['manager'][2]->full_name_generated);
    expect($detail['assigneeName'])->toBe($f['assignee'][2]->full_name_generated);
    expect($detail['commentAuthors'][$f['assignee'][2]->uuid])->toBe($f['assignee'][2]->full_name_generated);
});

// === RBAC ===

it('gates every task route behind authentication', function () {
    $this->postJson('/api/v1/tasks', [])->assertStatus(401);
    $this->getJson('/api/v1/tasks?box=assigned-to-me')->assertStatus(401);
    $this->getJson('/api/v1/me/task-stats')->assertStatus(401);
    $this->postJson('/api/v1/tasks/00000000-0000-4000-8000-000000000000/start', [])->assertStatus(401);
});
