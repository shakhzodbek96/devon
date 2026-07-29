<?php

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Signature;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\PositionsSeeder;
use Database\Seeders\RolesSeeder;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| ERI document signing — PLAN_PHASE_F2.md §2, §4
|--------------------------------------------------------------------------
|
| Ports the frontend mock's `signDocument` test surface: APPROVED -> SIGNED
| with a real ACTIVE certificate owned by the document's signerUuid,
| wrong-status/not-signer/cert-invalid policy errors, the SignatureRecord
| write, DOCUMENT_SIGNED audit + DOC_SIGNED notification fan-out to
| creator+recipient, getDocument's signatures list, and RBAC.
*/

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class, PositionsSeeder::class]);
});

/**
 * @return array{0: User, 1: string, 2: Employee}
 */
function sigActingUser(string $role = 'ROLE_EMPLOYEE'): array
{
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_uuid' => $employee->uuid]);
    $employee->update(['user_uuid' => $user->uuid]);
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token, $employee->fresh()];
}

/** Same forgetGuards() workaround as DocumentsTest.php's asDoc(). */
function asSig(TestCase $test, string $token): TestCase
{
    app('auth')->forgetGuards();

    return $test->withHeader('Authorization', "Bearer {$token}");
}

/**
 * Builds an APPROVED, signer-set document ready to be signed: creator ->
 * send-for-review (no approval chain, so it lands straight on APPROVED
 * with `signerUuid` set) -> returns the document uuid + actors.
 *
 * @return array{0: string, 1: string, 2: Employee, 3: Employee, 4: Employee}
 *   [documentUuid, signerToken, signer, creator, recipient]
 */
function approvedDocumentAwaitingSignature(TestCase $test): array
{
    [, $creatorToken, $creator] = sigActingUser();
    [, , $recipient] = sigActingUser();
    [, $signerToken, $signer] = sigActingUser();

    $doc = asSig($test, $creatorToken)->postJson('/api/v1/documents', [
        'title' => 'Buyruq',
        'source' => 'UPLOAD',
        'fileMeta' => ['fileName' => 'f.pdf', 'fileSize' => 10, 'mimeType' => 'application/pdf'],
        'recipientUuid' => $recipient->uuid,
        'signerUuid' => $signer->uuid,
        'requiresApproval' => false,
    ])->json();
    asSig($test, $creatorToken)->postJson("/api/v1/documents/{$doc['uuid']}/send-for-review")->assertOk();

    return [$doc['uuid'], $signerToken, $signer, $creator, $recipient];
}

