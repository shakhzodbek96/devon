<?php

use App\Models\Certificate;
use App\Models\CollegialBody;
use App\Models\CollegialBodyMember;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Bayonnoma (protocol) approval chain — PLAN_kollegial-organ.md §1, §3, §4
|--------------------------------------------------------------------------
|
| create (quorum/roster validation) -> submit -> reviewer(s) approve (all)
| -> auto SENT_TO_MEMBERS -> members sign one-by-one, ERI (last one ->
| auto SENT_TO_CHAIRMAN) -> chairman signs, ERI -> APPROVED. Every
| rejection station (review/members/chairman) bounces back to an editable
| DRAFT-equivalent state (§2 simplification #1). Unauthorized actors and
| double-decisions are rejected with the matching error code.
*/

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
});

/**
 * @return array{0: User, 1: string, 2: Employee}
 */
function protocolActingUser(string $role = 'ROLE_EMPLOYEE'): array
{
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_uuid' => $employee->uuid]);
    $employee->update(['user_uuid' => $user->uuid]);
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token, $employee->fresh()];
}

/** Same forgetGuards() workaround as TasksTest.php's asTask() / TabelTest.php's asTabel(). */
function asProtocol(TestCase $test, string $token): TestCase
{
    app('auth')->forgetGuards();

    return $test->withHeader('Authorization', "Bearer {$token}");
}

/**
 * Builds a fully-staffed collegial body: chairman, secretary, and 4
 * roster members (all with ACTIVE certificates so signing can be
 * exercised), quorum_min=3.
 *
 * @return array{
 *   body: CollegialBody,
 *   chairman: array{0: User, 1: string, 2: Employee},
 *   secretary: array{0: User, 1: string, 2: Employee},
 *   members: array<int, array{0: User, 1: string, 2: Employee}>,
 *   reviewer: array{0: User, 1: string, 2: Employee},
 * }
 */
function protocolFixture(int $quorumMin = 3): array
{
    $chairman = protocolActingUser();
    $secretary = protocolActingUser();
    $reviewer = protocolActingUser();

    Certificate::factory()->create(['employee_uuid' => $chairman[2]->uuid, 'status' => 'ACTIVE']);

    $members = [];
    for ($i = 0; $i < 4; $i++) {
        $member = protocolActingUser();
        Certificate::factory()->create(['employee_uuid' => $member[2]->uuid, 'status' => 'ACTIVE']);
        $members[] = $member;
    }

    $body = CollegialBody::factory()->create([
        'chairman_employee_uuid' => $chairman[2]->uuid,
        'secretary_employee_uuid' => $secretary[2]->uuid,
        'quorum_min' => $quorumMin,
    ]);

    foreach ($members as $member) {
        CollegialBodyMember::create([
            'collegial_body_uuid' => $body->uuid,
            'employee_uuid' => $member[2]->uuid,
        ]);
    }

    return compact('body', 'chairman', 'secretary', 'members', 'reviewer');
}

function createProtocolViaApi(TestCase $test, string $token, string $bodyUuid, array $overrides = []): array
{
    $payload = array_merge([
        'protocolDate' => now()->format('Y-m-d'),
        'templateKey' => 'STANDARD',
        'agenda' => 'Byudjetni tasdiqlash',
    ], $overrides);

    $response = asProtocol($test, $token)->postJson("/api/v1/collegial-bodies/{$bodyUuid}/protocols", $payload);

    return [$response, $response->json()];
}

it('creates a DRAFT protocol with an auto-generated number, reviewers and participants', function () {
    $f = protocolFixture();
    $memberUuids = collect($f['members'])->map(fn ($m) => $m[2]->uuid)->all();

    [$response, $protocol] = createProtocolViaApi($this, $f['secretary'][1], $f['body']->uuid, [
        'participantUuids' => $memberUuids,
        'reviewerUuids' => [$f['reviewer'][2]->uuid],
    ]);

    $response->assertStatus(201);
    expect($protocol['status'])->toBe('DRAFT')
        ->and($protocol['number'])->toStartWith('BAY-2026/')
        ->and($protocol['participants'])->toHaveCount(4)
        ->and($protocol['reviewers'])->toHaveCount(1);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'PROTOCOL_CREATED',
        'resource_type' => 'protocol',
        'resource_uuid' => $protocol['uuid'],
    ]);
});

it('rejects protocol creation below quorum with quorum-not-met', function () {
    $f = protocolFixture(3);
    $memberUuids = collect($f['members'])->take(2)->map(fn ($m) => $m[2]->uuid)->all();

    [$response] = createProtocolViaApi($this, $f['secretary'][1], $f['body']->uuid, [
        'participantUuids' => $memberUuids,
    ]);

    $response->assertStatus(422)->assertJsonPath('code', 'quorum-not-met');
});

