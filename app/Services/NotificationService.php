<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Str;

/**
 * In-app notifications (PLAN_PHASE_F1.md §2). Ported verbatim from the
 * frontend mock's `appendNotification` / `listNotifications` /
 * `markNotificationRead` / `markAllNotificationsRead`
 * (frontend/dashboard/src/lib/mock-backend/index.ts). `append()` is the
 * ONLY write path — `title_key` is always a fully-qualified i18n key
 * (`dashboard:notifications.title.*`), never literal copy, matching the
 * mock's `notifyDocument` helper.
 *
 * BLOCKED(email): real SMTP delivery is out of scope for this phase — this
 * service only ever writes the in-app bell row.
 */
class NotificationService
{
    /**
     * @param  array<string, string>  $params
     */
    public function append(
        string $recipientEmployeeUuid,
        string $type,
        string $titleKey,
        array $params,
        string $resourceType,
        string $resourceUuid,
    ): Notification {
        return Notification::create([
            'uuid' => (string) Str::uuid(),
            'recipient_employee_uuid' => $recipientEmployeeUuid,
            'type' => $type,
            'title_key' => $titleKey,
            'params' => $params,
            'resource_type' => $resourceType,
            'resource_uuid' => $resourceUuid,
            'is_read' => false,
        ]);
    }

    /**
     * Fan-out helper — one notification per unique recipient. Mirrors the
     * mock's `notifyDocument`: `titleKeyOverride` disambiguates the
     * DOC_SIGN_REQUESTED/DOC_ACCEPT_REQUESTED copy that rides the same
     * notification type.
     *
     * @param  string[]  $recipients
     * @param  array<string, string>  $params
     */
    public function notifyDocument(
        array $recipients,
        string $type,
        string $documentUuid,
        array $params,
        ?string $titleKeyOverride = null,
    ): void {
        foreach (array_unique(array_filter($recipients)) as $recipientEmployeeUuid) {
            $this->append(
                $recipientEmployeeUuid,
                $type,
                $titleKeyOverride ?? "dashboard:notifications.title.{$type}",
                $params,
                'document',
                $documentUuid,
            );
        }
    }

    public function markRead(string $uuid): void
    {
        Notification::where('uuid', $uuid)->update(['is_read' => true]);
    }

    public function markAllRead(string $recipientEmployeeUuid): void
    {
        Notification::where('recipient_employee_uuid', $recipientEmployeeUuid)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }
}
