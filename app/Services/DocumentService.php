<?php

namespace App\Services;

use App\Exceptions\DocumentValidationException;
use App\Models\ApprovalStep;
use App\Models\Certificate;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\Signature;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\TemplateRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Document workflow engine (PLAN_PHASE_F1.md §2, PLAN_PHASE_F2.md §2),
 * ported line-for-line from the frontend mock's `createDocument` /
 * `updateDraftDocument` / `submitDocumentForReview` / `decideApproval` /
 * `signDocument` / `acceptDocument` / `emailDocument` / `recordDocumentView`
 * / `listMyApprovals` (frontend/dashboard/src/lib/mock-backend/index.ts,
 * ~1400–1900). Every mutation validates against the *acting employee* uuid
 * — the authenticated user's own employee (never a client-supplied "act as
 * anyone"), resolved by `DocumentController` and passed in here.
 *
 * ERI signing (APPROVED → SIGNED, `sign()`) requires an ACTIVE certificate
 * owned by the document's `signerUuid`. BLOCKED(e-imzo): the `signatureHex`
 * it persists is a client-generated fake — see PLAN_PHASE_F2.md §0. A
 * document with a `signerUuid` set parks at APPROVED until `sign()` is
 * called; signer-less documents close via `accept()` instead.
 */
class DocumentService
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /** Year hardcoded per master §17 — the demo never crosses a year boundary. */
    public function nextDocumentNumber(): string
    {
        $max = 0;
        foreach (Document::where('number', 'like', 'HJ-2026/%')->pluck('number') as $number) {
            if (preg_match('/^HJ-2026\/(\d{4})$/', (string) $number, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'HJ-2026/'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Employee-kind fields arrive as employee UUIDs from the wizard's
     * Combobox — resolve them to the FIO before substitution.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function resolveTemplateValues(DocumentTemplate $template, array $values): array
    {
        $resolved = $values;
        foreach ($template->fields as $field) {
            if (($field['kind'] ?? null) !== 'employee') {
                continue;
            }
            $raw = $resolved[$field['key']] ?? null;
            if (! $raw) {
                continue;
            }
            $employee = Employee::where('uuid', $raw)->first();
            if ($employee) {
                $resolved[$field['key']] = $employee->full_name_generated;
            }
        }

        return $resolved;
    }

    /**
     * @param  string[]  $participantUuids
     */
    private function assertValidParticipants(array $participantUuids, string $creatorUuid): void
    {
        $unique = array_unique($participantUuids);
        if (count($participantUuids) < 1 || count($unique) !== count($participantUuids) || in_array($creatorUuid, $unique, true)) {
            // Programmer/UI error, not a policy violation — the wizard
            // prevents all three cases before submit (mirrors the mock).
            throw new \InvalidArgumentException(
                'participantUuids must be ordered, non-empty, duplicate-free and exclude the creator',
            );
        }
    }

    private function label(Document $document): string
    {
        return "{$document->number} · {$document->title}";
    }

    /** @return ApprovalStep[]|\Illuminate\Support\Collection<int, ApprovalStep> */
    private function stepsOfRound(string $documentUuid, int $round)
    {
        return ApprovalStep::where('document_uuid', $documentUuid)
            ->where('round', $round)
            ->orderBy('order_no')
            ->get();
    }

    /**
     * "Imzolash kerakmi?" branch (BPMN 3.4 node 10/11): with a signer set,
     * ask for the ERI (F2 — this only sends the notification, no SIGNED
     * transition here); with none, ask the recipient to accept.
     */
    private function notifySignerOrRecipient(Document $document, string $actorName): void
    {
        if ($document->signer_uuid) {
            $this->notifications->notifyDocument(
                [$document->signer_uuid],
                'DOC_SIGN_REQUESTED',
                $document->uuid,
                ['docNumber' => $document->number, 'actorName' => $actorName],
            );
        } else {
            $this->notifications->notifyDocument(
                [$document->recipient_uuid],
                'DOC_SIGN_REQUESTED',
                $document->uuid,
                ['docNumber' => $document->number, 'actorName' => $actorName],
                'dashboard:notifications.title.DOC_ACCEPT_REQUESTED',
            );
        }
    }

    /**
     * `createDocument` — TEMPLATE (rendered_body) or UPLOAD (file_meta).
     * `requiresApproval` builds round-1 PENDING steps. Status always DRAFT.
     * DRAFT create is audit-only — notifications start at submit.
     *
     * @param  array<string, mixed>  $input  Shape: title, source, templateUuid?,
     *   values?, fileMeta?, confidentiality, recipientUuid, signerUuid?,
     *   requiresApproval, participantUuids?.
     */
    public function create(array $input, string $actorEmployeeUuid, User $actorUser): Document
    {
        return DB::transaction(function () use ($input, $actorEmployeeUuid, $actorUser) {
            $templateUuid = null;
            $templateCode = null;
            $values = null;
            $renderedBody = null;
            $fileMeta = null;

            if ($input['source'] === 'TEMPLATE') {
                $template = ! empty($input['templateUuid'])
                    ? DocumentTemplate::where('uuid', $input['templateUuid'])->first()
                    : null;
                if (! $template || ! isset($input['values'])) {
                    throw new \InvalidArgumentException('TEMPLATE documents require templateUuid + values');
                }
                $templateUuid = $template->uuid;
                $templateCode = $template->code;
                $values = $input['values'];
                $renderedBody = TemplateRenderer::render(
                    $template->body_template,
                    $this->resolveTemplateValues($template, $input['values']),
                );
            } else {
                if (empty($input['fileMeta'])) {
                    throw new \InvalidArgumentException('UPLOAD documents require fileMeta');
                }
                $fileMeta = array_merge($input['fileMeta'], ['uploadedAt' => now()->toIso8601String()]);
            }

            $requiresApproval = (bool) ($input['requiresApproval'] ?? false);
            if ($requiresApproval) {
                $this->assertValidParticipants($input['participantUuids'] ?? [], $actorEmployeeUuid);
            }

            $document = Document::create([
                'uuid' => (string) Str::uuid(),
                'number' => $this->nextDocumentNumber(),
                'title' => $input['title'],
                'source' => $input['source'],
                'template_uuid' => $templateUuid,
                'values' => $values,
                'rendered_body' => $renderedBody,
                'file_meta' => $fileMeta,
                'confidentiality' => $input['confidentiality'] ?? 'ODDIY',
                'creator_uuid' => $actorEmployeeUuid,
                'recipient_uuid' => $input['recipientUuid'],
                'signer_uuid' => $input['signerUuid'] ?? null,
                'requires_approval' => $requiresApproval,
                'status' => 'DRAFT',
                'round' => 1,
                'viewed_by' => [],
            ]);

            if ($requiresApproval) {
                foreach (($input['participantUuids'] ?? []) as $i => $employeeUuid) {
                    ApprovalStep::create([
                        'uuid' => (string) Str::uuid(),
                        'document_uuid' => $document->uuid,
                        'round' => 1,
                        'order_no' => $i + 1,
                        'employee_uuid' => $employeeUuid,
                        'decision' => 'PENDING',
                    ]);
                }
            }

            AuditLogger::log(
                $actorUser,
                'DOCUMENT_CREATED',
                'document',
                $document->uuid,
                $this->label($document),
                null,
                ['number' => $document->number, 'source' => $document->source, 'template' => $templateCode],
            );

            return $document->fresh();
        });
    }

    /**
     * `updateDraftDocument` — only DRAFT/REJECTED documents are editable
     * (`not-editable`), only by the creator (`not-creator`). Rebuilds the
     * PENDING steps of the *upcoming* round only when `participantUuids` is
     * given.
     *
     * @param  array<string, mixed>  $patch
     */
    public function updateDraft(string $uuid, array $patch, string $actorEmployeeUuid, User $actorUser): Document
    {
        return DB::transaction(function () use ($uuid, $patch, $actorEmployeeUuid, $actorUser) {
            $document = Document::where('uuid', $uuid)->firstOrFail();

            if (! in_array($document->status, ['DRAFT', 'REJECTED'], true)) {
                throw new DocumentValidationException('not-editable');
            }
            if ($document->creator_uuid !== $actorEmployeeUuid) {
                throw new DocumentValidationException('not-creator');
            }

            if (array_key_exists('title', $patch)) {
                $document->title = $patch['title'];
            }
            if (array_key_exists('recipientUuid', $patch)) {
                $document->recipient_uuid = $patch['recipientUuid'];
            }
            if (array_key_exists('signerUuid', $patch)) {
                $document->signer_uuid = $patch['signerUuid'] ?? null;
            }
            if (array_key_exists('confidentiality', $patch)) {
                $document->confidentiality = $patch['confidentiality'];
            }
            if (! empty($patch['values']) && $document->source === 'TEMPLATE' && $document->template_uuid) {
                $template = DocumentTemplate::where('uuid', $document->template_uuid)->first();
                if ($template) {
                    $document->values = $patch['values'];
                    $document->rendered_body = TemplateRenderer::render(
                        $template->body_template,
                        $this->resolveTemplateValues($template, $patch['values']),
                    );
                }
            }
            if (! empty($patch['fileMeta']) && $document->source === 'UPLOAD') {
                $document->file_meta = array_merge($patch['fileMeta'], ['uploadedAt' => now()->toIso8601String()]);
            }
            $document->save();

            if (! empty($patch['participantUuids']) && $document->requires_approval) {
                $this->assertValidParticipants($patch['participantUuids'], $actorEmployeeUuid);
                // For a DRAFT the upcoming round IS the current round
                // (nothing decided yet). After a rejection it's round + 1 —
                // the decided steps of the halted round stay untouched as
                // immutable history.
                $upcomingRound = $document->status === 'DRAFT' ? $document->round : $document->round + 1;
                ApprovalStep::where('document_uuid', $uuid)->where('round', $upcomingRound)->delete();
                foreach ($patch['participantUuids'] as $i => $employeeUuid) {
                    ApprovalStep::create([
                        'uuid' => (string) Str::uuid(),
                        'document_uuid' => $uuid,
                        'round' => $upcomingRound,
                        'order_no' => $i + 1,
                        'employee_uuid' => $employeeUuid,
                        'decision' => 'PENDING',
                    ]);
                }
            }

            AuditLogger::log($actorUser, 'UPDATE', 'document', $uuid, $this->label($document));

            return $document->fresh();
        });
    }

    /**
     * `submitDocumentForReview` — DRAFT/REJECTED → IN_REVIEW (or straight to
     * APPROVED when `requiresApproval` is false — BPMN 3.4 "Kelishuv
     * varaqasi kerakmi? → Yo'q"). Resubmitting a REJECTED document opens a
     * new round; steps are cloned from the halted round if the participant
     * list wasn't edited during rework.
     */
    public function sendForReview(string $uuid, string $actorEmployeeUuid, User $actorUser): Document
    {
        return DB::transaction(function () use ($uuid, $actorEmployeeUuid, $actorUser) {
            $document = Document::where('uuid', $uuid)->firstOrFail();

            if (! in_array($document->status, ['DRAFT', 'REJECTED'], true)) {
                throw new DocumentValidationException('wrong-status');
            }
            if ($document->creator_uuid !== $actorEmployeeUuid) {
                throw new DocumentValidationException('not-creator');
            }
            $actorName = $actorUser->full_name;

            if ($document->requires_approval) {
                $round = $document->round;
                if ($document->status === 'REJECTED') {
                    $round = $document->round + 1;
                    if ($this->stepsOfRound($uuid, $round)->isEmpty()) {
                        foreach ($this->stepsOfRound($uuid, $document->round) as $p) {
                            ApprovalStep::create([
                                'uuid' => (string) Str::uuid(),
                                'document_uuid' => $uuid,
                                'round' => $round,
                                'order_no' => $p->order_no,
                                'employee_uuid' => $p->employee_uuid,
                                'decision' => 'PENDING',
                            ]);
                        }
                    }
                }
                $document->status = 'IN_REVIEW';
                $document->round = $round;
                $document->sent_for_review_at = now();
                $document->save();

                $first = $this->stepsOfRound($uuid, $round)->first();
                if ($first) {
                    $this->notifications->notifyDocument(
                        [$first->employee_uuid],
                        'DOC_REVIEW_REQUESTED',
                        $document->uuid,
                        ['docNumber' => $document->number, 'actorName' => $actorName],
                    );
                }
            } else {
                $document->status = 'APPROVED';
                $document->sent_for_review_at = now();
                $document->approved_at = now();
                $document->save();

                $this->notifySignerOrRecipient($document, $actorName);
            }

            AuditLogger::log(
                $actorUser,
                'DOCUMENT_SENT_FOR_REVIEW',
                'document',
                $uuid,
                $this->label($document),
                null,
                ['round' => $document->round],
            );

            return $document->fresh();
        });
    }

    /**
     * `decideApproval` — strictly sequential: only the current round's
     * first PENDING step is actionable (`not-participant` / `out-of-order`
     * / `already-decided`). REJECTED requires a comment
     * (`comment-required`), halts the round back to the creator for
     * rework. The last APPROVED clears the chain → document APPROVED, then
     * the sign/accept gate notification fires.
     */
    public function decide(
        string $documentUuid,
        string $actorEmployeeUuid,
        User $actorUser,
        string $decision,
        ?string $comment,
    ): Document {
        return DB::transaction(function () use ($documentUuid, $actorEmployeeUuid, $actorUser, $decision, $comment) {
            $document = Document::where('uuid', $documentUuid)->firstOrFail();
            if ($document->status !== 'IN_REVIEW') {
                throw new DocumentValidationException('wrong-status');
            }

            $roundSteps = $this->stepsOfRound($documentUuid, $document->round);
            $mine = $roundSteps->firstWhere('employee_uuid', $actorEmployeeUuid);
            if (! $mine) {
                throw new DocumentValidationException('not-participant');
            }
            if ($mine->decision !== 'PENDING') {
                throw new DocumentValidationException('already-decided');
            }
            if ($roundSteps->contains(fn (ApprovalStep $s) => $s->order_no < $mine->order_no && $s->decision === 'PENDING')) {
                throw new DocumentValidationException('out-of-order');
            }
            $comment = $comment !== null ? trim($comment) : null;
            if ($decision === 'REJECTED' && ($comment === null || $comment === '')) {
                throw new DocumentValidationException('comment-required');
            }

            $mine->decision = $decision;
            $mine->comment = $comment !== '' ? $comment : null;
            $mine->decided_at = now();
            $mine->save();

            $actorName = $actorUser->full_name;

            if ($decision === 'REJECTED') {
                // Halt the round: back to the creator for rework; the
                // remaining steps stay PENDING as the frozen history of the
                // halted round.
                $document->status = 'REJECTED';
                $document->save();
                $this->notifications->notifyDocument(
                    [$document->creator_uuid],
                    'DOC_REJECTED',
                    $document->uuid,
                    ['docNumber' => $document->number, 'actorName' => $actorName],
                );
            } else {
                $this->notifications->notifyDocument(
                    [$document->creator_uuid],
                    'DOC_DECIDED',
                    $document->uuid,
                    ['docNumber' => $document->number, 'actorName' => $actorName, 'decision' => $decision],
                );
                $stillPending = $roundSteps->contains(
                    fn (ApprovalStep $s) => $s->uuid !== $mine->uuid && $s->decision === 'PENDING',
                );
                if (! $stillPending) {
                    $document->status = 'APPROVED';
                    $document->approved_at = now();
                    $document->save();
                    $this->notifications->notifyDocument(
                        [$document->creator_uuid],
                        'DOC_APPROVED',
                        $document->uuid,
                        ['docNumber' => $document->number, 'actorName' => $actorName],
                    );
                    $this->notifySignerOrRecipient($document, $actorName);
                }
            }

            AuditLogger::log(
                $actorUser,
                $decision === 'REJECTED' ? 'DOCUMENT_REJECTED' : 'DOCUMENT_APPROVED',
                'document',
                $documentUuid,
                $this->label($document),
                null,
                [
                    'decision' => $decision,
                    'order' => $mine->order_no,
                    'round' => $document->round,
                    'comment' => $comment !== '' ? $comment : null,
                ],
            );

            return $document->fresh();
        });
    }

    /**
     * `signDocument` — APPROVED → SIGNED. Policy (PLAN_PHASE_F2.md §2):
     * document must be APPROVED (`wrong-status`); actor must be the
     * document's `signerUuid` (`not-signer`); the chosen certificate must
     * belong to the actor AND be ACTIVE (`cert-invalid`, folding "not
     * found" / "not owned" / "not active" into one code exactly like the
     * mock). Writes the `signatures` row, stamps `signedAt`/`archivedAt`
     * (terminal status archives immediately, master §17), audits
     * DOCUMENT_SIGNED, and notifies the creator + recipient.
     *
     * BLOCKED(e-imzo): `signatureHex` arrives from the frontend's
     * client-side `FakeEriSigner.ts` — a random hex string, not a real
     * PKCS#7 signature. The private key never reaches this server; only
     * signature *metadata* is persisted here, and that persistence is real.
     */
    public function sign(
        string $documentUuid,
        string $actorEmployeeUuid,
        User $actorUser,
        string $certificateUuid,
        string $signatureHex,
    ): Document {
        return DB::transaction(function () use ($documentUuid, $actorEmployeeUuid, $actorUser, $certificateUuid, $signatureHex) {
            $document = Document::where('uuid', $documentUuid)->firstOrFail();
            if ($document->status !== 'APPROVED') {
                throw new DocumentValidationException('wrong-status');
            }
            if ($document->signer_uuid !== $actorEmployeeUuid) {
                throw new DocumentValidationException('not-signer');
            }

            $cert = Certificate::where('uuid', $certificateUuid)->first();
            if (! $cert || $cert->status !== 'ACTIVE' || $cert->employee_uuid !== $actorEmployeeUuid) {
                throw new DocumentValidationException('cert-invalid');
            }

            Signature::create([
                'uuid' => (string) Str::uuid(),
                'resource_type' => 'document',
                'resource_uuid' => $documentUuid,
                'employee_uuid' => $actorEmployeeUuid,
                'certificate_uuid' => $certificateUuid,
                'algorithm' => 'RSA-PKCS7',
                'signature_hex' => $signatureHex,
                'signed_at' => now(),
            ]);

            // `archivedAt` stamps immediately: the TLH's nightly archive
            // job is simulated by archiving the moment a terminal status
            // lands (master §17), same as the mock.
            $document->status = 'SIGNED';
            $document->signed_at = now();
            $document->archived_at = now();
            $document->save();

            $actorName = $actorUser->full_name;
            $this->notifications->notifyDocument(
                [$document->creator_uuid, $document->recipient_uuid],
                'DOC_SIGNED',
                $document->uuid,
                ['docNumber' => $document->number, 'actorName' => $actorName],
            );
            AuditLogger::log(
                $actorUser,
                'DOCUMENT_SIGNED',
                'document',
                $documentUuid,
                $this->label($document),
                null,
                ['certificateSerial' => $cert->serial_number],
            );

            return $document->fresh();
        });
    }

    /**
     * The no-ERI branch (BPMN 3.4 node 11.2) — the recipient accepts an
     * APPROVED, signer-less document and it closes.
     */
    public function accept(string $documentUuid, string $actorEmployeeUuid, User $actorUser): Document
    {
        return DB::transaction(function () use ($documentUuid, $actorEmployeeUuid, $actorUser) {
            $document = Document::where('uuid', $documentUuid)->firstOrFail();
            if ($document->status !== 'APPROVED' || $document->signer_uuid) {
                throw new DocumentValidationException('wrong-status');
            }
            if ($document->recipient_uuid !== $actorEmployeeUuid) {
                throw new DocumentValidationException('not-recipient');
            }

            $document->status = 'CLOSED';
            $document->closed_at = now();
            $document->archived_at = now();
            $document->save();

            $actorName = $actorUser->full_name;
            $this->notifications->notifyDocument(
                [$document->creator_uuid],
                'DOC_CLOSED',
                $document->uuid,
                ['docNumber' => $document->number, 'actorName' => $actorName],
            );
            AuditLogger::log($actorUser, 'DOCUMENT_CLOSED', 'document', $documentUuid, $this->label($document));

            return $document->fresh();
        });
    }

    /**
     * `emailDocument` — appends to the mock email-export log.
     * BLOCKED(email): no real SMTP send — metadata only.
     */
    public function email(string $uuid, string $email, User $actorUser): Document
    {
        return DB::transaction(function () use ($uuid, $email, $actorUser) {
            $document = Document::where('uuid', $uuid)->firstOrFail();
            if (! in_array($document->status, ['SIGNED', 'CLOSED'], true)) {
                throw new DocumentValidationException('wrong-status');
            }

            $document->emailed_to = array_merge($document->emailed_to ?? [], [$email]);
            $document->save();

            // BLOCKED(email): real SMTP — metadata only, no mail is sent.
            AuditLogger::log(
                $actorUser,
                'DOCUMENT_EMAILED',
                'document',
                $uuid,
                $this->label($document),
                null,
                ['email' => $email],
            );

            return $document->fresh();
        });
    }

    /**
     * `deleteDocument` — allowed ONLY for the creator's own DRAFT. This is
     * the §2.2 signed-document protection: there is deliberately NO code
     * path that removes a non-DRAFT row, regardless of role.
     */
    public function delete(string $uuid, string $actorEmployeeUuid, User $actorUser): void
    {
        DB::transaction(function () use ($uuid, $actorEmployeeUuid, $actorUser) {
            $document = Document::where('uuid', $uuid)->firstOrFail();
            if ($document->status !== 'DRAFT') {
                throw new DocumentValidationException('not-deletable');
            }
            if ($document->creator_uuid !== $actorEmployeeUuid) {
                throw new DocumentValidationException('not-creator');
            }

            $label = $this->label($document);
            // approval_steps cascade-deletes via the FK (documents ->
            // approval_steps.document_uuid, cascadeOnDelete) — no orphan
            // steps left behind.
            $document->delete();

            AuditLogger::log($actorUser, 'DELETE', 'document', $uuid, $label);
        });
    }

    /**
     * `recordDocumentView` — one record per employee. Deliberately never
     * throws a business-rule error — viewing must never fail loudly.
     */
    public function recordView(string $uuid, string $actorEmployeeUuid, User $actorUser): void
    {
        DB::transaction(function () use ($uuid, $actorEmployeeUuid, $actorUser) {
            $document = Document::where('uuid', $uuid)->first();
            if (! $document) {
                return;
            }
            $viewedBy = $document->viewed_by ?? [];
            foreach ($viewedBy as $v) {
                if (($v['employeeUuid'] ?? null) === $actorEmployeeUuid) {
                    return;
                }
            }
            $viewedBy[] = ['employeeUuid' => $actorEmployeeUuid, 'viewedAt' => now()->toIso8601String()];
            $document->viewed_by = $viewedBy;
            $document->save();

            AuditLogger::log($actorUser, 'DOCUMENT_VIEWED', 'document', $uuid, $this->label($document));
        });
    }

    /**
     * `listMyApprovals` — the acting employee's queue: PENDING decisions
     * (current round, current actionable step only), signature-pending
     * (APPROVED docs where the actor is `signerUuid` — actioned via
     * `sign()`), and acceptance-pending (signer-less APPROVED docs).
     *
     * @return array<int, array{kind: string, document: Document, step?: ApprovalStep}>
     */
    public function myApprovals(string $actorEmployeeUuid): array
    {
        $items = [];
        $documents = Document::whereIn('status', ['IN_REVIEW', 'APPROVED'])->get();

        foreach ($documents as $document) {
            if ($document->status === 'IN_REVIEW') {
                $pending = $this->stepsOfRound($document->uuid, $document->round)
                    ->firstWhere('decision', 'PENDING');
                if ($pending && $pending->employee_uuid === $actorEmployeeUuid) {
                    $items[] = ['kind' => 'decision', 'document' => $document, 'step' => $pending];
                }
            } elseif ($document->status === 'APPROVED') {
                if ($document->signer_uuid === $actorEmployeeUuid) {
                    $items[] = ['kind' => 'signature', 'document' => $document];
                } elseif (! $document->signer_uuid && $document->recipient_uuid === $actorEmployeeUuid) {
                    $items[] = ['kind' => 'acceptance', 'document' => $document];
                }
            }
        }

        usort($items, function ($a, $b) {
            $aKey = $a['document']->sent_for_review_at ?? $a['document']->created_at;
            $bKey = $b['document']->sent_for_review_at ?? $b['document']->created_at;

            return $bKey <=> $aKey;
        });

        return $items;
    }
}
