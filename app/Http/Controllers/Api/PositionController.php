<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PositionResource;
use App\Models\Position;
use Illuminate\Http\JsonResponse;

class PositionController extends Controller
{
    /**
     * GET /positions — the seeded position catalogue, readable by every
     * authenticated role. Foundation for the Employees module (milestone C);
     * not yet user-editable.
     */
    public function index(): JsonResponse
    {
        return response()->json(PositionResource::collection(Position::orderBy('id')->get()));
    }
}
