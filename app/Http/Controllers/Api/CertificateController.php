<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RejectCertificateRequest;
use App\Http\Requests\RevokeCertificateRequest;
use App\Http\Requests\StoreCertificateRequest;
use App\Http\Resources\CertificateResource;
use App\Models\Certificate;
use App\Services\CertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ERI certificates (Flow 4, PLAN_PHASE_E.md §5). BLOCKED(e-imzo): every
 * mutation here only ever operates on certificate metadata — see
 * `CertificateService` and PLAN_PHASE_E.md §0 for the exact scope line.
 */
class CertificateController extends Controller
{
    public function __construct(private readonly CertificateService $certificates)
    {
    }

    /**
     * GET /certificates — filters: status, employeeUuid. Gated by
     * `certificates.view` (all roles), but ownership is enforced here, not
     * just by UI hiding: an actor WITHOUT `certificates.manage` only ever
     * sees their own certificates (via their linked employee), regardless
     * of an `employeeUuid` query param. Matches the mock's
     * `listCertificates`.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $query = Certificate::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($actor->can('certificates.manage')) {
            if ($employeeUuid = $request->query('employeeUuid')) {
                $query->where('employee_uuid', $employeeUuid);
            }
        } else {
            if (! $actor->employee_uuid) {
                return response()->json(CertificateResource::collection(collect()));
            }
            $query->where('employee_uuid', $actor->employee_uuid);
        }

        return response()->json(CertificateResource::collection($query->orderByDesc('created_at')->get()));
    }

    /**
     * GET /me/certificates — the acting user's own certificates. Any
     * authenticated user with a linked employee; matches the mock's
     * `listCertificates({ employeeUuid })` called with the acting employee.
     */
    public function myCertificates(Request $request): JsonResponse
    {
        $actor = $request->user();
        $rows = $actor->employee_uuid
            ? Certificate::where('employee_uuid', $actor->employee_uuid)->orderByDesc('created_at')->get()
            : collect();

        return response()->json(CertificateResource::collection($rows));
    }

    /**
     * POST /certificates — gated by `certificates.upload`. Metadata-only
     * upload (BLOCKED(e-imzo): no PFX/private-key field — see
     * `StoreCertificateRequest`). Matches the mock's `uploadCertificate`.
     */
    public function store(StoreCertificateRequest $request): JsonResponse
    {
        $cert = $this->certificates->upload($request->validated(), $request->user());

        return response()->json(new CertificateResource($cert), 201);
    }

    /**
     * POST /certificates/{uuid}/approve — gated by `certificates.manage`.
     * Matches the mock's `approveCertificate`.
     */
    public function approve(Request $request, string $uuid): JsonResponse
    {
        $cert = $this->certificates->approve($uuid, $request->user());

        return response()->json(new CertificateResource($cert));
    }

    /**
     * POST /certificates/{uuid}/reject — gated by `certificates.manage`.
     * Matches the mock's `rejectCertificate`.
     */
    public function reject(RejectCertificateRequest $request, string $uuid): JsonResponse
    {
        $cert = $this->certificates->reject($uuid, $request->validated('reason'), $request->user());

        return response()->json(new CertificateResource($cert));
    }

    /**
     * POST /certificates/{uuid}/revoke — `certificates.manage` OR the
     * certificate's own owner (PLAN_PHASE_E.md §5: "certificates.manage
     * (yoki egasi)") — an employee can self-revoke their own compromised
     * certificate. No `can:` route middleware for this reason; the
     * owner-or-manage check happens here, at the policy layer. Matches the
     * mock's `revokeCertificate`.
     */
    public function revoke(RevokeCertificateRequest $request, string $uuid): JsonResponse
    {
        $actor = $request->user();
        $cert = Certificate::where('uuid', $uuid)->firstOrFail();

        $isOwner = $actor->employee_uuid && $actor->employee_uuid === $cert->employee_uuid;
        if (! $actor->can('certificates.manage') && ! $isOwner) {
            abort(403);
        }

        $updated = $this->certificates->revoke($uuid, $request->validated('reason'), $actor);

        return response()->json(new CertificateResource($updated));
    }
}
