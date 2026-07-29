<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notification bell (PLAN_PHASE_F1.md §3). Every route reads/writes
 * only the AUTHENTICATED user's own employee's notifications — never a
 * client-supplied recipient.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    private function actingEmployeeUuid(Request $request): string
    {
        $uuid = $request->user()->employee_uuid;
        abort_if(! $uuid, 422, 'The authenticated user has no linked employee.');

        return $uuid;
    }

    /**
     * GET /me/notifications — `unreadOnly=true` filters. Matches the
     * mock's `listNotifications`.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Notification::where('recipient_employee_uuid', $this->actingEmployeeUuid($request));
        if ($request->boolean('unreadOnly')) {
            $query->where('is_read', false);
        }

        return response()->json(
            NotificationResource::collection($query->orderByDesc('created_at')->get()),
        );
    }

    /**
     * POST /notifications/{uuid}/read. Matches the mock's
     * `markNotificationRead`.
     */
    public function markRead(string $uuid): JsonResponse
    {
        $this->notifications->markRead($uuid);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /me/notifications/read-all. Matches the mock's
     * `markAllNotificationsRead`.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $this->notifications->markAllRead($this->actingEmployeeUuid($request));

        return response()->json(['ok' => true]);
    }
}
