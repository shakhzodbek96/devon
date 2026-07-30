<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateTabelRequest;
use App\Http\Requests\TabelHeadDecideRequest;
use App\Http\Requests\TabelHrDecideRequest;
use App\Http\Requests\UpdateTabelEntriesRequest;
use App\Http\Resources\TabelResource;
use App\Models\Tabel;
use App\Services\TabelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Monthly timesheet approval chain (PLAN_tabel-davomat.md §3, §1). Every
 * mutation acts as the AUTHENTICATED user's own employee —
 * `actingEmployeeUuid()` below, never a client-supplied uuid. Per-tabel
 * authorization (unit head / HR) lives in `TabelService`, not here — no
 * `can:` route middleware on generate/submit/head-decide/hr-decide/entries.
 * `tabel-registry` is the one exception (`can:attendance.view-registry`).
 */
class TabelController extends Controller
{
    public function __construct(private readonly TabelService $tabels)
    {
    }

    private function actingEmployeeUuid(Request $request): string
    {
        $uuid = $request->user()->employee_uuid;
        abort_if(! $uuid, 422, 'The authenticated user has no linked employee.');

        return $uuid;
    }

    /**
     * GET /tabels — filters: unitUuid, period, box=mine.
     */
    public function index(Request $request): JsonResponse
    {
        $tabels = $this->tabels->list([
            'unitUuid' => $request->query('unitUuid'),
            'period' => $request->query('period'),
            'box' => $request->query('box'),
            'actingUuid' => $this->actingEmployeeUuid($request),
        ]);

        return response()->json(TabelResource::collection($tabels));
    }

    /**
     * GET /tabels/{uuid} — with entries.
     */
    public function show(string $uuid): JsonResponse
    {
        $tabel = Tabel::where('uuid', $uuid)->with('entries')->firstOrFail();

        return response()->json(new TabelResource($tabel));
    }

    /**
     * POST /tabels/generate.
     */
    public function generate(GenerateTabelRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $tabel = $this->tabels->generate($validated['unitUuid'], $validated['period'], $request->user());

        return response()->json(new TabelResource($tabel->load('entries')), 201);
    }

    /**
     * PATCH /tabels/{uuid}/entries.
     */
    public function updateEntries(UpdateTabelEntriesRequest $request, string $uuid): JsonResponse
    {
        $tabel = $this->tabels->updateEntries($uuid, $request->validated('entries'), $request->user());

        return response()->json(new TabelResource($tabel->load('entries')));
    }

    /**
     * POST /tabels/{uuid}/submit.
     */
    public function submit(Request $request, string $uuid): JsonResponse
    {
        $tabel = $this->tabels->submitToHead($uuid, $request->user());

        return response()->json(new TabelResource($tabel));
    }

    /**
     * POST /tabels/{uuid}/head-decide.
     */
    public function headDecide(TabelHeadDecideRequest $request, string $uuid): JsonResponse
    {
        $validated = $request->validated();
        $tabel = $this->tabels->headDecide(
            $uuid,
            $validated['certificateUuid'] ?? null,
            $validated['signatureHex'] ?? null,
            $request->user(),
            $this->actingEmployeeUuid($request),
            (bool) $validated['approve'],
            $validated['note'] ?? null,
        );

        return response()->json(new TabelResource($tabel));
    }

    /**
     * POST /tabels/{uuid}/hr-decide.
     */
    public function hrDecide(TabelHrDecideRequest $request, string $uuid): JsonResponse
    {
        $validated = $request->validated();
        $tabel = $this->tabels->hrDecide($uuid, $request->user(), (bool) $validated['approve'], $validated['note'] ?? null);

        return response()->json(new TabelResource($tabel));
    }

    /**
     * GET /tabel-registry/{period} — Accounting view, `can:attendance.view-registry`.
     */
    public function registry(string $period): JsonResponse
    {
        $data = $this->tabels->registryFor($period);

        return response()->json([
            'period' => $period,
            'dispatched' => $data['dispatch'] !== null,
            'dispatchedAt' => $data['dispatch']?->dispatched_at?->toIso8601String(),
            'units' => collect($data['units'])->map(fn (array $row) => [
                'unitUuid' => $row['unit']->uuid,
                'unitNameUz' => $row['unit']->name_uz,
                'tabelUuid' => $row['tabel']?->uuid,
                'status' => $row['tabel']?->status,
            ])->values(),
        ]);
    }
}
