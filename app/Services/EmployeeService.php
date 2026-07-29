<?php

namespace App\Services;

use App\Exceptions\EmployeeValidationException;
use App\Models\Assignment;
use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Business rules for the Employees module (PLAN_PHASE_C.md §2), ported
 * verbatim from the frontend mock's `createEmployeeFull` / `updateEmployee`
 * / `terminateEmployee` / `submitProfileChangeRequest` /
 * `approveProfileRequest` (frontend/dashboard/src/lib/mock-backend/index.ts).
 */
class EmployeeService
{
    /**
     * `createEmployeeFull` — transactional user + employee + primary
     * assignment + role create. Every validation check runs BEFORE any
     * write (mirrors the mock's read-modify-write ordering) so a failed
     * create never strands an orphan row.
     *
     * @param  array<string, mixed>  $input  Shape: employee.*, orderExtract.*,
     *   positionInstruction.*, password, role.
     * @return array{employee: Employee, user: User, assignment: Assignment}
     */
    public function createFull(array $input, User $actor): array
    {
        return DB::transaction(function () use ($input, $actor) {
            $e = $input['employee'];
            $orderExtract = $input['orderExtract'] ?? null;
            $positionInstruction = $input['positionInstruction'] ?? null;

            // The certified hiring-order extract is required before the
            // profile can be created — enforced here (policy layer), not
            // just by the wizard UI.
            if (empty($orderExtract['fileName'] ?? null)) {
                throw new EmployeeValidationException('order-extract-missing');
            }
            // The job instruction ("lavozim yo'riqnomasi") is likewise mandatory.
            if (empty($positionInstruction['fileName'] ?? null)) {
                throw new EmployeeValidationException('position-instruction-missing');
            }

            // Uniqueness checks per TZ §4.4 — fail before any writes so a
            // partial create can't strand orphan records.
            $pinflTaken = Employee::where('pinfl', $e['pinfl'])
                ->where('status', '!=', 'TERMINATED')
                ->exists();
            if ($pinflTaken) {
                throw new EmployeeValidationException('pinfl-taken');
            }
            $emailLc = mb_strtolower($e['corporateEmail']);
            $emailTaken = User::whereRaw('LOWER(email) = ?', [$emailLc])->exists();
            if ($emailTaken) {
                throw new EmployeeValidationException('email-taken');
            }

            $employeeUuid = (string) Str::uuid();
            $userUuid = (string) Str::uuid();
            $fullNameGenerated = collect([$e['lastName'], $e['firstName'], $e['middleName'] ?? null])
                ->filter()
                ->implode(' ');

            $now = now();

            $employee = Employee::create([
                'uuid' => $employeeUuid,
                'user_uuid' => $userUuid,
                'last_name' => $e['lastName'],
                'first_name' => $e['firstName'],
                'middle_name' => $e['middleName'] ?? null,
                'full_name_generated' => $fullNameGenerated,
                'gender' => $e['gender'],
                'birth_date' => $e['birthDate'] ?? null,
                'pinfl' => $e['pinfl'],
                'passport_series' => $e['passportSeries'] ?? null,
                'work_phone' => $e['workPhone'] ?? null,
                'internal_extension' => $e['internalExtension'] ?? null,
                'mobile_phone' => $e['mobilePhone'],
                'corporate_email' => $e['corporateEmail'],
                'personal_email' => $e['personalEmail'] ?? null,
                'primary_unit_uuid' => $e['primaryUnitUuid'],
                'position_id' => $e['positionId'],
                'employment_type' => $e['employmentType'],
                'hire_date' => $e['hireDate'],
                'employment_order_extract' => [
                    'fileName' => $orderExtract['fileName'],
                    'fileSize' => $orderExtract['fileSize'],
                    'mimeType' => $orderExtract['mimeType'],
                    'uploadedAt' => $now->toIso8601String(),
                ],
                'position_instruction' => [
                    'fileName' => $positionInstruction['fileName'],
                    'fileSize' => $positionInstruction['fileSize'],
                    'mimeType' => $positionInstruction['mimeType'],
                    'uploadedAt' => $now->toIso8601String(),
                ],
                'status' => 'ACTIVE',
            ]);

            $user = User::create([
                'uuid' => $userUuid,
                'employee_uuid' => $employeeUuid,
                'full_name' => $fullNameGenerated,
                'email' => $e['corporateEmail'],
                'password' => Hash::make($input['password']),
                'must_change_password' => true,
                'status' => 'ACTIVE',
            ]);
            $user->assignRole($input['role']);

            $assignment = Assignment::create([
                'uuid' => (string) Str::uuid(),
                'employee_uuid' => $employeeUuid,
                'unit_uuid' => $e['primaryUnitUuid'],
                'position_id' => $e['positionId'],
                'is_primary' => true,
                'start_date' => $e['hireDate'],
                'workload_percent' => 100,
                'type' => 'PRIMARY',
            ]);

            AuditLogger::log(
                $actor,
                'CREATE',
                'employee',
                $employeeUuid,
                $fullNameGenerated,
                null,
                [
                    'orderExtractFileName' => $orderExtract['fileName'],
                    'positionInstructionFileName' => $positionInstruction['fileName'],
                ],
            );

            return [
                'employee' => $employee->fresh(),
                'user' => $user->fresh('roles'),
                'assignment' => $assignment,
            ];
        });
    }

