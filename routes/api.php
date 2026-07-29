<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\ProfileChangeRequestController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Devon — Auth & Roles + Audit/Units + Employees API (PLAN_AUTH_ROLES.md
| §1.5, PLAN_PHASE_AB.md §1–2, PLAN_PHASE_C.md §2)
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

        Route::get('/employees', [EmployeeController::class, 'index'])->middleware('can:employees.view');
        Route::get('/employees/{uuid}', [EmployeeController::class, 'show'])->middleware('can:employees.view');
        Route::post('/employees', [EmployeeController::class, 'store'])->middleware('can:employees.create');
        Route::patch('/employees/{uuid}', [EmployeeController::class, 'update'])->middleware('can:employees.update');
        Route::post('/employees/{uuid}/terminate', [EmployeeController::class, 'terminate'])->middleware('can:employees.terminate');
        Route::post('/employees/{uuid}/transfer', [EmployeeController::class, 'transfer'])->middleware('can:employees.update');
        Route::get('/employees/{uuid}/assignments', [EmployeeController::class, 'assignments'])->middleware('can:employees.view');
        Route::get('/validate/pinfl/{pinfl}', [EmployeeController::class, 'validatePinfl'])->middleware('can:employees.view');

        Route::post('/me/profile-change-request', [ProfileChangeRequestController::class, 'store']);
        Route::get('/profile-change-requests', [ProfileChangeRequestController::class, 'index'])->middleware('can:employees.update');
        Route::post('/profile-change-requests/{uuid}/decide', [ProfileChangeRequestController::class, 'decide'])->middleware('can:employees.update');

        // ERI Certificates (Flow 4, PLAN_PHASE_E.md §5). `revoke` has no
        // `can:` middleware — the owner-or-`certificates.manage` check
        // happens inside CertificateController::revoke itself.
        Route::get('/certificates', [CertificateController::class, 'index'])->middleware('can:certificates.view');
        Route::get('/me/certificates', [CertificateController::class, 'myCertificates']);
        Route::post('/certificates', [CertificateController::class, 'store'])->middleware('can:certificates.upload');
        Route::post('/certificates/{uuid}/approve', [CertificateController::class, 'approve'])->middleware('can:certificates.manage');
        Route::post('/certificates/{uuid}/reject', [CertificateController::class, 'reject'])->middleware('can:certificates.manage');
        Route::post('/certificates/{uuid}/revoke', [CertificateController::class, 'revoke']);
    });
});
