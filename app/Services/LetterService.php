<?php

namespace App\Services;

use App\Exceptions\LetterValidationException;
use App\Models\Assignment;
use App\Models\Certificate;
use App\Models\Employee;
use App\Models\Letter;
use App\Models\Signature;
use App\Models\Unit;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Incoming/outgoing correspondence workflow engine (PLAN_PHASE_G.md §2,
 * BPMN 3.3 / BP-3), ported line-for-line from the frontend mock's
 * `registerIncomingLetter` / `routeLetter` / `assignLetterExecutor` /
 * `startLetterExecution` / `submitLetterExecution` / `acceptLetterExecution`
 * / `signLetter` / `dispatchLetter` / `listLetters` / `isLetterOverdue`
 * (frontend/dashboard/src/lib/mock-backend/index.ts, ~2036–2566). Every
 * mutation validates against the *acting employee* uuid — the authenticated
 * user's own employee, resolved by `LetterController` and passed in here —
 * never a client-supplied "act as anyone".
 *
 * Persona resolution mirrors the mock exactly:
 * - Devonxona = the actor's user carries ROLE_DEVONXONA.
 * - Rahbar = the actor heads a root-level unit (level 0 + head_employee_uuid).
 * - Unit head = the actor heads the routed unit itself or any ancestor.
 *
 * Letter ERI signing (`sign()`) reuses the SHARED `signatures` table
 * (`resource_type='letter'`) — same ACTIVE-cert-owned-by-actor pattern as
 * `DocumentService::sign` (PLAN_PHASE_F2.md §2). BLOCKED(e-imzo):
 * `signatureHex` is the frontend's client-side fake (`FakeEriSigner.ts`) —
 * only signature *metadata* is persisted here.
 */