    /**
     * `updateEmployee` — pinfl / employmentOrderExtract / positionInstruction
     * / terminationOrderExtract are immutable post-creation (legal documents
     * signed at hire time) — same lock as the mock's `Partial<Omit<...>>`.
     * `fullNameGenerated` recomputes whenever a name component changes.
     *
     * @param  array<string, mixed>  $patch  Only keys present are applied.
     */
    public function update(string $uuid, array $patch, User $actor): Employee
    {
        return DB::transaction(function () use ($uuid, $patch, $actor) {
            $employee = Employee::where('uuid', $uuid)->firstOrFail();

            $fieldMap = [
                'lastName' => 'last_name',
                'firstName' => 'first_name',
                'middleName' => 'middle_name',
                'gender' => 'gender',
                'birthDate' => 'birth_date',
                'passportSeries' => 'passport_series',
                'workPhone' => 'work_phone',
                'internalExtension' => 'internal_extension',
                'mobilePhone' => 'mobile_phone',
                'corporateEmail' => 'corporate_email',
                'personalEmail' => 'personal_email',
                'primaryUnitUuid' => 'primary_unit_uuid',
                'positionId' => 'position_id',
                'employmentType' => 'employment_type',
                'hireDate' => 'hire_date',
                'status' => 'status',
                'avatarUrl' => 'avatar_url',
            ];
            foreach ($fieldMap as $frontKey => $column) {
                if (array_key_exists($frontKey, $patch)) {
                    $employee->{$column} = $patch[$frontKey];
                }
            }

            if (array_key_exists('lastName', $patch) || array_key_exists('firstName', $patch) || array_key_exists('middleName', $patch)) {
                $employee->full_name_generated = collect([
                    $patch['lastName'] ?? $employee->last_name,
                    $patch['firstName'] ?? $employee->first_name,
                    array_key_exists('middleName', $patch) ? $patch['middleName'] : $employee->middle_name,
                ])->filter()->implode(' ');
            }

            $employee->save();

            AuditLogger::log($actor, 'UPDATE', 'employee', $employee->uuid, $employee->full_name_generated);

            return $employee->fresh();
        });
    }

    /**
     * `terminateEmployee` — the certified termination-order extract is
     * required. Flips the employee to TERMINATED only.
     *
     * TODO(phase-E): cascade cert revocation (mock's terminateEmployee
     * revokes every ACTIVE certificate for the employee) — certificates are
     * still mock-only in this phase.
     *
     * @param  array<string, mixed>|null  $orderExtract
     */
    public function terminate(string $uuid, User $actor, ?array $orderExtract): Employee
    {
        return DB::transaction(function () use ($uuid, $actor, $orderExtract) {
            if (empty($orderExtract['fileName'] ?? null)) {
                throw new EmployeeValidationException('termination-extract-missing');
            }

            $employee = Employee::where('uuid', $uuid)->firstOrFail();
            $now = now();

            $employee->status = 'TERMINATED';
            $employee->termination_date = $now;
            $employee->termination_order_extract = [
                'fileName' => $orderExtract['fileName'],
                'fileSize' => $orderExtract['fileSize'],
                'mimeType' => $orderExtract['mimeType'],
                'uploadedAt' => $now->toIso8601String(),
            ];
            $employee->save();

            AuditLogger::log(
                $actor,
                'UPDATE',
                'employee',
                $employee->uuid,
                $employee->full_name_generated,
                null,
                [
                    'status' => 'TERMINATED',
                    'terminationOrderExtractFileName' => $orderExtract['fileName'],
                ],
            );

            return $employee->fresh();
        });
    }

    /**
     * `submitProfileChangeRequest` — files a pending request for HR review.
     * `employeeUuid` is resolved server-side from the acting user's own
     * linked employee (never trusted from client input) — a small,
     * intentional hardening over the mock, which trusts the caller.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $fields
     */
    public function submitProfileChangeRequest(string $employeeUuid, array $fields, User $actor): ProfileChangeRequest
    {
        return DB::transaction(function () use ($employeeUuid, $fields, $actor) {
            $employee = Employee::where('uuid', $employeeUuid)->first();

            $request = ProfileChangeRequest::create([
                'uuid' => (string) Str::uuid(),
                'employee_uuid' => $employeeUuid,
                'fields' => $fields,
                'status' => 'PENDING',
            ]);

            AuditLogger::log(
                $actor,
                'PROFILE_CHANGE_REQUESTED',
                'profile-request',
                $request->uuid,
                $employee?->full_name_generated ?? $employeeUuid,
                $fields,
            );

            return $request->fresh();
        });
    }

    /**
     * `approveProfileRequest` — APPROVED applies the field patch to the
     * employee via `update()`; REJECTED just closes the request with a
     * reason. Both write a `PROFILE_CHANGE_APPROVED` audit entry (the
     * `context.decision` field disambiguates the outcome).
     */
    public function decideProfileChangeRequest(
        string $uuid,
        User $actor,
        string $decision,
        ?string $rejectionReason,
    ): ProfileChangeRequest {
        return DB::transaction(function () use ($uuid, $actor, $decision, $rejectionReason) {
            $request = ProfileChangeRequest::where('uuid', $uuid)->firstOrFail();

            $request->status = $decision;
            $request->rejection_reason = $decision === 'REJECTED' ? $rejectionReason : null;
            $request->reviewed_at = now();
            $request->reviewed_by_uuid = $actor->uuid;
            $request->save();

            if ($decision === 'APPROVED') {
                $patch = [];
                foreach ($request->fields as $key => $change) {
                    $patch[$key] = $change['to'];
                }
                if (! empty($patch)) {
                    $this->update($request->employee_uuid, $patch, $actor);
                }
            }

            $employee = Employee::where('uuid', $request->employee_uuid)->first();
            AuditLogger::log(
                $actor,
                'PROFILE_CHANGE_APPROVED',
                'profile-request',
                $request->uuid,
                $employee?->full_name_generated ?? $request->employee_uuid,
                null,
                ['decision' => $decision, 'rejectionReason' => $rejectionReason],
            );

            return $request->fresh();
        });
    }
}