it('signs an APPROVED document with the signer\'s ACTIVE certificate, moving it to SIGNED', function () {
    [$docUuid, $signerToken, $signer, $creator, $recipient] = approvedDocumentAwaitingSignature($this);
    $cert = Certificate::factory()->create(['employee_uuid' => $signer->uuid, 'status' => 'ACTIVE']);

    $response = asSig($this, $signerToken)->postJson("/api/v1/documents/{$docUuid}/sign", [
        'certificateUuid' => $cert->uuid,
        'signatureHex' => str_repeat('ab', 64),
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'SIGNED');
    expect($response->json('signedAt'))->not->toBeNull();
    expect($response->json('archivedAt'))->not->toBeNull();

    $signature = Signature::where('resource_uuid', $docUuid)->first();
    expect($signature)->not->toBeNull();
    expect($signature->resource_type)->toBe('document');
    expect($signature->employee_uuid)->toBe($signer->uuid);
    expect($signature->certificate_uuid)->toBe($cert->uuid);
    expect($signature->algorithm)->toBe('RSA-PKCS7');
    expect($signature->signature_hex)->toBe(str_repeat('ab', 64));
    expect($signature->signed_at)->not->toBeNull();

    expect(
        AuditLog::where('action', 'DOCUMENT_SIGNED')->where('resource_uuid', $docUuid)->first()?->context,
    )->toBe(['certificateSerial' => $cert->serial_number]);

    expect(Notification::where('recipient_employee_uuid', $creator->uuid)->where('type', 'DOC_SIGNED')->exists())->toBeTrue();
    expect(Notification::where('recipient_employee_uuid', $recipient->uuid)->where('type', 'DOC_SIGNED')->exists())->toBeTrue();
});

it('exposes the signature in GET /documents/{uuid}', function () {
    [$docUuid, $signerToken, $signer] = approvedDocumentAwaitingSignature($this);
    $cert = Certificate::factory()->create(['employee_uuid' => $signer->uuid, 'status' => 'ACTIVE']);
    asSig($this, $signerToken)->postJson("/api/v1/documents/{$docUuid}/sign", [
        'certificateUuid' => $cert->uuid,
        'signatureHex' => 'deadbeef',
    ])->assertOk();

    $response = asSig($this, $signerToken)->getJson("/api/v1/documents/{$docUuid}");
    $response->assertOk();
    expect($response->json('signatures'))->toHaveCount(1);
    expect($response->json('signatures.0.certificateUuid'))->toBe($cert->uuid);
    expect($response->json('signatures.0.signatureHex'))->toBe('deadbeef');
});

it('rejects signing a document that is not APPROVED', function () {
    [, $creatorToken] = sigActingUser();
    [, , $recipient] = sigActingUser();
    [, $signerToken, $signer] = sigActingUser();
    $cert = Certificate::factory()->create(['employee_uuid' => $signer->uuid, 'status' => 'ACTIVE']);

    $doc = asSig($this, $creatorToken)->postJson('/api/v1/documents', [
        'title' => 'Draft buyruq',
        'source' => 'UPLOAD',
        'fileMeta' => ['fileName' => 'f.pdf', 'fileSize' => 10, 'mimeType' => 'application/pdf'],
        'recipientUuid' => $recipient->uuid,
        'signerUuid' => $signer->uuid,
        'requiresApproval' => false,
    ])->json();
    // Still DRAFT — never sent for review.

    asSig($this, $signerToken)
        ->postJson("/api/v1/documents/{$doc['uuid']}/sign", [
            'certificateUuid' => $cert->uuid,
            'signatureHex' => 'abcd',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'wrong-status');

    expect(Signature::count())->toBe(0);
});

it('rejects signing by anyone other than the document\'s signerUuid', function () {
    [$docUuid, , , , ] = approvedDocumentAwaitingSignature($this);
    [, $otherToken, $other] = sigActingUser();
    $cert = Certificate::factory()->create(['employee_uuid' => $other->uuid, 'status' => 'ACTIVE']);

    asSig($this, $otherToken)
        ->postJson("/api/v1/documents/{$docUuid}/sign", [
            'certificateUuid' => $cert->uuid,
            'signatureHex' => 'abcd',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'not-signer');

    expect(Document::where('uuid', $docUuid)->value('status'))->toBe('APPROVED');
});

it('rejects a certificate that is not ACTIVE', function () {
    [$docUuid, $signerToken, $signer] = approvedDocumentAwaitingSignature($this);
    $cert = Certificate::factory()->create(['employee_uuid' => $signer->uuid, 'status' => 'REVOKED']);

    asSig($this, $signerToken)
        ->postJson("/api/v1/documents/{$docUuid}/sign", [
            'certificateUuid' => $cert->uuid,
            'signatureHex' => 'abcd',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'cert-invalid');
});

it('rejects a certificate that does not belong to the signer', function () {
    [$docUuid, $signerToken] = approvedDocumentAwaitingSignature($this);
    [, , $someoneElse] = sigActingUser();
    $cert = Certificate::factory()->create(['employee_uuid' => $someoneElse->uuid, 'status' => 'ACTIVE']);

    asSig($this, $signerToken)
        ->postJson("/api/v1/documents/{$docUuid}/sign", [
            'certificateUuid' => $cert->uuid,
            'signatureHex' => 'abcd',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'cert-invalid');
});

it('rejects an unknown certificate uuid', function () {
    [$docUuid, $signerToken] = approvedDocumentAwaitingSignature($this);

    asSig($this, $signerToken)
        ->postJson("/api/v1/documents/{$docUuid}/sign", [
            'certificateUuid' => (string) Illuminate\Support\Str::uuid(),
            'signatureHex' => 'abcd',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'cert-invalid');
});

it('gates the sign route behind authentication', function () {
    $this->postJson('/api/v1/documents/00000000-0000-4000-8000-000000000000/sign', [])
        ->assertStatus(401);
});
