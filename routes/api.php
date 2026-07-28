<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Devon — Auth & Roles + Audit/Units API (PLAN_AUTH_ROLES.md §1.5,
| PLAN_PHASE_AB.md §1–2)
|--------------------------------------------------------------------------
|
| Prefixed /api/v1. Sanctum token-based auth (Bearer) — the SPA runs on a
| different origin (Vite :5173) than the API (:8000).
|
*/

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

        Route::get('/roles', [RoleController::class, 'index']);

        Route::get('/users', [UserController::class, 'index'])->middleware('can:users.view');
        Route::post('/users', [UserController::class, 'store'])->middleware('can:users.create');
        Route::patch('/users/{uuid}', [UserController::class, 'update'])->middleware('can:users.update');
        Route::post('/users/{uuid}/reset-password', [UserController::class, 'resetPassword'])->middleware('can:users.update');

        Route::get('/audit', [AuditController::class, 'index'])->middleware('can:audit.view');

        Route::get('/positions', [PositionController::class, 'index']);

        Route::get('/units', [UnitController::class, 'index'])->middleware('can:units.view');
        Route::get('/units/{uuid}', [UnitController::class, 'show'])->middleware('can:units.view');
        Route::post('/units', [UnitController::class, 'store'])->middleware('can:units.create');
        Route::patch('/units/{uuid}', [UnitController::class, 'update'])->middleware('can:units.update');
        Route::delete('/units/{uuid}', [UnitController::class, 'destroy'])->middleware('can:units.update');
    });
});
