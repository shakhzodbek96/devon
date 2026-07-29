<?php

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| ERI Certificates (Flow 4) — PLAN_PHASE_E.md §2, §3, §4, §5, §8
|--------------------------------------------------------------------------
|
| Ports the frontend mock's certificate test surface: upload (pinfl-mismatch,
| serial-taken, auto vs manual approve), approve/reject/revoke status+audit,
| list ownership filtering, the terminate→cascade revoke, the daily expiry
| command, and RBAC (a plain employee cannot approve another cert).
*/

beforeEach(function () {
    $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
});

function certActingUser(string $role, ?string $employeeUuid = null): array
{
    $user = User::factory()->create(['employee_uuid' => $employeeUuid]);
    $user->assignRole($role);
    $token = $user->createToken('test')->plainTextToken;

    return [$user, $token];
}

function certPayload(Employee $employee, array $overrides = []): array
{
    return array_merge([
        'employeeUuid' => $employee->uuid,
        'serialNumber' => strtoupper(Str::random(16)),
        'thumbprint' => strtoupper(Str::random(40)),
        'subjectPinfl' => $employee->pinfl,
        'subjectCommonName' => $employee->full_name_generated,
        'subjectOrganization' => 'Devon Demo',
        'issuerName' => 'YANGI TEXNOLOGIYALAR ILMIY-AXBOROT MARKAZI AJ',
        'validFrom' => now()->subDay()->toIso8601String(),
        'validTo' => now()->addYear()->toIso8601String(),
        'keyUsage' => ['digitalSignature', 'nonRepudiation'],
        'certificateType' => 'SIGNING',
    ], $overrides);
}

// === upload ===

it('requires authentication and gates certificates.upload for the upload endpoint', function () {
    $employee = Employee::factory()->create();

    $this->postJson('/api/v1/certificates', certPayload($employee))->assertStatus(401);
});

it('uploads a certificate as PENDING_APPROVAL by default and audits CERTIFICATE_UPLOADED', function () {
    [$actor, $token] = certActingUser('ROLE_HR_ADMIN');
    $employee = Employee::factory()->create();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/certificates', certPayload($employee));

    $response->assertStatus(201)
        ->assertJsonPath('status', 'PENDING_APPROVAL')
        ->assertJsonPath('employeeUuid', $employee->uuid)
        ->assertJsonPath('approvedByUuid', null);

    $uuid = $response->json('uuid');
    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'CERTIFICATE_UPLOADED',
        'resource_type' => 'certificate',
        'resource_uuid' => $uuid,
    ]);
    $this->assertDatabaseMissing('audit_logs', [
        'resource_uuid' => $uuid,
        'action' => 'CERTIFICATE_APPROVED',
    ]);
});

it('auto-approves to ACTIVE when autoApprove=true and audits both UPLOADED and APPROVED', function () {
    [$actor, $token] = certActingUser('ROLE_HR_ADMIN');
    $employee = Employee::factory()->create();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/certificates', certPayload($employee, ['autoApprove' => true]));

    $response->assertStatus(201)
        ->assertJsonPath('status', 'ACTIVE')
        ->assertJsonPath('approvedByUuid', $actor->uuid);

    $uuid = $response->json('uuid');
    $this->assertDatabaseHas('audit_logs', [
        'resource_uuid' => $uuid,
        'action' => 'CERTIFICATE_UPLOADED',
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'resource_uuid' => $uuid,
        'action' => 'CERTIFICATE_APPROVED',
    ]);
});

