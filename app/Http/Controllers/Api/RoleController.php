<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    /**
     * GET /roles — the 7 Devon roles for the admin "new user" dropdown.
     */
    public function index(): JsonResponse
    {
        $roles = collect(config('devon_roles'))
            ->map(fn (string $nameUz, string $code) => [
                'code' => $code,
                'nameUz' => $nameUz,
            ])
            ->values();

        return response()->json($roles);
    }
}