it('rejects protocol creation with a non-roster participant with not-body-member', function () {
    $f = protocolFixture();
    $outsider = protocolActingUser();
    $participantUuids = array_merge(
        collect($f['members'])->take(2)->map(fn ($m) => $m[2]->uuid)->all(),
        [$outsider[2]->uuid],
    );

    [$response] = createProtocolViaApi($this, $f['secretary'][1], $f['body']->uuid, [
        'participantUuids' => $participantUuids,
    ]);

    $response->assertStatus(422)->assertJsonPath('code', 'not-body-member');
});

it('rejects protocol creation by a non-secretary, non-collegial.manage actor with not-authorized', function () {
    $f = protocolFixture();
    $memberUuids = collect($f['members'])->map(fn ($m) => $m[2]->uuid)->all();
    $stranger = protocolActingUser();

    [$response] = createProtocolViaApi($this, $stranger[1], $f['body']->uuid, [
        'participantUuids' => $memberUuids,
    ]);

    $response->assertStatus(422)->assertJsonPath('code', 'not-authorized');
});

/**
 * @return array{fixture: array, protocolUuid: string}
 */
function protocolAtMembersStage(TestCase $test, int $quorumMin = 3, int $reviewerCount = 2): array
{
    $f = protocolFixture($quorumMin);
    $memberUuids = collect($f['members'])->map(fn ($m) => $m[2]->uuid)->all();

    $reviewers = [$f['reviewer']];
    for ($i = 1; $i < $reviewerCount; $i++) {
        $reviewers[] = protocolActingUser();
    }

    [, $protocol] = createProtocolViaApi($test, $f['secretary'][1], $f['body']->uuid, [
        'participantUuids' => $memberUuids,
        'reviewerUuids' => collect($reviewers)->map(fn ($r) => $r[2]->uuid)->all(),
    ]);

    $uuid = $protocol['uuid'];

    asProtocol($test, $f['secretary'][1])->postJson("/api/v1/protocols/{$uuid}/submit")->assertOk();

    foreach ($reviewers as $reviewer) {
        asProtocol($test, $reviewer[1])
            ->postJson("/api/v1/protocols/{$uuid}/reviewer-decide", ['approve' => true])
            ->assertOk();
    }

    $f['reviewers'] = $reviewers;

    return ['fixture' => $f, 'protocolUuid' => $uuid];
}

it('runs the full chain: submit -> all reviewers approve -> auto SENT_TO_MEMBERS -> all members sign -> auto SENT_TO_CHAIRMAN -> chairman signs -> APPROVED', function () {
    $ctx = protocolAtMembersStage($this);
    $f = $ctx['fixture'];
    $uuid = $ctx['protocolUuid'];

    $show = asProtocol($this, $f['secretary'][1])->getJson("/api/v1/protocols/{$uuid}")->assertOk();
    expect($show->json('status'))->toBe('SENT_TO_MEMBERS');

    $members = $f['members'];
    foreach ($members as $i => $member) {
        $cert = Certificate::where('employee_uuid', $member[2]->uuid)->where('status', 'ACTIVE')->first();

        $response = asProtocol($this, $member[1])->postJson("/api/v1/protocols/{$uuid}/member-sign", [
            'certificateUuid' => $cert->uuid,
            'signatureHex' => bin2hex(random_bytes(16)),
        ])->assertOk();

        $isLast = $i === count($members) - 1;
        expect($response->json('status'))->toBe($isLast ? 'SENT_TO_CHAIRMAN' : 'SENT_TO_MEMBERS');
    }

    $this->assertDatabaseHas('signatures', [
        'resource_type' => 'protocol',
        'resource_uuid' => $uuid,
        'employee_uuid' => $members[0][2]->uuid,
    ]);

    $chairmanCert = Certificate::where('employee_uuid', $f['chairman'][2]->uuid)->where('status', 'ACTIVE')->first();
    $finalResponse = asProtocol($this, $f['chairman'][1])->postJson("/api/v1/protocols/{$uuid}/chairman-decide", [
        'approve' => true,
        'certificateUuid' => $chairmanCert->uuid,
        'signatureHex' => bin2hex(random_bytes(16)),
    ])->assertOk();

    expect($finalResponse->json('status'))->toBe('APPROVED')
        ->and($finalResponse->json('finalizedAt'))->not->toBeNull();

    $this->assertDatabaseHas('signatures', [
        'resource_type' => 'protocol',
        'resource_uuid' => $uuid,
        'employee_uuid' => $f['chairman'][2]->uuid,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'PROTOCOL_CHAIRMAN_APPROVED',
        'resource_type' => 'protocol',
        'resource_uuid' => $uuid,
    ]);
});

