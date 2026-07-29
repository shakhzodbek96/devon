<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentTemplateResource;
use App\Models\DocumentTemplate;
use Illuminate\Http\JsonResponse;

/**
 * Document template catalogue (PLAN_PHASE_F1.md §3). Seeded only — no
 * create/update endpoints (matches the mock's `listDocumentTemplates`).
 */
class DocumentTemplateController extends Controller
{
    /**
     * GET /document-templates.
     */
    public function index(): JsonResponse
    {
        return response()->json(
            DocumentTemplateResource::collection(DocumentTemplate::orderBy('name_uz')->get()),
        );
    }
}
