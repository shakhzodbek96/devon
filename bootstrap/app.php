<?php

use App\Exceptions\AssignmentValidationException;
use App\Exceptions\CertificateValidationException;
use App\Exceptions\DocumentValidationException;
use App\Exceptions\EmployeeValidationException;
use App\Exceptions\UnitValidationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Unit business-rule violations (PLAN_PHASE_AB.md §2.2) render as a
        // 422 `{ code, message }` body — `code` matches the frontend mock's
        // UnitValidationCode ('cycle' | 'duplicate-name' | 'max-depth' |
        // 'invalid-parent') so src/lib/api/units.ts can rethrow the same
        // `UnitValidationError` class the mock backend throws.
        $exceptions->render(function (UnitValidationException $e) {
            return response()->json([
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], 422);
        });

        // Employee business-rule violations (PLAN_PHASE_C.md §2) — same
        // 422 `{ code, message }` shape, code matches the frontend mock's
        // EmployeeValidationCode ('pinfl-taken' | 'email-taken' |
        // 'order-extract-missing' | 'position-instruction-missing' |
        // 'termination-extract-missing').
        $exceptions->render(function (EmployeeValidationException $e) {
            return response()->json([
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], 422);
        });

        // Assignment/transfer business-rule violations (PLAN_PHASE_D.md §1)
        // — same 422 `{ code, message }` shape, code matches the frontend
        // mock's AssignmentValidationCode ('workload-exceeded').
        $exceptions->render(function (AssignmentValidationException $e) {
            return response()->json([
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], 422);
        });

        // Certificate business-rule violations (PLAN_PHASE_E.md §2) — same
        // 422 `{ code, message }` shape, code matches the frontend mock's
        // CertificateValidationCode ('serial-taken' | 'pinfl-mismatch').
        $exceptions->render(function (CertificateValidationException $e) {
            return response()->json([
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], 422);
        });

        // Document business-rule / per-document authorization violations
        // (PLAN_PHASE_F1.md §2) — same 422 `{ code, message }` shape, code
        // matches the frontend mock's DocumentValidationCode.
        $exceptions->render(function (DocumentValidationException $e) {
            return response()->json([
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
            ], 422);
        });
    })->create();
