<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DecideDocumentRequest;
use App\Http\Requests\EmailDocumentRequest;
use App\Http\Requests\SignDocumentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Resources\ApprovalStepResource;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\SignatureResource;
use App\Models\ApprovalStep;
use App\Models\Document;
use App\Models\Signature;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Documents + approval workflow + ERI signing (PLAN_PHASE_F1.md §3,
 * PLAN_PHASE_F2.md §2). Every mutation acts as the AUTHENTICATED user's own
 * employee — `actingEmployeeUuid()` below, never a client-supplied uuid
 * (privilege-escalation guard, PLAN_PHASE_F1.md §0/§2). Per-document
 * authorization (creator/participant/recipient/signer checks) lives in
 * `DocumentService`, not here.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents)
    {
    }

    private function actingEmployeeUuid(Request $request): string
    {
        $uuid = $request->user()->employee_uuid;
        abort_if(! $uuid, 422, 'The authenticated user has no linked employee.');

        return $uuid;
    }

    /**
     * GET /documents — filters: status, creatorUuid, recipientUuid,
     * archivedOnly, search. Matches the mock's `listDocuments`.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Document::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($creatorUuid = $request->query('creatorUuid')) {
            $query->where('creator_uuid', $creatorUuid);
        }
        if ($recipientUuid = $request->query('recipientUuid')) {
            $query->where('recipient_uuid', $recipientUuid);
        }
        if ($request->boolean('archivedOnly')) {
            $query->whereNotNull('archived_at');
        }
        if ($search = $request->query('search')) {
            $q = mb_strtolower($search);
            $query->where(function ($w) use ($q) {
                $w->whereRaw('LOWER(number) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('LOWER(title) LIKE ?', ["%{$q}%"]);
            });
        }

        return response()->json(DocumentResource::collection($query->orderByDesc('created_at')->get()));
    }

    /**
     * GET /documents/{uuid} — document + all-rounds steps + signatures.
     * Matches the mock's `getDocument`.
     */
    public function show(string $uuid): JsonResponse
    {
        $document = Document::where('uuid', $uuid)->first();
        if (! $document) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        $steps = ApprovalStep::where('document_uuid', $uuid)
            ->orderBy('round')
            ->orderBy('order_no')
            ->get();

        $signatures = Signature::where('resource_type', 'document')
            ->where('resource_uuid', $uuid)
            ->orderByDesc('signed_at')
            ->get();

        return response()->json([
            'document' => new DocumentResource($document),
            'steps' => ApprovalStepResource::collection($steps),
            'signatures' => SignatureResource::collection($signatures),
        ]);
    }

    /**
     * POST /documents. Matches the mock's `createDocument`.
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $document = $this->documents->create(
            $request->validated(),
            $this->actingEmployeeUuid($request),
            $request->user(),
        );

        return response()->json(new DocumentResource($document), 201);
    }

    /**
     * PATCH /documents/{uuid} — DRAFT/REJECTED only. Matches the mock's
     * `updateDraftDocument`.
     */
    public function update(UpdateDocumentRequest $request, string $uuid): JsonResponse
    {
        $document = $this->documents->updateDraft(
            $uuid,
            $request->validated(),
            $this->actingEmployeeUuid($request),
            $request->user(),
        );

        return response()->json(new DocumentResource($document));
    }

    /**
     * POST /documents/{uuid}/send-for-review. Matches the mock's
     * `submitDocumentForReview`.
     */
    public function sendForReview(Request $request, string $uuid): JsonResponse
    {
        $document = $this->documents->sendForReview(
            $uuid,
            $this->actingEmployeeUuid($request),
            $request->user(),
        );

        return response()->json(new DocumentResource($document));
    }

    /**
     * POST /documents/{uuid}/decide — approve/reject(+comment). Matches
     * the mock's `decideApproval` (strictly sequential).
     */
    public function decide(DecideDocumentRequest $request, string $uuid): JsonResponse
    {
        $document = $this->documents->decide(
            $uuid,
            $this->actingEmployeeUuid($request),
            $request->user(),
            $request->validated('decision'),
            $request->validated('comment'),
        );

        return response()->json(new DocumentResource($document));
    }

    /**
     * POST /documents/{uuid}/sign — APPROVED → SIGNED (PLAN_PHASE_F2.md
     * §2). `signatureHex` is the frontend's client-side fake ERI signature
     * (BLOCKED(e-imzo) — see `DocumentService::sign`). Matches the mock's
     * `signDocument`.
     */
    public function sign(SignDocumentRequest $request, string $uuid): JsonResponse
    {
        $document = $this->documents->sign(
            $uuid,
            $this->actingEmployeeUuid($request),
            $request->user(),
            $request->validated('certificateUuid'),
            $request->validated('signatureHex'),
        );

        return response()->json(new DocumentResource($document));
    }

    /**
     * POST /documents/{uuid}/accept — CLOSED (no-ERI branch). Matches the
     * mock's `acceptDocument`.
     */
    public function accept(Request $request, string $uuid): JsonResponse
    {
        $document = $this->documents->accept(
            $uuid,
            $this->actingEmployeeUuid($request),
            $request->user(),
        );

        return response()->json(new DocumentResource($document));
    }

    /**
     * POST /documents/{uuid}/email — BLOCKED(email): metadata only, no
     * real SMTP send. Matches the mock's `emailDocument`.
     */
    public function email(EmailDocumentRequest $request, string $uuid): JsonResponse
    {
        $document = $this->documents->email($uuid, $request->validated('email'), $request->user());

        return response()->json(new DocumentResource($document));
    }

    /**
     * POST /documents/{uuid}/view. Matches the mock's `recordDocumentView`
     * — never throws a business-rule error.
     */
    public function recordView(Request $request, string $uuid): JsonResponse
    {
        $this->documents->recordView($uuid, $this->actingEmployeeUuid($request), $request->user());

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /documents/{uuid} — creator's own DRAFT only. Matches the
     * mock's `deleteDocument`.
     */
    public function destroy(Request $request, string $uuid): \Illuminate\Http\Response
    {
        $this->documents->delete($uuid, $this->actingEmployeeUuid($request), $request->user());

        return response()->noContent();
    }

    /**
     * GET /me/approvals — the acting employee's decision/signature/
     * acceptance queue. Matches the mock's `listMyApprovals`.
     */
    public function myApprovals(Request $request): JsonResponse
    {
        $items = $this->documents->myApprovals($this->actingEmployeeUuid($request));

        $payload = array_map(function (array $item) {
            $row = [
                'kind' => $item['kind'],
                'document' => new DocumentResource($item['document']),
            ];
            if (isset($item['step'])) {
                $row['step'] = new ApprovalStepResource($item['step']);
            }

            return $row;
        }, $items);

        return response()->json($payload);
    }
}