it('rejects an upload whose subjectPinfl does not match the employee with code pinfl-mismatch', function () {
    [, $token] = certActingUser('ROLE_HR_ADMIN');
    $employee = Employee::factory()->create(['pinfl' => '50000000000011']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/certificates', certPayload($employee, ['subjectPinfl' => '50000000000099']));

    $response->assertStatus(422)->assertJsonPath('code', 'pinfl-mismatch');
    $this->assertDatabaseCount('digital_certificates', 0);
});

it('rejects an upload with a case-insensitively duplicate serial number with code serial-taken', function () {
    [, $token] = certActingUser('ROLE_HR_ADMIN');
    $employee = Employee::factory()->create();
    Certificate::factory()->create(['employee_uuid' => $employee->uuid, 'serial_number' => 'ABC123']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/certificates', certPayload($employee, ['serialNumber' => 'abc123']));

    $response->assertStatus(422)->assertJsonPath('code', 'serial-taken');
    $this->assertDatabaseCount('digital_certificates', 1);
});

it('forbids ROLE_HR_OPERATOR (view-only on certificates) from uploading (403)', function () {
    [, $token] = certActingUser('ROLE_HR_OPERATOR');
    $employee = Employee::factory()->create();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/certificates', certPayload($employee))
        ->assertStatus(403);
});

// === approve / reject ===

it('approves a PENDING_APPROVAL certificate to ACTIVE and audits CERTIFICATE_APPROVED', function () {
    [$actor, $token] = certActingUser('ROLE_HR_ADMIN');
    $cert = Certificate::factory()->create(['status' => 'PENDING_APPROVAL', 'approved_by_uuid' => null, 'approved_at' => null]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/certificates/{$cert->uuid}/approve");

    $response->assertOk()->assertJsonPath('status', 'ACTIVE')->assertJsonPath('approvedByUuid', $actor->uuid);
    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'CERTIFICATE_APPROVED',
        'resource_type' => 'certificate',
        'resource_uuid' => $cert->uuid,
    ]);
});

it('rejects a certificate with a reason and audits CERTIFICATE_REJECTED', function () {
    [$actor, $token] = certActingUser('ROLE_HR_ADMIN');
    $cert = Certificate::factory()->create(['status' => 'PENDING_APPROVAL']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/certificates/{$cert->uuid}/reject", ['reason' => 'Muddati noto\'g\'ri']);

    $response->assertOk()->assertJsonPath('status', 'REJECTED')->assertJsonPath('rejectionReason', "Muddati noto'g'ri");
    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'CERTIFICATE_REJECTED',
        'resource_type' => 'certificate',
        'resource_uuid' => $cert->uuid,
    ]);
});

it('forbids a plain employee (no certificates.manage) from approving another certificate (403)', function () {
    $owner = Employee::factory()->create();
    [, $token] = certActingUser('ROLE_EMPLOYEE', $owner->uuid);
    $otherEmployee = Employee::factory()->create();
    $cert = Certificate::factory()->create(['employee_uuid' => $otherEmployee->uuid, 'status' => 'PENDING_APPROVAL']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/certificates/{$cert->uuid}/approve")
        ->assertStatus(403);
});

// === revoke (manage OR owner) ===

it('lets certificates.manage revoke any ACTIVE certificate with a reason and audits CERTIFICATE_REVOKED', function () {
    [$actor, $token] = certActingUser('ROLE_HR_ADMIN');
    $cert = Certificate::factory()->create(['status' => 'ACTIVE']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/certificates/{$cert->uuid}/revoke", ['reason' => 'COMPROMISED']);

    $response->assertOk()
        ->assertJsonPath('status', 'REVOKED')
        ->assertJsonPath('revocationReason', 'COMPROMISED');
    expect($response->json('revokedAt'))->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'CERTIFICATE_REVOKED',
        'resource_type' => 'certificate',
        'resource_uuid' => $cert->uuid,
    ]);
});

