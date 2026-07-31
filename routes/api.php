<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\CollegialBodyController;
use App\Http\Controllers\Api\ConferenceBookingController;
use App\Http\Controllers\Api\ConferenceRoomController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentTemplateController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\LetterController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OfficeNetworkController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\ProfileChangeRequestController;
use App\Http\Controllers\Api\ProtocolController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\TabelController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkShiftController;
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
        Route::post('/positions', [PositionController::class, 'store'])->middleware('can:positions.manage');
        Route::patch('/positions/{id}', [PositionController::class, 'update'])->middleware('can:positions.manage');
        Route::delete('/positions/{id}', [PositionController::class, 'destroy'])->middleware('can:positions.manage');

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

        // Documents + approval workflow + ERI signing + notifications
        // (PLAN_PHASE_F1.md §3, PLAN_PHASE_F2.md §2, milestone 2). No
        // `can:` route middleware — every mutation acts as the
        // authenticated user's own employee, and per-document authorization
        // (creator/participant/recipient/signer) is enforced inside
        // `DocumentService` itself, not by role.
        Route::get('/document-templates', [DocumentTemplateController::class, 'index']);
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::get('/documents/{uuid}', [DocumentController::class, 'show']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::patch('/documents/{uuid}', [DocumentController::class, 'update']);
        Route::delete('/documents/{uuid}', [DocumentController::class, 'destroy']);
        Route::post('/documents/{uuid}/send-for-review', [DocumentController::class, 'sendForReview']);
        Route::post('/documents/{uuid}/decide', [DocumentController::class, 'decide']);
        Route::post('/documents/{uuid}/sign', [DocumentController::class, 'sign']);
        Route::post('/documents/{uuid}/accept', [DocumentController::class, 'accept']);
        Route::post('/documents/{uuid}/email', [DocumentController::class, 'email']);
        Route::post('/documents/{uuid}/view', [DocumentController::class, 'recordView']);
        Route::get('/me/approvals', [DocumentController::class, 'myApprovals']);

        // Incoming/outgoing correspondence (PLAN_PHASE_G.md §3, BPMN 3.3 /
        // BP-3). No `can:` route middleware — every mutation acts as the
        // authenticated user's own employee, and per-station authorization
        // (Devonxona/Rahbar/unit-head/executor) is enforced inside
        // `LetterService` itself, not by role. Letter signing reuses the
        // shared `signatures` table (PLAN_PHASE_F2.md).
        Route::get('/letters', [LetterController::class, 'index']);
        Route::get('/letters/{uuid}', [LetterController::class, 'show']);
        Route::post('/letters', [LetterController::class, 'store']);
        Route::post('/letters/{uuid}/route', [LetterController::class, 'route']);
        Route::post('/letters/{uuid}/assign', [LetterController::class, 'assign']);
        Route::post('/letters/{uuid}/start', [LetterController::class, 'start']);
        Route::post('/letters/{uuid}/execute', [LetterController::class, 'execute']);
        Route::post('/letters/{uuid}/accept', [LetterController::class, 'accept']);
        Route::post('/letters/{uuid}/sign', [LetterController::class, 'sign']);
        Route::post('/letters/{uuid}/dispatch', [LetterController::class, 'dispatch']);

        // Task delegation / Kanban (PLAN_PHASE_H.md §3, BP-2 — the last
        // mock module). No `can:` route middleware — every mutation acts
        // as the authenticated user's own employee, and per-task
        // authorization (assigner/assignee, subtree scope) is enforced
        // inside `TaskService` itself, not by role.
        Route::get('/tasks', [TaskController::class, 'index']);
        Route::get('/tasks/{uuid}', [TaskController::class, 'show']);
        Route::get('/me/task-stats', [TaskController::class, 'stats']);
        Route::post('/tasks', [TaskController::class, 'store']);
        Route::patch('/tasks/{uuid}', [TaskController::class, 'update']);
        Route::post('/tasks/{uuid}/start', [TaskController::class, 'start']);
        Route::post('/tasks/{uuid}/clarification-request', [TaskController::class, 'requestClarification']);
        Route::post('/tasks/{uuid}/clarification-answer', [TaskController::class, 'answerClarification']);
        Route::post('/tasks/{uuid}/submit', [TaskController::class, 'submit']);
        Route::post('/tasks/{uuid}/review', [TaskController::class, 'review']);

        // Conference-room booking (PLAN_conference-room.md §3, Tier 2 —
        // simplest new module). Room CRUD gated by `conference-rooms.manage`;
        // booking itself is unrestricted (any authenticated employee), with
        // per-booking cancel authorization enforced inside
        // `ConferenceBookingService`, not here.
        Route::get('/conference-rooms', [ConferenceRoomController::class, 'index']);
        Route::post('/conference-rooms', [ConferenceRoomController::class, 'store'])->middleware('can:conference-rooms.manage');
        Route::patch('/conference-rooms/{uuid}', [ConferenceRoomController::class, 'update'])->middleware('can:conference-rooms.manage');
        Route::delete('/conference-rooms/{uuid}', [ConferenceRoomController::class, 'destroy'])->middleware('can:conference-rooms.manage');

        Route::get('/conference-bookings', [ConferenceBookingController::class, 'index']);
        Route::post('/conference-bookings', [ConferenceBookingController::class, 'store']);
        Route::post('/conference-bookings/{uuid}/cancel', [ConferenceBookingController::class, 'cancel']);

        // Tabel / Davomat — Wi-Fi perimeter config + work shifts (admin
        // CRUD, PLAN_tabel-davomat.md §3), gated by `attendance.manage-config`.
        Route::get('/office-networks', [OfficeNetworkController::class, 'index'])->middleware('can:attendance.manage-config');
        Route::post('/office-networks', [OfficeNetworkController::class, 'store'])->middleware('can:attendance.manage-config');
        Route::patch('/office-networks/{uuid}', [OfficeNetworkController::class, 'update'])->middleware('can:attendance.manage-config');
        Route::delete('/office-networks/{uuid}', [OfficeNetworkController::class, 'destroy'])->middleware('can:attendance.manage-config');

        Route::get('/work-shifts', [WorkShiftController::class, 'index'])->middleware('can:attendance.manage-config');
        Route::post('/work-shifts', [WorkShiftController::class, 'store'])->middleware('can:attendance.manage-config');
        Route::patch('/work-shifts/{uuid}', [WorkShiftController::class, 'update'])->middleware('can:attendance.manage-config');
        Route::delete('/work-shifts/{uuid}', [WorkShiftController::class, 'destroy'])->middleware('can:attendance.manage-config');

        // Daily check-in/check-out (PLAN_tabel-davomat.md §3). check-in/
        // check-out/me act as the authenticated user's own employee — no
        // `can:` middleware, everyone checks themselves in.
        Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut']);
        Route::get('/attendance/me', [AttendanceController::class, 'me']);
        Route::get('/attendance', [AttendanceController::class, 'index'])->middleware('can:attendance.view');
        Route::post('/attendance/manual', [AttendanceController::class, 'manual'])->middleware('can:attendance.manage');

        // Monthly timesheet approval chain (PLAN_tabel-davomat.md §1, §3).
        // No `can:` route middleware on generate/entries/submit/head-decide/
        // hr-decide — per-tabel authorization (unit head / HR) is enforced
        // inside `TabelService`. `tabel-registry` is Accounting's
        // cross-unit view, gated by `attendance.view-registry`.
        Route::get('/tabels', [TabelController::class, 'index']);
        Route::get('/tabels/{uuid}', [TabelController::class, 'show']);
        Route::post('/tabels/generate', [TabelController::class, 'generate']);
        Route::patch('/tabels/{uuid}/entries', [TabelController::class, 'updateEntries']);
        Route::post('/tabels/{uuid}/submit', [TabelController::class, 'submit']);
        Route::post('/tabels/{uuid}/head-decide', [TabelController::class, 'headDecide']);
        Route::post('/tabels/{uuid}/hr-decide', [TabelController::class, 'hrDecide']);
        Route::get('/tabel-registry/{period}', [TabelController::class, 'registry'])->middleware('can:attendance.view-registry');

        // Kollegial organ — standing body registry (PLAN_kollegial-organ.md
        // §2.2, §4). `index` open to all; CRUD + member management gated
        // by `collegial.manage`.
        Route::get('/collegial-bodies', [CollegialBodyController::class, 'index']);
        Route::post('/collegial-bodies', [CollegialBodyController::class, 'store'])->middleware('can:collegial.manage');
        Route::patch('/collegial-bodies/{uuid}', [CollegialBodyController::class, 'update'])->middleware('can:collegial.manage');
        Route::delete('/collegial-bodies/{uuid}', [CollegialBodyController::class, 'destroy'])->middleware('can:collegial.manage');
        Route::post('/collegial-bodies/{uuid}/members', [CollegialBodyController::class, 'addMember'])->middleware('can:collegial.manage');
        Route::delete('/collegial-bodies/{uuid}/members/{employeeUuid}', [CollegialBodyController::class, 'removeMember'])->middleware('can:collegial.manage');

        // Bayonnoma (protocol) approval chain (PLAN_kollegial-organ.md §1,
        // §3, §4). No `can:` route middleware — secretary/reviewer/
        // participant/chairman authorization is enforced inside
        // `ProtocolService`.
        Route::get('/protocols', [ProtocolController::class, 'index']);
        Route::get('/protocols/{uuid}', [ProtocolController::class, 'show']);
        Route::post('/collegial-bodies/{bodyUuid}/protocols', [ProtocolController::class, 'store']);
        Route::patch('/protocols/{uuid}', [ProtocolController::class, 'update']);
        Route::post('/protocols/{uuid}/submit', [ProtocolController::class, 'submit']);
        Route::post('/protocols/{uuid}/reviewer-decide', [ProtocolController::class, 'reviewerDecide']);
        Route::post('/protocols/{uuid}/member-sign', [ProtocolController::class, 'memberSign']);
        Route::post('/protocols/{uuid}/member-decline', [ProtocolController::class, 'memberDecline']);
        Route::post('/protocols/{uuid}/chairman-decide', [ProtocolController::class, 'chairmanDecide']);

        Route::get('/me/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{uuid}/read', [NotificationController::class, 'markRead']);
        Route::post('/me/notifications/read-all', [NotificationController::class, 'markAllRead']);
    });
});