it('reviewer rejection immediately bounces the protocol to REVIEW_REJECTED', function () {
    $f = protocolFixture();
    $memberUuids = collect($f['members'])->map(fn ($m) => $m[2]->uuid)->all();
    $secondReviewer = protocolActingUser();

    [, $protocol] = createProtocolViaApi($this, $f['secretary'][1], $f['body']->uuid, [
        'participantUuids' => $memberUuids,
        'reviewerUuids' => [$f['reviewer'][2]->uuid, $secondReviewer[2]->uuid],
    ]);
    $uuid = $protocol['uuid'];

    asProtocol($this, $f['secretary'][1])->postJson("/api/v1/protocols/{$uuid}/submit")->assertOk();

    $response = asProtocol($this, $f['reviewer'][1])->postJson("/api/v1/protocols/{$uuid}/reviewer-decide", [
        'approve' => false,
        'note' => 'Xato bor',
    ])->assertOk();

    expect($response->json('status'))->toBe('REVIEW_REJECTED')
        ->and($response->json('reviewNote'))->toBe('Xato bor');

    // The other reviewer, who never got to decide, cannot decide against a
    // protocol that's no longer SENT_FOR_REVIEW.
    asProtocol($this, $secondReviewer[1])
        ->postJson("/api/v1/protocols/{$uuid}/reviewer-decide", ['approve' => true])
        ->assertStatus(422)
        ->assertJsonPath('code', 'wrong-status');

    // Editable again — secretary can resubmit.
    asProtocol($this, $f['secretary'][1])->postJson("/api/v1/protocols/{$uuid}/submit")->assertOk();
});

it('a member decline immediately bounces the protocol to MEMBERS_REJECTED', function () {
    $ctx = protocolAtMembersStage($this);
    $f = $ctx['fixture'];
    $uuid = $ctx['protocolUuid'];

    $decliner = $f['members'][0];
    $response = asProtocol($this, $decliner[1])->postJson("/api/v1/protocols/{$uuid}/member-decline", [
        'note' => 'Roziman emas',
    ])->assertOk();

    expect($response->json('status'))->toBe('MEMBERS_REJECTED');

    // A different member can no longer sign — protocol isn't SENT_TO_MEMBERS anymore.
    $otherMember = $f['members'][1];
    $cert = Certificate::where('employee_uuid', $otherMember[2]->uuid)->where('status', 'ACTIVE')->first();
    asProtocol($this, $otherMember[1])->postJson("/api/v1/protocols/{$uuid}/member-sign", [
        'certificateUuid' => $cert->uuid,
        'signatureHex' => bin2hex(random_bytes(16)),
    ])->assertStatus(422)->assertJsonPath('code', 'wrong-status');
});

it('a chairman rejection bounces the protocol to CHAIRMAN_REJECTED', function () {
    $ctx = protocolAtMembersStage($this);
    $f = $ctx['fixture'];
    $uuid = $ctx['protocolUuid'];

    foreach ($f['members'] as $member) {
        $cert = Certificate::where('employee_uuid', $member[2]->uuid)->where('status', 'ACTIVE')->first();
        asProtocol($this, $member[1])->postJson("/api/v1/protocols/{$uuid}/member-sign", [
            'certificateUuid' => $cert->uuid,
            'signatureHex' => bin2hex(random_bytes(16)),
        ])->assertOk();
    }

    $response = asProtocol($this, $f['chairman'][1])->postJson("/api/v1/protocols/{$uuid}/chairman-decide", [
        'approve' => false,
        'note' => 'Qayta ko\'rib chiqilsin',
    ])->assertOk();

    expect($response->json('status'))->toBe('CHAIRMAN_REJECTED')
        ->and($response->json('chairmanNote'))->toBe('Qayta ko\'rib chiqilsin');
});

it('rejects a non-reviewer deciding with not-reviewer', function () {
    $f = protocolFixture();
    $memberUuids = collect($f['members'])->map(fn ($m) => $m[2]->uuid)->all();
    $stranger = protocolActingUser();

    [, $protocol] = createProtocolViaApi($this, $f['secretary'][1], $f['body']->uuid, [
        'participantUuids' => $memberUuids,
        'reviewerUuids' => [$f['reviewer'][2]->uuid],
    ]);
    $uuid = $protocol['uuid'];
    asProtocol($this, $f['secretary'][1])->postJson("/api/v1/protocols/{$uuid}/submit")->assertOk();

    asProtocol($this, $stranger[1])
        ->postJson("/api/v1/protocols/{$uuid}/reviewer-decide", ['approve' => true])
        ->assertStatus(422)
        ->assertJsonPath('code', 'not-reviewer');
});