class LetterService
{
    private const TERMINAL_STATUSES = ['CLOSED', 'CLOSED_NO_RESPONSE', 'DISPATCHED'];

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * Year hardcoded per master §17 (documents convention) — the demo never
     * crosses a year boundary. Separate counters per prefix: incoming
     * 'K-2026/NNNN', outgoing 'CH-2026/NNNN'.
     */
    public function nextLetterNumber(string $prefix): string
    {
        $max = 0;
        foreach (Letter::where('number', 'like', "{$prefix}-2026/%")->pluck('number') as $number) {
            if (preg_match('/^'.preg_quote($prefix, '/').'-2026\/(\d{4})$/', (string) $number, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return "{$prefix}-2026/".str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Overdue = deadline strictly before today AND the letter is not terminal. */
    public function isOverdue(Letter $letter): bool
    {
        if (! $letter->deadline || in_array($letter->status, self::TERMINAL_STATUSES, true)) {
            return false;
        }

        return $letter->deadline->format('Y-m-d') < now()->format('Y-m-d');
    }

    /**
     * `listLetters` — filters: direction, status, search (number/subject/
     * externalOrg), overdueOnly.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Letter>
     */
    public function list(array $filters): Collection
    {
        $query = Letter::query();

        if (! empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $q = mb_strtolower((string) $filters['search']);
            $query->where(function ($w) use ($q) {
                $w->whereRaw('LOWER(number) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(subject) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(external_org) LIKE ?', ["%{$q}%"]);
            });
        }

        $letters = $query->orderByDesc('created_at')->get();

        if (! empty($filters['overdueOnly'])) {
            $letters = $letters->filter(fn (Letter $l) => $this->isOverdue($l))->values();
        }

        return $letters;
    }

    /**
     * `registerIncomingLetter` — Devonxona-only (`not-devonxona`). Auto
     * `K-2026/NNNN`. Registration is Devonxona's own act — audit-only,
     * notifications start at routing.
     *
     * @param  array<string, mixed>  $input  Shape: externalOrg, subject,
     *   channel, receivedAt, deadline?, requiresSignature, fileMeta?.
     */
    public function register(array $input, string $actorEmployeeUuid, User $actorUser): Letter
    {
        return DB::transaction(function () use ($input, $actorEmployeeUuid, $actorUser) {
            if (! $this->isDevonxona($actorEmployeeUuid)) {
                throw new LetterValidationException('not-devonxona');
            }

            $fileMeta = ! empty($input['fileMeta'])
                ? array_merge($input['fileMeta'], ['uploadedAt' => now()->toIso8601String()])
                : null;

            $letter = Letter::create([
                'uuid' => (string) Str::uuid(),
                'direction' => 'INCOMING',
                'number' => $this->nextLetterNumber('K'),
                'external_org' => $input['externalOrg'],
                'subject' => $input['subject'],
                'channel' => $input['channel'],
                'file_meta' => $fileMeta,
                'received_at' => $input['receivedAt'],
                'deadline' => $input['deadline'] ?? null,
                'requires_signature' => (bool) $input['requiresSignature'],
                'status' => 'REGISTERED',
                'registered_by_uuid' => $actorEmployeeUuid,
            ]);

            AuditLogger::log(
                $actorUser,
                'LETTER_REGISTERED',
                'letter',
                $letter->uuid,
                $this->label($letter),
                null,
                ['number' => $letter->number, 'channel' => $letter->channel, 'externalOrg' => $letter->external_org],
            );

            return $letter->fresh();
        });
    }

    /**
     * `routeLetter` — REGISTERED -> ROUTED. Rahbar-only (`not-rahbar`); the
     * target unit must exist and be ACTIVE (programmer/UI error otherwise —
     * the picker only offers existing ACTIVE units, mirrors the mock).
     */
    public function route(string $uuid, string $unitUuid, string $actorEmployeeUuid, User $actorUser): Letter
    {
        return DB::transaction(function () use ($uuid, $unitUuid, $actorEmployeeUuid, $actorUser) {
            $letter = Letter::where('uuid', $uuid)->firstOrFail();
            if ($letter->status !== 'REGISTERED') {
                throw new LetterValidationException('wrong-status');
            }
            if (! $this->isRahbar($actorEmployeeUuid)) {
                throw new LetterValidationException('not-rahbar');
            }

            $unit = Unit::where('uuid', $unitUuid)->first();
            if (! $unit || $unit->status !== 'ACTIVE') {
                throw new \InvalidArgumentException("Cannot route to unit: {$unitUuid}");
            }

            $letter->status = 'ROUTED';
            $letter->routed_to_unit_uuid = $unitUuid;
            $letter->save();

            $actorName = $actorUser->full_name;
            $this->notify([$unit->head_employee_uuid], 'LETTER_ROUTED', $letter, [
                'letterNumber' => $letter->number,
                'actorName' => $actorName,
            ]);
            AuditLogger::log(
                $actorUser, 'LETTER_ROUTED', 'letter', $uuid, $this->label($letter), null,
                ['unit' => $unit->name_uz],
            );

            return $letter->fresh();
        });
    }

    /**
     * `assignLetterExecutor` — ROUTED -> ASSIGNED. The unit head of the
     * routed unit or an ancestor (`not-unit-head`); the executor must
     * belong to the routed unit's subtree (open assignment or primary unit
     * there — programmer/UI error otherwise, the picker only offers
     * members, mirrors the mock).
     */
    public function assign(string $uuid, string $employeeUuid, string $actorEmployeeUuid, User $actorUser): Letter
    {
        return DB::transaction(function () use ($uuid, $employeeUuid, $actorEmployeeUuid, $actorUser) {
            $letter = Letter::where('uuid', $uuid)->firstOrFail();
            if ($letter->status !== 'ROUTED') {
                throw new LetterValidationException('wrong-status');
            }
            $routedUnit = $letter->routed_to_unit_uuid;
            if (! $this->headsUnitOrAncestor($actorEmployeeUuid, $routedUnit)) {
                throw new LetterValidationException('not-unit-head');
            }

            $routedPath = Unit::where('uuid', $routedUnit)->value('path');
            $subtreeUnitUuids = $routedPath
                ? Unit::where('path', 'like', $routedPath.'%')->pluck('uuid')->all()
                : [];
            $belongs = ! empty($subtreeUnitUuids) && (
                Assignment::where('employee_uuid', $employeeUuid)
                    ->whereNull('end_date')
                    ->whereIn('unit_uuid', $subtreeUnitUuids)
                    ->exists()
                || Employee::where('uuid', $employeeUuid)
                    ->whereIn('primary_unit_uuid', $subtreeUnitUuids)
                    ->exists()
            );
            if (! $belongs) {
                throw new \InvalidArgumentException("Executor {$employeeUuid} does not belong to unit {$routedUnit}");
            }

            $letter->status = 'ASSIGNED';
            $letter->assigned_employee_uuid = $employeeUuid;
            $letter->save();

            $actorName = $actorUser->full_name;
            $this->notify([$employeeUuid], 'LETTER_ASSIGNED', $letter, [
                'letterNumber' => $letter->number,
                'actorName' => $actorName,
            ]);
            AuditLogger::log(
                $actorUser, 'LETTER_ASSIGNED', 'letter', $uuid, $this->label($letter), null,
                ['executor' => $this->employeeNameFor($employeeUuid)],
            );

            return $letter->fresh();
        });
    }

    /**
     * `startLetterExecution` — ASSIGNED -> IN_PROGRESS (timeline realism
     * only). Audited as LETTER_EXECUTED with `context.phase = 'started'` —
     * matches the mock (no separate "started" audit verb).
     */
    public function start(string $uuid, string $actorEmployeeUuid, User $actorUser): Letter
    {
        return DB::transaction(function () use ($uuid, $actorEmployeeUuid, $actorUser) {
            $letter = Letter::where('uuid', $uuid)->firstOrFail();
            if ($letter->status !== 'ASSIGNED') {
                throw new LetterValidationException('wrong-status');
            }
            if ($letter->assigned_employee_uuid !== $actorEmployeeUuid) {
                throw new LetterValidationException('not-executor');
            }

            $letter->status = 'IN_PROGRESS';
            $letter->save();

            AuditLogger::log(
                $actorUser, 'LETTER_EXECUTED', 'letter', $uuid, $this->label($letter), null,
                ['phase' => 'started'],
            );

            return $letter->fresh();
        });
    }

    /**
     * `submitLetterExecution` — ASSIGNED/IN_PROGRESS -> EXECUTED. BPMN 7.1
     * comment-only path selected by the mere PRESENCE of `executionComment`
     * in the input (even empty triggers `comment-required`); BPMN 7.2
     * response-file/response-document path otherwise (`missing-response`
     * when neither is given).
     *
     * @param  array<string, mixed>  $input  Shape: executionComment?,
     *   responseFileMeta?, responseDocumentUuid?.
     */
    public function submit(string $uuid, array $input, string $actorEmployeeUuid, User $actorUser): Letter
    {
        return DB::transaction(function () use ($uuid, $input, $actorEmployeeUuid, $actorUser) {
            $letter = Letter::where('uuid', $uuid)->firstOrFail();
            if (! in_array($letter->status, ['ASSIGNED', 'IN_PROGRESS'], true)) {
                throw new LetterValidationException('wrong-status');
            }
            if ($letter->assigned_employee_uuid !== $actorEmployeeUuid) {
                throw new LetterValidationException('not-executor');
            }

            $commentPath = array_key_exists('executionComment', $input);
            if ($commentPath && trim((string) $input['executionComment']) === '') {
                throw new LetterValidationException('comment-required');
            }
            if (! $commentPath && empty($input['responseFileMeta']) && empty($input['responseDocumentUuid'])) {
                throw new LetterValidationException('missing-response');
            }

            $letter->status = 'EXECUTED';
            $letter->execution_comment = $commentPath ? trim((string) $input['executionComment']) : null;
            $letter->response_file_meta = ! empty($input['responseFileMeta'])
                ? array_merge($input['responseFileMeta'], ['uploadedAt' => now()->toIso8601String()])
                : null;
            $letter->response_document_uuid = $input['responseDocumentUuid'] ?? null;
            $letter->save();

            $actorName = $actorUser->full_name;
            $this->notify([$this->unitHeadOf($letter->routed_to_unit_uuid)], 'LETTER_EXECUTED', $letter, [
                'letterNumber' => $letter->number,
                'actorName' => $actorName,
            ]);
            AuditLogger::log(
                $actorUser, 'LETTER_EXECUTED', 'letter', $uuid, $this->label($letter), null,
                ['phase' => 'submitted', 'mode' => $commentPath ? 'comment' : 'response'],
            );

            return $letter->fresh();
        });
    }

    /**
     * `acceptLetterExecution` — unit-head acceptance gate (BPMN 3.3 node 8).
     * Branches:
     * - comment-only execution -> CLOSED_NO_RESPONSE (nothing to sign or
     *   dispatch, `requiresSignature` is irrelevant on this path);
     * - response present + requiresSignature -> ON_SIGNATURE (Rahbar's ERI
     *   next);
     * - response present, no signature required -> RESPONDED (ready for
     *   dispatch).
     */
    public function accept(string $uuid, string $actorEmployeeUuid, User $actorUser): Letter
    {
        return DB::transaction(function () use ($uuid, $actorEmployeeUuid, $actorUser) {
            $letter = Letter::where('uuid', $uuid)->firstOrFail();
            if ($letter->status !== 'EXECUTED') {
                throw new LetterValidationException('wrong-status');
            }
            if (! $this->headsUnitOrAncestor($actorEmployeeUuid, $letter->routed_to_unit_uuid)) {
                throw new LetterValidationException('not-unit-head');
            }

            $hasResponse = ! empty($letter->response_file_meta) || ! empty($letter->response_document_uuid);
            $status = ! $hasResponse
                ? 'CLOSED_NO_RESPONSE'
                : ($letter->requires_signature ? 'ON_SIGNATURE' : 'RESPONDED');

            $letter->status = $status;
            if ($status === 'CLOSED_NO_RESPONSE') {
                $letter->closed_at = now();
            }
            $letter->save();

            $actorName = $actorUser->full_name;
            $params = ['letterNumber' => $letter->number, 'actorName' => $actorName];
            // The executor always learns their work was accepted; the branch
            // target (Rahbar or Devonxona) learns what to do next.
            $this->notify([$letter->assigned_employee_uuid], 'LETTER_ACCEPTED', $letter, $params);
            if ($status === 'ON_SIGNATURE') {
                $this->notify([$this->rahbarOverUnit($letter->routed_to_unit_uuid)], 'LETTER_SIGN_REQUESTED', $letter, $params);
            } else {
                $this->notify($this->devonxonaEmployeeUuids(), 'LETTER_ACCEPTED', $letter, $params);
            }
            AuditLogger::log(
                $actorUser, 'LETTER_ACCEPTED', 'letter', $uuid, $this->label($letter), null,
                ['outcome' => $status],
            );

            return $letter->fresh();
        });
    }

    /**
     * `signLetter` — ON_SIGNATURE -> RESPONDED. Policy: Rahbar-only
     * (`not-rahbar`); the chosen certificate must belong to the actor AND
     * be ACTIVE (`cert-invalid`, folding "not found" / "not owned" / "not
     * active" into one code exactly like the mock). Writes to the SHARED
     * `signatures` table with `resource_type='letter'` (PLAN_PHASE_F2.md
     * §1) — mirrors `DocumentService::sign`.
     *
     * BLOCKED(e-imzo): `signatureHex` arrives from the frontend's
     * client-side `FakeEriSigner.ts` — a random hex string, not a real
     * PKCS#7 signature.
     */
    public function sign(
        string $uuid,
        string $certificateUuid,
        string $signatureHex,
        string $actorEmployeeUuid,
        User $actorUser,
    ): Letter {
        return DB::transaction(function () use ($uuid, $certificateUuid, $signatureHex, $actorEmployeeUuid, $actorUser) {
            $letter = Letter::where('uuid', $uuid)->firstOrFail();
            if ($letter->status !== 'ON_SIGNATURE') {
                throw new LetterValidationException('wrong-status');
            }
            if (! $this->isRahbar($actorEmployeeUuid)) {
                throw new LetterValidationException('not-rahbar');
            }

            $cert = Certificate::where('uuid', $certificateUuid)->first();
            if (! $cert || $cert->status !== 'ACTIVE' || $cert->employee_uuid !== $actorEmployeeUuid) {
                throw new LetterValidationException('cert-invalid');
            }

            Signature::create([
                'uuid' => (string) Str::uuid(),
                'resource_type' => 'letter',
                'resource_uuid' => $uuid,
                'employee_uuid' => $actorEmployeeUuid,
                'certificate_uuid' => $certificateUuid,
                'algorithm' => 'RSA-PKCS7',
                'signature_hex' => $signatureHex,
                'signed_at' => now(),
            ]);

            $letter->status = 'RESPONDED';
            $letter->save();

            $actorName = $actorUser->full_name;
            // The signature gate is cleared — Devonxona can dispatch now.
            $this->notify($this->devonxonaEmployeeUuids(), 'LETTER_ACCEPTED', $letter, [
                'letterNumber' => $letter->number,
                'actorName' => $actorName,
            ]);
            AuditLogger::log(
                $actorUser, 'LETTER_SIGNED', 'letter', $uuid, $this->label($letter), null,
                ['certificateSerial' => $cert->serial_number],
            );

            return $letter->fresh();
        });
    }

    /**
     * `dispatchLetter` — Devonxona dispatch (BPMN 3.3 node 10): the
     * incoming letter CLOSES and the OUTGOING reply row is created in one
     * step — auto-numbered 'CH-2026/NNNN', terminal DISPATCHED, linked back
     * via `linked_incoming_uuid`, addressee = the incoming sender, carrying
     * the response package as its `file_meta`.
     *
     * @return array{incoming: Letter, outgoing: Letter}
     */
    public function dispatch(string $uuid, string $channel, string $actorEmployeeUuid, User $actorUser): array
    {
        return DB::transaction(function () use ($uuid, $channel, $actorEmployeeUuid, $actorUser) {
            $incoming = Letter::where('uuid', $uuid)->firstOrFail();
            if ($incoming->status !== 'RESPONDED') {
                throw new LetterValidationException('wrong-status');
            }
            if (! $this->isDevonxona($actorEmployeeUuid)) {
                throw new LetterValidationException('not-devonxona');
            }

            $now = now();
            $outgoing = Letter::create([
                'uuid' => (string) Str::uuid(),
                'direction' => 'OUTGOING',
                'number' => $this->nextLetterNumber('CH'),
                'external_org' => $incoming->external_org,
                'subject' => $incoming->subject,
                'channel' => $channel,
                'file_meta' => $incoming->response_file_meta,
                'response_document_uuid' => $incoming->response_document_uuid,
                'requires_signature' => $incoming->requires_signature,
                'linked_incoming_uuid' => $incoming->uuid,
                'status' => 'DISPATCHED',
                'registered_by_uuid' => $actorEmployeeUuid,
                'dispatched_at' => $now,
            ]);

            $incoming->status = 'CLOSED';
            $incoming->dispatched_at = $now;
            $incoming->closed_at = $now;
            $incoming->save();

            $actorName = $actorUser->full_name;
            $params = ['letterNumber' => $outgoing->number, 'actorName' => $actorName];
            $this->notify(
                [$incoming->assigned_employee_uuid, $this->unitHeadOf($incoming->routed_to_unit_uuid)],
                'LETTER_DISPATCHED',
                $incoming,
                $params,
            );
            AuditLogger::log(
                $actorUser, 'LETTER_DISPATCHED', 'letter', $incoming->uuid, $this->label($incoming), null,
                ['outgoingNumber' => $outgoing->number, 'channel' => $channel],
            );
            AuditLogger::log(
                $actorUser, 'LETTER_DISPATCHED', 'letter', $outgoing->uuid, $this->label($outgoing), null,
                ['linkedIncomingNumber' => $incoming->number, 'channel' => $channel],
            );
            AuditLogger::log($actorUser, 'LETTER_CLOSED', 'letter', $incoming->uuid, $this->label($incoming));

            return ['incoming' => $incoming->fresh(), 'outgoing' => $outgoing->fresh()];
        });
    }

    // --- Persona resolution (BPMN 3.3 swim lanes) ---

    /** Devonxona = the actor's user carries ROLE_DEVONXONA. */
    private function isDevonxona(string $employeeUuid): bool
    {
        return User::where('employee_uuid', $employeeUuid)->first()?->hasRole('ROLE_DEVONXONA') ?? false;
    }

    /** Rahbar = the actor heads a root-level unit (level 0 + head_employee_uuid). */
    private function isRahbar(string $employeeUuid): bool
    {
        return Unit::where('level', 0)->where('head_employee_uuid', $employeeUuid)->exists();
    }

    /** Unit head = the actor heads the unit itself or any of its ancestors. */
    private function headsUnitOrAncestor(string $employeeUuid, ?string $unitUuid): bool
    {
        $current = $unitUuid ? Unit::where('uuid', $unitUuid)->first() : null;
        while ($current) {
            if ($current->head_employee_uuid === $employeeUuid) {
                return true;
            }
            $current = $current->parent_uuid ? Unit::where('uuid', $current->parent_uuid)->first() : null;
        }

        return false;
    }

    /**
     * "The Rahbar" for a routed letter = head of the root ancestor of the
     * routed unit — the leader of the branch the letter is being executed
     * in. Used for notification targeting (the `isRahbar` *policy* check
     * stays generic).
     */
    private function rahbarOverUnit(?string $unitUuid): ?string
    {
        $current = $unitUuid ? Unit::where('uuid', $unitUuid)->first() : null;
        while ($current && $current->parent_uuid) {
            $current = Unit::where('uuid', $current->parent_uuid)->first();
        }

        return $current?->head_employee_uuid;
    }

    /** All employees carrying ROLE_DEVONXONA (the demo seeds exactly one). */
    private function devonxonaEmployeeUuids(): array
    {
        return User::role('ROLE_DEVONXONA')->pluck('employee_uuid')->filter()->values()->all();
    }

    private function unitHeadOf(?string $unitUuid): ?string
    {
        if (! $unitUuid) {
            return null;
        }

        return Unit::where('uuid', $unitUuid)->value('head_employee_uuid');
    }

    private function employeeNameFor(string $employeeUuid): string
    {
        return Employee::where('uuid', $employeeUuid)->value('full_name_generated') ?? $employeeUuid;
    }

    private function label(Letter $letter): string
    {
        return "{$letter->number} · {$letter->subject}";
    }

    /**
     * @param  array<int, string|null>  $recipients
     * @param  array<string, string>  $params
     */
    private function notify(array $recipients, string $type, Letter $letter, array $params): void
    {
        $unique = array_unique(array_filter($recipients));
        foreach ($unique as $recipientEmployeeUuid) {
            $this->notifications->append(
                $recipientEmployeeUuid,
                $type,
                "dashboard:notifications.title.{$type}",
                $params,
                'letter',
                $letter->uuid,
            );
        }
    }
}
