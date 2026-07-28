<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    /**
     * GET /audit — filters: resourceType, resourceUuid, actorUuid, dateFrom,
     * dateTo, limit. Newest-first. Matches the frontend mock's `listAudit`
     * (src/lib/mock-backend/index.ts) filter semantics exactly. Gated by
     * `audit.view` (ROLE_SUPER_ADMIN, ROLE_AUDITOR, ROLE_HR_ADMIN).
     *
     * Note (PLAN_PHASE_AB.md §1.1 seam note): only auth actions and unit
     * mutations write here so far. Modules still on the mock backend
     * (employees, certificates, documents, letters, tasks) keep writing to
     * the mock's own localStorage audit table and are invisible here until
     * each module migrates to the real API.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()->orderByDesc('created_at')->orderByDesc('id');

        if ($resourceType = $request->query('resourceType')) {
            $query->where('resource_type', $resourceType);
        }
        if ($resourceUuid = $request->query('resourceUuid')) {
            $query->where('resource_uuid', $resourceUuid);
        }
        if ($actorUuid = $request->query('actorUuid')) {
            $query->where('actor_uuid', $actorUuid);
        }
        if ($dateFrom = $request->query('dateFrom')) {
            $query->where('created_at', '>=', Carbon::parse($dateFrom));
        }
        if ($dateTo = $request->query('dateTo')) {
            // A bare `YYYY-MM-DD` upper bound is inclusive through end-of-day —
            // mirrors the frontend mock's date-input convention.
            $to = strlen($dateTo) === 10 ? Carbon::parse($dateTo)->endOfDay() : Carbon::parse($dateTo);
            $query->where('created_at', '<=', $to);
        }
        if ($limit = $request->query('limit')) {
            $query->limit((int) $limit);
        }

        return response()->json(AuditLogResource::collection($query->get()));
    }
}
