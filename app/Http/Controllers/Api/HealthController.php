<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * GET /health — unauthenticated liveness/readiness probe for load
     * balancers and orchestrators. Checks DB connectivity; 200 when up,
     * 503 when the DB is unreachable.
     */
    public function index(): JsonResponse
    {
        $dbOk = true;

        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            $dbOk = false;
        }

        return response()->json([
            'status' => $dbOk ? 'ok' : 'error',
            'timestamp' => now()->toIso8601String(),
            'checks' => [
                'database' => $dbOk ? 'ok' : 'error',
            ],
        ], $dbOk ? 200 : 503);
    }
}