it('rejects a reviewer re-deciding while the protocol is still SENT_FOR_REVIEW with already-decided', function () {
    $f = protocolFixture();
    $memberUuids = collect($f['members'])->map(fn ($m) => $m[2]->uuid)->all();
    $secondReviewer = protocolActingUser();

    [, $protocol] = createProtocolViaApi($this, $f['secretary'][1], $f['body']->uuid, [
        'participantUuids' => $memberUuids,
        'reviewerUuids' => [$f['reviewer'][2]->uuid, $secondReviewer[2]->uuid],
    ]);
    $uuid = $protocol['uuid'];
    asProtocol($this, $f['secretary'][1])->postJson("/api/v1/protocols/{$uuid}/submit")->assertOk();

    asProtocol($this, $f['reviewer'][1])->postJson("/api/v1/protocols/{$uuid}/reviewer-decide", ['approve' => true])->assertOk();

    // Same reviewer decides again while protocol is still SENT_FOR_REVIEW
    // (second reviewer hasn't decided yet) -> already-decided.
    asProtocol($this, $f['reviewer'][1])
        ->postJson("/api/v1/protocols/{$uuid}/reviewer-decide", ['approve' => true])
        ->assertStatus(422)
        ->assertJsonPath('code', 'already-decided');
});

it('rejects a non-participant signing with not-participant', function () {
    $ctx = protocolAtMembersStage($this);
    $uuid = $ctx['protocolUuid'];
    $stranger = protocolActingUser();
    $cert = Certificate::factory()->create(['employee_uuid' => $stranger[2]->uuid, 'status' => 'ACTIVE']);

    asProtocol($this, $stranger[1])->postJson("/api/v1/protocols/{$uuid}/member-sign", [
        'certificateUuid' => $cert->uuid,
        'signatureHex' => bin2hex(random_bytes(16)),
    ])->assertStatus(422)->assertJsonPath('code', 'not-participant');
});

it('rejects a member signing twice with already-signed', function () {
    $ctx = protocolAtMembersStage($this);
    $f = $ctx['fixture'];
    $uuid = $ctx['protocolUuid'];
    $member = $f['members'][0];
    $cert = Certificate::where('employee_uuid', $member[2]->uuid)->where('status', 'ACTIVE')->first();

    asProtocol($this, $member[1])->postJson("/api/v1/protocols/{$uuid}/member-sign", [
        'certificateUuid' => $cert->uuid,
        'signatureHex' => bin2hex(random_bytes(16)),
    ])->assertOk();

    asProtocol($this, $member[1])->postJson("/api/v1/protocols/{$uuid}/member-sign", [
        'certificateUuid' => $cert->uuid,
        'signatureHex' => bin2hex(random_bytes(16)),
    ])->assertStatus(422)->assertJsonPath('code', 'already-signed');
});

it('rejects signing with an invalid/foreign certificate with cert-invalid', function () {
    $ctx = protocolAtMembersStage($this);
    $f = $ctx['fixture'];
    $uuid = $ctx['protocolUuid'];
    $member = $f['members'][0];
    $otherMember = $f['members'][1];
    $foreignCert = Certificate::where('employee_uuid', $otherMember[2]->uuid)->where('status', 'ACTIVE')->first();

    asProtocol($this, $member[1])->postJson("/api/v1/protocols/{$uuid}/member-sign", [
        'certificateUuid' => $foreignCert->uuid,
        'signatureHex' => bin2hex(random_bytes(16)),
    ])->assertStatus(422)->assertJsonPath('code', 'cert-invalid');

    asProtocol($this, $member[1])->postJson("/api/v1/protocols/{$uuid}/member-sign", [
        'certificateUuid' => (string) Str::uuid(),
        'signatureHex' => bin2hex(random_bytes(16)),
    ])->assertStatus(422)->assertJsonPath('code', 'cert-invalid');
});

it('rejects a non-chairman deciding with not-chairman', function () {
    $ctx = protocolAtMembersStage($this);
    $f = $ctx['fixture'];
    $uuid = $ctx['protocolUuid'];

    foreach ($f['members'] as $member) {
        $cert = Certificate::where('employee_uuid', $member[2]->uuid)->where('status', 'ACTIVE')->first();
        asProtocol($this, $member[1])->postJson("/api/v1/protocols/{$uuid}/member-sign", [
            'certificateUuid' => $cert->uuid,
            'signatureHex' => bin2hex(random_bytes(16)),
        ])->assertOk();
    }

    $stranger = protocolActingUser();
    asProtocol($this, $stranger[1])->postJson("/api/v1/protocols/{$uuid}/chairman-decide", [
        'approve' => false,
        'note' => 'not mine to decide',
    ])->assertStatus(422)->assertJsonPath('code', 'not-chairman');
});