it('lets the certificate owner revoke their own certificate without certificates.manage', function () {
    $owner = Employee::factory()->create();
    [$actor, $token] = certActingUser('ROLE_EMPLOYEE', $owner->uuid);
    $cert = Certificate::factory()->create(['employee_uuid' => $owner->uuid, 'status' => 'ACTIVE']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/certificates/{$cert->uuid}/revoke", ['reason' => 'COMPROMISED']);

    $response->assertOk()->assertJsonPath('status', 'REVOKED');
    $this->assertDatabaseHas('audit_logs', ['actor_uuid' => $actor->uuid, 'action' => 'CERTIFICATE_REVOKED']);
});

it('forbids a non-owner without certificates.manage from revoking someone else\'s certificate (403)', function () {
    $owner = Employee::factory()->create();
    [, $token] = certActingUser('ROLE_EMPLOYEE', Employee::factory()->create()->uuid);
    $cert = Certificate::factory()->create(['employee_uuid' => $owner->uuid, 'status' => 'ACTIVE']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/certificates/{$cert->uuid}/revoke", ['reason' => 'COMPROMISED'])
        ->assertStatus(403);
});

it('rejects a manual revoke with reason EMPLOYEE_TERMINATED (reserved for the terminate cascade)', function () {
    [, $token] = certActingUser('ROLE_HR_ADMIN');
    $cert = Certificate::factory()->create(['status' => 'ACTIVE']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/certificates/{$cert->uuid}/revoke", ['reason' => 'EMPLOYEE_TERMINATED'])
        ->assertStatus(422);
});

// === list ownership ===

it('shows only the acting employee\'s own certificates on GET /certificates for a non-manage role', function () {
    $me = Employee::factory()->create();
    $someoneElse = Employee::factory()->create();
    [, $token] = certActingUser('ROLE_EMPLOYEE', $me->uuid);
    $mine = Certificate::factory()->create(['employee_uuid' => $me->uuid]);
    Certificate::factory()->create(['employee_uuid' => $someoneElse->uuid]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/certificates');

    $response->assertOk();
    $uuids = collect($response->json())->pluck('uuid');
    expect($uuids)->toContain($mine->uuid)->and($uuids)->toHaveCount(1);
});

it('shows every certificate on GET /certificates for a certificates.manage role', function () {
    [, $token] = certActingUser('ROLE_HR_ADMIN');
    Certificate::factory()->count(3)->create();

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/certificates');

    $response->assertOk();
    expect($response->json())->toHaveCount(3);
});

it('returns only the acting user\'s own certificates on GET /me/certificates', function () {
    $me = Employee::factory()->create();
    [, $token] = certActingUser('ROLE_EMPLOYEE', $me->uuid);
    $mine = Certificate::factory()->count(2)->create(['employee_uuid' => $me->uuid]);
    Certificate::factory()->create();

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/me/certificates');

    $response->assertOk();
    expect($response->json())->toHaveCount(2);
    expect(collect($response->json())->pluck('uuid')->sort()->values())
        ->toEqual($mine->pluck('uuid')->sort()->values());
});

// === terminate cascade ===

it('cascades: terminating an employee revokes every ACTIVE certificate with reason EMPLOYEE_TERMINATED', function () {
    [$actor, $token] = certActingUser('ROLE_SUPER_ADMIN');
    $employee = Employee::factory()->create();
    $activeCert = Certificate::factory()->create(['employee_uuid' => $employee->uuid, 'status' => 'ACTIVE']);
    $pendingCert = Certificate::factory()->create(['employee_uuid' => $employee->uuid, 'status' => 'PENDING_APPROVAL']);
    $alreadyRevoked = Certificate::factory()->create([
        'employee_uuid' => $employee->uuid,
        'status' => 'REVOKED',
        'revoked_at' => now()->subDay(),
        'revocation_reason' => 'MANUAL',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/employees/{$employee->uuid}/terminate", [
            'orderExtract' => ['fileName' => 'termination.pdf', 'fileSize' => 100, 'mimeType' => 'application/pdf'],
        ]);

    $response->assertOk()->assertJsonPath('status', 'TERMINATED');

    $this->assertDatabaseHas('digital_certificates', [
        'uuid' => $activeCert->uuid,
        'status' => 'REVOKED',
        'revocation_reason' => 'EMPLOYEE_TERMINATED',
    ]);
    // Non-ACTIVE certificates are left untouched by the cascade.
    $this->assertDatabaseHas('digital_certificates', ['uuid' => $pendingCert->uuid, 'status' => 'PENDING_APPROVAL']);
    $this->assertDatabaseHas('digital_certificates', ['uuid' => $alreadyRevoked->uuid, 'revocation_reason' => 'MANUAL']);

    $this->assertDatabaseHas('audit_logs', [
        'actor_uuid' => $actor->uuid,
        'action' => 'CERTIFICATE_REVOKED',
        'resource_type' => 'certificate',
        'resource_uuid' => $activeCert->uuid,
    ]);
    $certAudit = AuditLog::where('resource_uuid', $activeCert->uuid)->where('action', 'CERTIFICATE_REVOKED')->firstOrFail();
    expect($certAudit->context)->toBe(['reason' => 'EMPLOYEE_TERMINATED']);

    // Cascade audit entries precede the employee's own UPDATE audit entry —
    // matches the mock's `terminateEmployee` ordering.
    $employeeAudit = AuditLog::where('resource_uuid', $employee->uuid)->where('action', 'UPDATE')->firstOrFail();
    expect($certAudit->id)->toBeLessThan($employeeAudit->id);
});

// === expiry command ===

it('flips ACTIVE certificates past their valid_to to EXPIRED via the daily command and audits it', function () {
    $this->seed([AdminUserSeeder::class]);
    $pastDue = Certificate::factory()->create(['status' => 'ACTIVE', 'valid_to' => now()->subDay()]);
    $stillValid = Certificate::factory()->create(['status' => 'ACTIVE', 'valid_to' => now()->addMonth()]);
    $alreadyExpired = Certificate::factory()->create(['status' => 'EXPIRED', 'valid_to' => now()->subYear()]);

    Artisan::call('certificates:expire');

    $this->assertDatabaseHas('digital_certificates', ['uuid' => $pastDue->uuid, 'status' => 'EXPIRED']);
    $this->assertDatabaseHas('digital_certificates', ['uuid' => $stillValid->uuid, 'status' => 'ACTIVE']);
    $this->assertDatabaseHas('digital_certificates', ['uuid' => $alreadyExpired->uuid, 'status' => 'EXPIRED']);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'CERTIFICATE_REVOKED',
        'resource_type' => 'certificate',
        'resource_uuid' => $pastDue->uuid,
    ]);
    $this->assertDatabaseMissing('audit_logs', [
        'resource_type' => 'certificate',
        'resource_uuid' => $stillValid->uuid,
    ]);
});
