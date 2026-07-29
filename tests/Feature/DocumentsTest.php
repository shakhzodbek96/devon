<?php

use App\Models\ApprovalStep;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\DocumentTemplatesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\PositionsSeeder;
use Database\Seeders\RolesSeeder;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Documents + approval workflow + notifications (PLAN_PHASE_F1.md §6)
|--------------------------------------------------------------------------
|
| Ports the frontend mock's document test surface: template render +
| approval-step creation, draft edit policy (not-editable/not-creator),
| sendForReview (fresh + rework resubmit), the strictly-sequential decide
| chain (approve chain, reject+comment-required, rework round increment,
| out-of-order/already-decided/not-participant), accept→CLOSED,
| per-document authorization, notification fan-out, one-shot recordView,
| and listMyApprovals queueing.
*/

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class, PositionsSeeder::class, DocumentTemplatesSeeder::class]);
});

/**
 * @return array{0: User, 1: string, 2: Employee}
 */
function docActingUser(string $role = 'ROLE_EMPLOYEE'): array
{
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_uuid' => $employee->uuid]);
    $employee->update(['user_uuid' => $user->uuid]);
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token, $employee->fresh()];
}

/**
 * `forgetGuards()` before every switch: Laravel's AuthManager caches a
 * resolved guard's user for the lifetime of the test's container, so
 * issuing requests as a SECOND bearer token within the same test method
 * would otherwise keep resolving the FIRST token's user (a well-known
 * Laravel testing gotcha — `withHeader()` alone is not enough once more
 * than one actor appears in a single test).
 */
function asDoc(TestCase $test, string $token): TestCase
{
    app('auth')->forgetGuards();

    return $test->withHeader('Authorization', "Bearer {$token}");
}

it('creates a TEMPLATE document, renders the body, and builds round-1 approval steps', function () {
    [$creator, $creatorToken] = docActingUser();
    [, , $recipient] = docActingUser();
    [, , $approver1] = docActingUser();
    [, , $approver2] = docActingUser();
    $template = DocumentTemplate::where('code', 'ARIZA')->firstOrFail();

    $response = asDoc($this, $creatorToken)->postJson('/api/v1/documents', [
        'title' => "Ta'til arizasi",
        'source' => 'TEMPLATE',
        'templateUuid' => $template->uuid,
        'values' => ['SANA' => '01.07.2026', 'MAZMUN' => "yillik ta'til", 'SABAB' => 'dam olish'],
        'confidentiality' => 'ODDIY',
        'recipientUuid' => $recipient->uuid,
        'requiresApproval' => true,
        'participantUuids' => [$approver1->uuid, $approver2->uuid],
    ]);

    $response->assertCreated();
    $response->assertJsonPath('number', 'HJ-2026/0001');
    $response->assertJsonPath('status', 'DRAFT');
    expect($response->json('renderedBody'))
        ->toContain('01.07.2026')
        ->toContain("yillik ta'til")
        ->toContain('dam olish');

    $document = Document::where('uuid', $response->json('uuid'))->firstOrFail();
    expect($document->creator_uuid)->toBe($creator->employee_uuid);

    $steps = ApprovalStep::where('document_uuid', $document->uuid)->orderBy('order_no')->get();
    expect($steps)->toHaveCount(2);
    expect($steps[0]->employee_uuid)->toBe($approver1->uuid);
    expect($steps[0]->order_no)->toBe(1);
    expect($steps[0]->decision)->toBe('PENDING');
    expect($steps[1]->employee_uuid)->toBe($approver2->uuid);

    expect(AuditLog::where('action', 'DOCUMENT_CREATED')->where('resource_uuid', $document->uuid)->exists())->toBeTrue();
    // DRAFT create is audit-only — no notification yet.
    expect(Notification::count())->toBe(0);
});

it('auto-numbers documents sequentially as HJ-2026/NNNN', function () {
    [, $token, $employee] = docActingUser();
    [, , $recipient] = docActingUser();

    for ($i = 0; $i < 2; $i++) {
        $response = asDoc($this, $token)->postJson('/api/v1/documents', [
            'title' => "Hujjat {$i}",
            'source' => 'UPLOAD',
            'fileMeta' => ['fileName' => 'skan.pdf', 'fileSize' => 1024, 'mimeType' => 'application/pdf'],
            'recipientUuid' => $recipient->uuid,
            'requiresApproval' => false,
        ]);
        $response->assertCreated();
    }

    expect(Document::pluck('number')->sort()->values()->all())->toBe(['HJ-2026/0001', 'HJ-2026/0002']);
});