it('rejects updating a non-editable protocol with not-editable', function () {
    $ctx = protocolAtMembersStage($this);
    $uuid = $ctx['protocolUuid'];
    $f = $ctx['fixture'];

    asProtocol($this, $f['secretary'][1])
        ->patchJson("/api/v1/protocols/{$uuid}", ['agenda' => 'Yangi kun tartibi'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'not-editable');
});

it('resets member signatures when a bounced protocol is re-submitted', function () {
    $ctx = protocolAtMembersStage($this);
    $f = $ctx['fixture'];
    $uuid = $ctx['protocolUuid'];

    // Two of four members sign, the third declines -> MEMBERS_REJECTED.
    foreach ([0, 1] as $i) {
        $cert = Certificate::where('employee_uuid', $f['members'][$i][2]->uuid)->where('status', 'ACTIVE')->first();
        asProtocol($this, $f['members'][$i][1])->postJson("/api/v1/protocols/{$uuid}/member-sign", [
            'certificateUuid' => $cert->uuid,
            'signatureHex' => bin2hex(random_bytes(16)),
        ])->assertOk();
    }
    asProtocol($this, $f['members'][2][1])
        ->postJson("/api/v1/protocols/{$uuid}/member-decline", ['note' => 'Roziman emas'])
        ->assertOk();

    // Secretary edits the agenda and re-submits.
    asProtocol($this, $f['secretary'][1])->patchJson("/api/v1/protocols/{$uuid}", [
        'agenda' => 'Tuzatilgan kun tartibi',
    ])->assertOk();
    asProtocol($this, $f['secretary'][1])->postJson("/api/v1/protocols/{$uuid}/submit")->assertOk();

    // Every participant is PENDING again — nobody carries a signature over
    // to the edited text.
    $show = asProtocol($this, $f['secretary'][1])->getJson("/api/v1/protocols/{$uuid}")->assertOk();
    expect(collect($show->json('participants'))->pluck('decision')->unique()->all())->toBe(['PENDING']);

    // ...and the chain does NOT short-circuit to the chairman when only the
    // previously-declining member signs.
    foreach ($f['reviewers'] as $reviewer) {
        asProtocol($this, $reviewer[1])
            ->postJson("/api/v1/protocols/{$uuid}/reviewer-decide", ['approve' => true])
            ->assertOk();
    }
    $cert = Certificate::where('employee_uuid', $f['members'][2][2]->uuid)->where('status', 'ACTIVE')->first();
    $response = asProtocol($this, $f['members'][2][1])->postJson("/api/v1/protocols/{$uuid}/member-sign", [
        'certificateUuid' => $cert->uuid,
        'signatureHex' => bin2hex(random_bytes(16)),
    ])->assertOk();
    expect($response->json('status'))->toBe('SENT_TO_MEMBERS');
});

it('rejects protocol participants/reviewers who are not existing ACTIVE employees', function () {
    $f = protocolFixture();
    $memberUuids = collect($f['members'])->map(fn ($m) => $m[2]->uuid)->all();

    [$response] = createProtocolViaApi($this, $f['secretary'][1], $f['body']->uuid, [
        'participantUuids' => $memberUuids,
        'reviewerUuids' => [(string) Str::uuid()],
    ]);
    $response->assertStatus(422)->assertJsonValidationErrors('reviewerUuids.0');

    $terminated = Employee::factory()->create(['status' => 'TERMINATED']);
    [$response2] = createProtocolViaApi($this, $f['secretary'][1], $f['body']->uuid, [
        'participantUuids' => $memberUuids,
        'reviewerUuids' => [$terminated->uuid],
    ]);
    $response2->assertStatus(422)->assertJsonValidationErrors('reviewerUuids.0');
});

it('refuses to create a protocol on an ARCHIVED collegial body', function () {
    $f = protocolFixture();
    $f['body']->update(['status' => 'ARCHIVED']);

    [$response] = createProtocolViaApi($this, $f['secretary'][1], $f['body']->uuid, [
        'participantUuids' => collect($f['members'])->map(fn ($m) => $m[2]->uuid)->all(),
    ]);

    $response->assertStatus(422)->assertJsonPath('code', 'not-authorized');
});
