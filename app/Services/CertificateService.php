<?php

namespace App\Services;

use App\Exceptions\CertificateValidationException;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Business rules for the ERI Certificates module (PLAN_PHASE_E.md §2), ported
 * verbatim from the frontend mock's `uploadCertificate` / `approveCertificate`
 * / `rejectCertificate` / `revokeCertificate`
 * (frontend/dashboard/src/lib/mock-backend/index.ts). `EmployeeService::
 * terminate` calls `revoke()` per active certificate for its terminate
 * cascade (PLAN_PHASE_E.md §3).
 *
 * BLOCKED(e-imzo): every method here only ever touches certificate
 * *metadata*. Real PFX crypto validation, challenge-response ownership
 * proof, and CRL/OCSP revocation-status checks are out of scope for this
 * phase — see PLAN_PHASE_E.md §0. The frontend's `FakePfxParser.ts` stays
 * client-side; the private key never reaches this service.
 */
class CertificateService
{
    /**
     * `uploadCertificate` — `subjectPinfl` must match the employee's own
     * PINFL (TZ §6.2); `serialNumber` must be unique case-insensitively
     * across every certificate. `autoApprove` mirrors TZ §6.4's AUTO/MANUAL
     * mode — a request param for now (no persisted org-wide config flag
     * yet).
     *
     * @param  array<string, mixed>  $input  Shape: employeeUuid, serialNumber,
     *   thumbprint, subjectPinfl, subjectCommonName, subjectOrganization?,
     *   issuerName, validFrom, validTo, keyUsage[], certificateType, autoApprove?.
     */
    public function upload(array $input, User $actor): Certificate
    {
        return DB::transaction(function () use ($input, $actor) {
            $employee = Employee::where('uuid', $input['employeeUuid'])->first();

            // TZ §6.2 — the certificate owner's PINFL must equal the
            // employee's own PINFL.
            if ($employee && $employee->pinfl !== $input['subjectPinfl']) {
                throw new CertificateValidationException('pinfl-mismatch');
            }

            // Serial-uniqueness across the whole certs table (case-insensitive).
            $serialLc = mb_strtolower($input['serialNumber']);
            $taken = Certificate::whereRaw('LOWER(serial_number) = ?', [$serialLc])->exists();
            if ($taken) {
                throw new CertificateValidationException('serial-taken');
            }

            $autoApprove = (bool) ($input['autoApprove'] ?? false);
            $now = now();

            $cert = Certificate::create([
                'uuid' => (string) Str::uuid(),
                'employee_uuid' => $input['employeeUuid'],
                'serial_number' => $input['serialNumber'],
                'thumbprint' => $input['thumbprint'],
                'subject_pinfl' => $input['subjectPinfl'],
                'subject_common_name' => $input['subjectCommonName'],
                'subject_organization' => $input['subjectOrganization'] ?? null,
                'issuer_name' => $input['issuerName'],
                'valid_from' => $input['validFrom'],
                'valid_to' => $input['validTo'],
                'key_usage' => $input['keyUsage'],
                'certificate_type' => $input['certificateType'],
                'status' => $autoApprove ? 'ACTIVE' : 'PENDING_APPROVAL',
                'uploaded_by_uuid' => $actor->uuid,
                'approved_by_uuid' => $autoApprove ? $actor->uuid : null,
                'approved_at' => $autoApprove ? $now : null,
            ]);

            $label = $this->label($cert, $employee);
            AuditLogger::log($actor, 'CERTIFICATE_UPLOADED', 'certificate', $cert->uuid, $label);
            if ($autoApprove) {
                AuditLogger::log($actor, 'CERTIFICATE_APPROVED', 'certificate', $cert->uuid, $label);
            }

            return $cert->fresh();
        });
    }

    /**
     * `approveCertificate` — PENDING_APPROVAL → ACTIVE.
     */
    public function approve(string $uuid, User $actor): Certificate
    {
        return DB::transaction(function () use ($uuid, $actor) {
            $cert = Certificate::where('uuid', $uuid)->firstOrFail();
            $cert->status = 'ACTIVE';
            $cert->approved_by_uuid = $actor->uuid;
            $cert->approved_at = now();
            $cert->save();

            AuditLogger::log($actor, 'CERTIFICATE_APPROVED', 'certificate', $cert->uuid, $this->label($cert));

            return $cert->fresh();
        });
    }

    /**
     * `rejectCertificate` — PENDING_APPROVAL → REJECTED + reason.
     */
    public function reject(string $uuid, string $reason, User $actor): Certificate
    {
        return DB::transaction(function () use ($uuid, $reason, $actor) {
            $cert = Certificate::where('uuid', $uuid)->firstOrFail();
            $cert->status = 'REJECTED';
            $cert->rejection_reason = $reason;
            $cert->save();

            AuditLogger::log(
                $actor,
                'CERTIFICATE_REJECTED',
                'certificate',
                $cert->uuid,
                $this->label($cert),
                null,
                ['reason' => $reason],
            );

            return $cert->fresh();
        });
    }

    /**
     * `revokeCertificate` — any status → REVOKED + `revokedAt`/`revocationReason`.
     * Reused by `EmployeeService::terminate`'s cascade with
     * `reason = 'EMPLOYEE_TERMINATED'` (PLAN_PHASE_E.md §3).
     */
    public function revoke(string $uuid, string $reason, User $actor): Certificate
    {
        return DB::transaction(function () use ($uuid, $reason, $actor) {
            $cert = Certificate::where('uuid', $uuid)->firstOrFail();
            $cert->status = 'REVOKED';
            $cert->revoked_at = now();
            $cert->revocation_reason = $reason;
            $cert->save();

            AuditLogger::log(
                $actor,
                'CERTIFICATE_REVOKED',
                'certificate',
                $cert->uuid,
                $this->label($cert),
                null,
                ['reason' => $reason],
            );

            return $cert->fresh();
        });
    }

    /** `ERI · <employee full name>` — matches the mock's audit resourceLabel. */
    private function label(Certificate $cert, ?Employee $employee = null): string
    {
        $employee ??= Employee::where('uuid', $cert->employee_uuid)->first();

        return 'ERI · '.($employee?->full_name_generated ?? $cert->employee_uuid);
    }
}