it('only lets the creator edit their own DRAFT/REJECTED document', function () {
    [$creator, $creatorToken] = docActingUser();
    [, $otherToken] = docActingUser();
    [, , $recipient] = docActingUser();

    $doc = asDoc($this, $creatorToken)->postJson('/api/v1/documents', [
        'title' => 'Draft',
        'source' => 'UPLOAD',
        'fileMeta' => ['fileName' => 'f.pdf', 'fileSize' => 10, 'mimeType' => 'application/pdf'],
        'recipientUuid' => $recipient->uuid,
        'requiresApproval' => false,
    ])->json();

    // not-creator
    asDoc($this, $otherToken)
        ->patchJson("/api/v1/documents/{$doc['uuid']}", ['title' => 'Hijacked'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'not-creator');

    // creator can edit while DRAFT
    asDoc($this, $creatorToken)
        ->patchJson("/api/v1/documents/{$doc['uuid']}", ['title' => 'Updated title'])
        ->assertOk()
        ->assertJsonPath('title', 'Updated title');

    // not-editable once IN_REVIEW/APPROVED — send it forward (no approval => straight to APPROVED)
    asDoc($this, $creatorToken)->postJson("/api/v1/documents/{$doc['uuid']}/send-for-review")->assertOk();
    asDoc($this, $creatorToken)
        ->patchJson("/api/v1/documents/{$doc['uuid']}", ['title' => 'Too late'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'not-editable');
});

it('walks a two-step sequential approval chain to APPROVED and notifies at every hop', function () {
    [$creator, $creatorToken, $creatorEmp] = docActingUser();
    [, , $recipient] = docActingUser();
    [, $a1Token, $approver1] = docActingUser();
    [, $a2Token, $approver2] = docActingUser();

    $doc = asDoc($this, $creatorToken)->postJson('/api/v1/documents', [
        'title' => 'Xizmat xati',
        'source' => 'UPLOAD',
        'fileMeta' => ['fileName' => 'f.pdf', 'fileSize' => 10, 'mimeType' => 'application/pdf'],
        'recipientUuid' => $recipient->uuid,
        'requiresApproval' => true,
        'participantUuids' => [$approver1->uuid, $approver2->uuid],
    ])->json();

    asDoc($this, $creatorToken)->postJson("/api/v1/documents/{$doc['uuid']}/send-for-review")->assertOk();
    expect(Notification::where('recipient_employee_uuid', $approver1->uuid)->where('type', 'DOC_REVIEW_REQUESTED')->exists())->toBeTrue();

    // approver2 cannot act out of order (approver1's step is still PENDING).
    asDoc($this, $a2Token)
        ->postJson("/api/v1/documents/{$doc['uuid']}/decide", ['decision' => 'APPROVED'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'out-of-order');

    // approver1 approves.
    asDoc($this, $a1Token)
        ->postJson("/api/v1/documents/{$doc['uuid']}/decide", ['decision' => 'APPROVED'])
        ->assertOk()
        ->assertJsonPath('status', 'IN_REVIEW');
    expect(Notification::where('recipient_employee_uuid', $creatorEmp->uuid)->where('type', 'DOC_DECIDED')->exists())->toBeTrue();

    // approver1 cannot decide twice.
    asDoc($this, $a1Token)
        ->postJson("/api/v1/documents/{$doc['uuid']}/decide", ['decision' => 'APPROVED'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'already-decided');

    // approver2 (now current) clears the chain -> APPROVED, recipient asked to accept (no signer).
    asDoc($this, $a2Token)
        ->postJson("/api/v1/documents/{$doc['uuid']}/decide", ['decision' => 'APPROVED'])
        ->assertOk()
        ->assertJsonPath('status', 'APPROVED');

    expect(Notification::where('recipient_employee_uuid', $creatorEmp->uuid)->where('type', 'DOC_APPROVED')->exists())->toBeTrue();
    expect(
        Notification::where('recipient_employee_uuid', $recipient->uuid)
            ->where('title_key', 'dashboard:notifications.title.DOC_ACCEPT_REQUESTED')
            ->exists(),
    )->toBeTrue();
});

it('requires a comment on REJECTED, halts the round, and increments round on rework resubmit', function () {
    [, $creatorToken] = docActingUser();
    [, , $recipient] = docActingUser();
    [, $a1Token, $approver1] = docActingUser();

    $doc = asDoc($this, $creatorToken)->postJson('/api/v1/documents', [
        'title' => 'Ariza',
        'source' => 'UPLOAD',
        'fileMeta' => ['fileName' => 'f.pdf', 'fileSize' => 10, 'mimeType' => 'application/pdf'],
        'recipientUuid' => $recipient->uuid,
        'requiresApproval' => true,
        'participantUuids' => [$approver1->uuid],
    ])->json();
    asDoc($this, $creatorToken)->postJson("/api/v1/documents/{$doc['uuid']}/send-for-review")->assertOk();

    // comment-required
    asDoc($this, $a1Token)
        ->postJson("/api/v1/documents/{$doc['uuid']}/decide", ['decision' => 'REJECTED'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'comment-required');

    asDoc($this, $a1Token)
        ->postJson("/api/v1/documents/{$doc['uuid']}/decide", ['decision' => 'REJECTED', 'comment' => 'Mos emas'])
        ->assertOk()
        ->assertJsonPath('status', 'REJECTED');

    expect(Document::where('uuid', $doc['uuid'])->value('round'))->toBe(1);

    // rework: creator resubmits -> round increments.
    asDoc($this, $creatorToken)->postJson("/api/v1/documents/{$doc['uuid']}/send-for-review")->assertOk();
    $fresh = Document::where('uuid', $doc['uuid'])->first();
    expect($fresh->status)->toBe('IN_REVIEW');
    expect($fresh->round)->toBe(2);
    expect(ApprovalStep::where('document_uuid', $doc['uuid'])->where('round', 2)->count())->toBe(1);
    // Round-1 history stays untouched.
    expect(ApprovalStep::where('document_uuid', $doc['uuid'])->where('round', 1)->first()->decision)->toBe('REJECTED');
});

it('lets only the recipient accept a signer-less APPROVED document, closing it', function () {
    [, $creatorToken, $creatorEmp] = docActingUser();
    [, $recipientToken, $recipient] = docActingUser();
    [, $otherToken] = docActingUser();

    $doc = asDoc($this, $creatorToken)->postJson('/api/v1/documents', [
        'title' => 'Bildirishnoma',
        'source' => 'UPLOAD',
        'fileMeta' => ['fileName' => 'f.pdf', 'fileSize' => 10, 'mimeType' => 'application/pdf'],
        'recipientUuid' => $recipient->uuid,
        'requiresApproval' => false,
    ])->json();
    asDoc($this, $creatorToken)->postJson("/api/v1/documents/{$doc['uuid']}/send-for-review")->assertOk();

    asDoc($this, $otherToken)
        ->postJson("/api/v1/documents/{$doc['uuid']}/accept")
        ->assertStatus(422)
        ->assertJsonPath('code', 'not-recipient');

    asDoc($this, $recipientToken)
        ->postJson("/api/v1/documents/{$doc['uuid']}/accept")
        ->assertOk()
        ->assertJsonPath('status', 'CLOSED');

    $fresh = Document::where('uuid', $doc['uuid'])->first();
    expect($fresh->closed_at)->not->toBeNull();
    expect($fresh->archived_at)->not->toBeNull();
    expect(Notification::where('recipient_employee_uuid', $creatorEmp->uuid)->where('type', 'DOC_CLOSED')->exists())->toBeTrue();
});

it('records a document view once per employee and never throws', function () {
    [, $creatorToken] = docActingUser();
    [, $viewerToken, $viewer] = docActingUser();
    [, , $recipient] = docActingUser();

    $doc = asDoc($this, $creatorToken)->postJson('/api/v1/documents', [
        'title' => 'Hujjat',
        'source' => 'UPLOAD',
        'fileMeta' => ['fileName' => 'f.pdf', 'fileSize' => 10, 'mimeType' => 'application/pdf'],
        'recipientUuid' => $recipient->uuid,
        'requiresApproval' => false,
    ])->json();

    asDoc($this, $viewerToken)->postJson("/api/v1/documents/{$doc['uuid']}/view")->assertOk();
    asDoc($this, $viewerToken)->postJson("/api/v1/documents/{$doc['uuid']}/view")->assertOk();

    $fresh = Document::where('uuid', $doc['uuid'])->first();
    expect($fresh->viewed_by)->toHaveCount(1);
    expect($fresh->viewed_by[0]['employeeUuid'])->toBe($viewer->uuid);
    expect(AuditLog::where('action', 'DOCUMENT_VIEWED')->where('resource_uuid', $doc['uuid'])->count())->toBe(1);
});

it('lists the acting employee\'s decision/acceptance queue via GET /me/approvals', function () {
    [, $creatorToken] = docActingUser();
    [, $approverToken, $approver] = docActingUser();
    [, , $recipient] = docActingUser();

    $doc = asDoc($this, $creatorToken)->postJson('/api/v1/documents', [
        'title' => 'Kelishuv kerak',
        'source' => 'UPLOAD',
        'fileMeta' => ['fileName' => 'f.pdf', 'fileSize' => 10, 'mimeType' => 'application/pdf'],
        'recipientUuid' => $recipient->uuid,
        'requiresApproval' => true,
        'participantUuids' => [$approver->uuid],
    ])->json();
    asDoc($this, $creatorToken)->postJson("/api/v1/documents/{$doc['uuid']}/send-for-review")->assertOk();

    $response = asDoc($this, $approverToken)->getJson('/api/v1/me/approvals');
    $response->assertOk();
    expect($response->json())->toHaveCount(1);
    expect($response->json('0.kind'))->toBe('decision');
    expect($response->json('0.document.uuid'))->toBe($doc['uuid']);
});

it('gates document mutation routes behind authentication', function () {
    $this->getJson('/api/v1/documents')->assertStatus(401);
    $this->postJson('/api/v1/documents', [])->assertStatus(401);
});
