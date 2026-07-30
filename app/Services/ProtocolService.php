<?php

namespace App\Services;

use App\Exceptions\ProtocolValidationException;
use App\Models\Certificate;
use App\Models\CollegialBody;
use App\Models\CollegialBodyMember;
use App\Models\Protocol;
use App\Models\ProtocolParticipant;
use App\Models\ProtocolReviewer;
use App\Models\Signature;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bayonnoma (protocol) approval-chain engine (PLAN_kollegial-organ.md §1,
 * §3, §4):
 * DRAFT -> SENT_FOR_REVIEW -> (auto, all reviewers approve) SENT_TO_MEMBERS
 * -> (auto, all members sign) SENT_TO_CHAIRMAN -> (chairman signs) APPROVED,
 * with REVIEW_REJECTED/MEMBERS_REJECTED/CHAIRMAN_REJECTED all bouncing back
 * to an editable state (§2 simplification #1 — unlike Tabel's two distinct
 * reject stations, EVERY station here uses the same "back to
 * kotibi-editable" rule). Every mutation validates against the *acting
 * employee/user* — resolved by `ProtocolController` and passed in here,
 * never a client-supplied "act as anyone" (same guard convention as
 * `TabelService`/`LetterService`).
 *
 * ERI signing (member `memberSign` / chairman `chairmanDecide` approve
 * paths) reuses the SHARED `signatures` table (`resource_type='protocol'`)
 * — same ACTIVE-cert-owned-by-actor pattern as `LetterService::sign` /
 * `TabelService::headDecide`. BLOCKED(e-imzo): `signatureHex` is the
 * frontend's client-side fake — only signature *metadata* is persisted.
 */
class ProtocolService
{
    private const EDITABLE_STATUSES = ['DRAFT', 'REVIEW_REJECTED', 'MEMBERS_REJECTED', 'CHAIRMAN_REJECTED'];

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(string $bodyUuid, array $input, User $actorUser): Protocol
    {
        return DB::transaction(function () use ($bodyUuid, $input, $actorUser) {
            $body = CollegialBody::where('uuid', $bodyUuid)->firstOrFail();

            if (! $this->isSecretaryOrManager($body, $actorUser)) {
                throw new ProtocolValidationException('not-authorized');
            }
            // An ARCHIVED body must not accept new protocols — otherwise
            // `archive()` is decorative (same rule as
            // `ConferenceBookingService::create`'s `room-archived`).
            if ($body->status !== 'ACTIVE') {
                throw new ProtocolValidationException('not-authorized');
            }

            $participantUuids = array_values(array_unique($input['participantUuids'] ?? []));
            $this->assertRosterAndQuorum($body, $participantUuids);

            $reviewerUuids = array_values(array_unique($input['reviewerUuids'] ?? []));

            $protocol = Protocol::create([
                'uuid' => (string) Str::uuid(),
                'number' => $this->nextNumber(),
                'collegial_body_uuid' => $bodyUuid,
                'protocol_date' => $input['protocolDate'],
                'template_key' => $input['templateKey'],
                'agenda' => $input['agenda'],
                'status' => 'DRAFT',
                'created_by' => $actorUser->employee_uuid,
            ]);

            $this->syncParticipants($protocol, $participantUuids);
            $this->syncReviewers($protocol, $reviewerUuids);

            AuditLogger::log($actorUser, 'PROTOCOL_CREATED', 'protocol', $protocol->uuid, $this->label($protocol));

            return $protocol->fresh(['reviewers', 'participants']);
        });
    }

    /**
     * @param  array<string, mixed>  $patch  Only keys present are applied.
     */
    public function update(string $protocolUuid, array $patch, User $actorUser): Protocol
    {
        return DB::transaction(function () use ($protocolUuid, $patch, $actorUser) {
            $protocol = Protocol::where('uuid', $protocolUuid)->lockForUpdate()->firstOrFail();

            if (! in_array($protocol->status, self::EDITABLE_STATUSES, true)) {
                throw new ProtocolValidationException('not-editable');
            }

            $body = CollegialBody::where('uuid', $protocol->collegial_body_uuid)->firstOrFail();
            if (! $this->isSecretaryOrManager($body, $actorUser)) {
                throw new ProtocolValidationException('not-authorized');
            }

            $fieldMap = [
                'protocolDate' => 'protocol_date',
                'templateKey' => 'template_key',
                'agenda' => 'agenda',
            ];
            foreach ($fieldMap as $frontKey => $column) {
                if (array_key_exists($frontKey, $patch)) {
                    $protocol->{$column} = $patch[$frontKey];
                }
            }

            if (array_key_exists('participantUuids', $patch)) {
                $participantUuids = array_values(array_unique($patch['participantUuids'] ?? []));
                $this->assertRosterAndQuorum($body, $participantUuids);
                $this->syncParticipants($protocol, $participantUuids);
            }

            if (array_key_exists('reviewerUuids', $patch)) {
                $reviewerUuids = array_values(array_unique($patch['reviewerUuids'] ?? []));
                $this->syncReviewers($protocol, $reviewerUuids);
            }

            $protocol->save();

            AuditLogger::log($actorUser, 'PROTOCOL_UPDATED', 'protocol', $protocol->uuid, $this->label($protocol));

            return $protocol->fresh(['reviewers', 'participants']);
        });
    }

    public function submitForReview(string $protocolUuid, User $actorUser): Protocol
    {
        return DB::transaction(function () use ($protocolUuid, $actorUser) {
            $protocol = Protocol::where('uuid', $protocolUuid)->lockForUpdate()->firstOrFail();

            if (! in_array($protocol->status, self::EDITABLE_STATUSES, true)) {
                throw new ProtocolValidationException('wrong-status');
            }

            $body = CollegialBody::where('uuid', $protocol->collegial_body_uuid)->firstOrFail();
            if (! $this->isSecretaryOrManager($body, $actorUser)) {
                throw new ProtocolValidationException('not-authorized');
            }

            ProtocolReviewer::where('protocol_uuid', $protocolUuid)->update([
                'decision' => 'PENDING',
                'note' => null,
                'decided_at' => null,
            ]);

            // Member decisions must reset too. Re-submitting happens after a
            // MEMBERS_REJECTED/CHAIRMAN_REJECTED bounce, where the secretary
            // has just EDITED the agenda — keeping the previous round's
            // SIGNED rows would credit those members with an ERI signature on
            // text they never saw (and let the protocol auto-advance to the
            // chairman as soon as the single declining member re-signs).
            // The `signatures` rows themselves stay — they are append-only
            // audit history; a new round writes new ones.
            ProtocolParticipant::where('protocol_uuid', $protocolUuid)->update([
                'decision' => 'PENDING',
                'note' => null,
                'decided_at' => null,
            ]);

            $protocol->status = 'SENT_FOR_REVIEW';
            $protocol->submitted_at = now();
            $protocol->members_completed_at = null;
            $protocol->chairman_signed_at = null;
            $protocol->review_note = null;
            $protocol->chairman_note = null;
            $protocol->save();

            foreach ($protocol->reviewers()->pluck('employee_uuid') as $reviewerUuid) {
                $this->notifications->append(
                    $reviewerUuid,
                    'PROTOCOL_REVIEW_REQUESTED',
                    'dashboard:notifications.title.PROTOCOL_REVIEW_REQUESTED',
                    ['number' => $protocol->number],
                    'protocol',
                    $protocol->uuid,
                );
            }

            AuditLogger::log($actorUser, 'PROTOCOL_SUBMITTED', 'protocol', $protocol->uuid, $this->label($protocol));

            return $protocol->fresh(['reviewers', 'participants']);
        });
    }

    public function reviewerDecide(
        string $protocolUuid,
        string $actorEmployeeUuid,
        User $actorUser,
        bool $approve,
        ?string $note,
    ): Protocol {
        return DB::transaction(function () use ($protocolUuid, $actorEmployeeUuid, $actorUser, $approve, $note) {
            $protocol = Protocol::where('uuid', $protocolUuid)->lockForUpdate()->firstOrFail();

            if ($protocol->status !== 'SENT_FOR_REVIEW') {
                throw new ProtocolValidationException('wrong-status');
            }

            $reviewer = ProtocolReviewer::where('protocol_uuid', $protocolUuid)
                ->where('employee_uuid', $actorEmployeeUuid)
                ->first();
            if (! $reviewer) {
                throw new ProtocolValidationException('not-reviewer');
            }
            if ($reviewer->decision !== 'PENDING') {
                throw new ProtocolValidationException('already-decided');
            }

            if ($approve) {
                $reviewer->decision = 'APPROVED';
                $reviewer->note = $note;
                $reviewer->decided_at = now();
                $reviewer->save();

                AuditLogger::log($actorUser, 'PROTOCOL_REVIEWER_APPROVED', 'protocol', $protocol->uuid, $this->label($protocol));

                $stillPending = ProtocolReviewer::where('protocol_uuid', $protocolUuid)
                    ->where('decision', '!=', 'APPROVED')
                    ->exists();

                if (! $stillPending) {
                    $protocol->status = 'SENT_TO_MEMBERS';
                    $protocol->save();

                    foreach ($protocol->participants()->pluck('employee_uuid') as $participantUuid) {
                        $this->notifications->append(
                            $participantUuid,
                            'PROTOCOL_SIGN_REQUESTED',
                            'dashboard:notifications.title.PROTOCOL_SIGN_REQUESTED',
                            ['number' => $protocol->number],
                            'protocol',
                            $protocol->uuid,
                        );
                    }

                    AuditLogger::log($actorUser, 'PROTOCOL_SENT_TO_MEMBERS', 'protocol', $protocol->uuid, $this->label($protocol));
                }
            } else {
                $reviewer->decision = 'REJECTED';
                $reviewer->note = $note;
                $reviewer->decided_at = now();
                $reviewer->save();

                $protocol->status = 'REVIEW_REJECTED';
                $protocol->review_note = $note;
                $protocol->save();

                $this->notifySecretary($protocol, 'PROTOCOL_REVIEW_REJECTED');

                AuditLogger::log($actorUser, 'PROTOCOL_REVIEWER_REJECTED', 'protocol', $protocol->uuid, $this->label($protocol));
            }

            return $protocol->fresh(['reviewers', 'participants']);
        });
    }

    public function memberSign(
        string $protocolUuid,
        string $actorEmployeeUuid,
        User $actorUser,
        string $certificateUuid,
        ?string $signatureHex,
    ): Protocol {
        return DB::transaction(function () use ($protocolUuid, $actorEmployeeUuid, $actorUser, $certificateUuid, $signatureHex) {
            $protocol = Protocol::where('uuid', $protocolUuid)->lockForUpdate()->firstOrFail();

            if ($protocol->status !== 'SENT_TO_MEMBERS') {
                throw new ProtocolValidationException('wrong-status');
            }

            $participant = ProtocolParticipant::where('protocol_uuid', $protocolUuid)
                ->where('employee_uuid', $actorEmployeeUuid)
                ->first();
            if (! $participant) {
                throw new ProtocolValidationException('not-participant');
            }
            if ($participant->decision !== 'PENDING') {
                throw new ProtocolValidationException('already-signed');
            }

            $cert = Certificate::where('uuid', $certificateUuid)->first();
            if (! $cert || $cert->status !== 'ACTIVE' || $cert->employee_uuid !== $actorEmployeeUuid) {
                throw new ProtocolValidationException('cert-invalid');
            }

            Signature::create([
                'uuid' => (string) Str::uuid(),
                'resource_type' => 'protocol',
                'resource_uuid' => $protocolUuid,
                'employee_uuid' => $actorEmployeeUuid,
                'certificate_uuid' => $certificateUuid,
                'algorithm' => 'RSA-PKCS7',
                'signature_hex' => $signatureHex,
                'signed_at' => now(),
            ]);

            $participant->decision = 'SIGNED';
            $participant->decided_at = now();
            $participant->save();

            AuditLogger::log($actorUser, 'PROTOCOL_MEMBER_SIGNED', 'protocol', $protocol->uuid, $this->label($protocol), null, [
                'certificateSerial' => $cert->serial_number,
            ]);

            $stillPending = ProtocolParticipant::where('protocol_uuid', $protocolUuid)
                ->where('decision', '!=', 'SIGNED')
                ->exists();

            if (! $stillPending) {
                $protocol->status = 'SENT_TO_CHAIRMAN';
                $protocol->members_completed_at = now();
                $protocol->save();

                $body = CollegialBody::where('uuid', $protocol->collegial_body_uuid)->first();
                if ($body) {
                    $this->notifications->append(
                        $body->chairman_employee_uuid,
                        'PROTOCOL_CHAIRMAN_REQUESTED',
                        'dashboard:notifications.title.PROTOCOL_CHAIRMAN_REQUESTED',
                        ['number' => $protocol->number],
                        'protocol',
                        $protocol->uuid,
                    );
                }

                AuditLogger::log($actorUser, 'PROTOCOL_SENT_TO_CHAIRMAN', 'protocol', $protocol->uuid, $this->label($protocol));
            }

            return $protocol->fresh(['reviewers', 'participants']);
        });
    }

    public function memberDecline(
        string $protocolUuid,
        string $actorEmployeeUuid,
        User $actorUser,
        ?string $note,
    ): Protocol {
        return DB::transaction(function () use ($protocolUuid, $actorEmployeeUuid, $actorUser, $note) {
            $protocol = Protocol::where('uuid', $protocolUuid)->lockForUpdate()->firstOrFail();

            if ($protocol->status !== 'SENT_TO_MEMBERS') {
                throw new ProtocolValidationException('wrong-status');
            }

            $participant = ProtocolParticipant::where('protocol_uuid', $protocolUuid)
                ->where('employee_uuid', $actorEmployeeUuid)
                ->first();
            if (! $participant) {
                throw new ProtocolValidationException('not-participant');
            }
            if ($participant->decision !== 'PENDING') {
                throw new ProtocolValidationException('already-signed');
            }

            $participant->decision = 'DECLINED';
            $participant->note = $note;
            $participant->decided_at = now();
            $participant->save();

            $protocol->status = 'MEMBERS_REJECTED';
            $protocol->save();

            $this->notifySecretary($protocol, 'PROTOCOL_MEMBERS_REJECTED');

            AuditLogger::log($actorUser, 'PROTOCOL_MEMBER_DECLINED', 'protocol', $protocol->uuid, $this->label($protocol));

            return $protocol->fresh(['reviewers', 'participants']);
        });
    }

    public function chairmanDecide(
        string $protocolUuid,
        string $actorEmployeeUuid,
        User $actorUser,
        bool $approve,
        ?string $certificateUuid,
        ?string $signatureHex,
        ?string $note,
    ): Protocol {
        return DB::transaction(function () use ($protocolUuid, $actorEmployeeUuid, $actorUser, $approve, $certificateUuid, $signatureHex, $note) {
            $protocol = Protocol::where('uuid', $protocolUuid)->lockForUpdate()->firstOrFail();

            $body = CollegialBody::where('uuid', $protocol->collegial_body_uuid)->first();
            if (! $body || $body->chairman_employee_uuid !== $actorEmployeeUuid) {
                throw new ProtocolValidationException('not-chairman');
            }
            if ($protocol->status !== 'SENT_TO_CHAIRMAN') {
                throw new ProtocolValidationException('wrong-status');
            }

            if ($approve) {
                $cert = $certificateUuid ? Certificate::where('uuid', $certificateUuid)->first() : null;
                if (! $cert || $cert->status !== 'ACTIVE' || $cert->employee_uuid !== $actorEmployeeUuid) {
                    throw new ProtocolValidationException('cert-invalid');
                }

                Signature::create([
                    'uuid' => (string) Str::uuid(),
                    'resource_type' => 'protocol',
                    'resource_uuid' => $protocolUuid,
                    'employee_uuid' => $actorEmployeeUuid,
                    'certificate_uuid' => $certificateUuid,
                    'algorithm' => 'RSA-PKCS7',
                    'signature_hex' => $signatureHex,
                    'signed_at' => now(),
                ]);

                $protocol->status = 'APPROVED';
                $protocol->chairman_signed_at = now();
                $protocol->finalized_at = now();
                $protocol->chairman_note = null;
                $protocol->save();

                AuditLogger::log($actorUser, 'PROTOCOL_CHAIRMAN_APPROVED', 'protocol', $protocol->uuid, $this->label($protocol), null, [
                    'certificateSerial' => $cert->serial_number,
                ]);
            } else {
                $protocol->status = 'CHAIRMAN_REJECTED';
                $protocol->chairman_note = $note;
                $protocol->save();

                $this->notifySecretary($protocol, 'PROTOCOL_CHAIRMAN_REJECTED');

                AuditLogger::log($actorUser, 'PROTOCOL_CHAIRMAN_REJECTED', 'protocol', $protocol->uuid, $this->label($protocol));
            }

            return $protocol->fresh(['reviewers', 'participants']);
        });
    }

    /**
     * `list` — filters: bodyUuid, status, box=mine (secretary/reviewer/
     * participant/chairman of the acting employee).
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Protocol>
     */
    public function list(array $filters): Collection
    {
        $query = Protocol::query();

        if (! empty($filters['bodyUuid'])) {
            $query->where('collegial_body_uuid', $filters['bodyUuid']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (($filters['box'] ?? null) === 'mine' && ! empty($filters['actingUuid'])) {
            $actingUuid = $filters['actingUuid'];

            $bodyUuids = CollegialBody::where('secretary_employee_uuid', $actingUuid)
                ->orWhere('chairman_employee_uuid', $actingUuid)
                ->pluck('uuid');
            $reviewerProtocolUuids = ProtocolReviewer::where('employee_uuid', $actingUuid)->pluck('protocol_uuid');
            $participantProtocolUuids = ProtocolParticipant::where('employee_uuid', $actingUuid)->pluck('protocol_uuid');

            $query->where(function ($q) use ($bodyUuids, $reviewerProtocolUuids, $participantProtocolUuids) {
                $q->whereIn('collegial_body_uuid', $bodyUuids)
                    ->orWhereIn('uuid', $reviewerProtocolUuids)
                    ->orWhereIn('uuid', $participantProtocolUuids);
            });
        }

        return $query->orderByDesc('created_at')->get();
    }

    /** Secretary of the body, or a `collegial.manage` permission holder. */
    private function isSecretaryOrManager(CollegialBody $body, User $actorUser): bool
    {
        return $body->secretary_employee_uuid === $actorUser->employee_uuid || $actorUser->can('collegial.manage');
    }

    /**
     * @param  string[]  $participantUuids
     */
    private function assertRosterAndQuorum(CollegialBody $body, array $participantUuids): void
    {
        $rosterUuids = CollegialBodyMember::where('collegial_body_uuid', $body->uuid)->pluck('employee_uuid')->all();
        foreach ($participantUuids as $employeeUuid) {
            if (! in_array($employeeUuid, $rosterUuids, true)) {
                throw new ProtocolValidationException('not-body-member');
            }
        }
        if (count($participantUuids) < $body->quorum_min) {
            throw new ProtocolValidationException('quorum-not-met');
        }
    }

    /**
     * @param  string[]  $participantUuids
     */
    private function syncParticipants(Protocol $protocol, array $participantUuids): void
    {
        ProtocolParticipant::where('protocol_uuid', $protocol->uuid)->delete();
        foreach ($participantUuids as $employeeUuid) {
            ProtocolParticipant::create([
                'uuid' => (string) Str::uuid(),
                'protocol_uuid' => $protocol->uuid,
                'employee_uuid' => $employeeUuid,
                'decision' => 'PENDING',
            ]);
        }
    }

    /**
     * @param  string[]  $reviewerUuids
     */
    private function syncReviewers(Protocol $protocol, array $reviewerUuids): void
    {
        ProtocolReviewer::where('protocol_uuid', $protocol->uuid)->delete();
        foreach ($reviewerUuids as $employeeUuid) {
            ProtocolReviewer::create([
                'uuid' => (string) Str::uuid(),
                'protocol_uuid' => $protocol->uuid,
                'employee_uuid' => $employeeUuid,
                'decision' => 'PENDING',
            ]);
        }
    }

    private function notifySecretary(Protocol $protocol, string $type): void
    {
        $body = CollegialBody::where('uuid', $protocol->collegial_body_uuid)->first();
        if (! $body) {
            return;
        }

        $this->notifications->append(
            $body->secretary_employee_uuid,
            $type,
            "dashboard:notifications.title.{$type}",
            ['number' => $protocol->number],
            'protocol',
            $protocol->uuid,
        );
    }

    /** `BAY-2026/NNNN` — mirrors `LetterService::nextLetterNumber`. */
    private function nextNumber(): string
    {
        $max = 0;
        foreach (Protocol::where('number', 'like', 'BAY-2026/%')->pluck('number') as $number) {
            if (preg_match('/^BAY-2026\/(\d{4})$/', (string) $number, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'BAY-2026/'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    private function label(Protocol $protocol): string
    {
        return "Bayonnoma {$protocol->number}";
    }
}
