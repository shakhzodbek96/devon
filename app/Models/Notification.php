<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * In-app notification (PLAN_PHASE_F1.md §1, milestone 2). Mirrors the
 * frontend `AppNotification` (src/types/domain.ts). `title_key` is always a
 * fully-qualified i18n key, never literal copy — enforced by
 * `App\Services\NotificationService::append`, the only write path.
 *
 * Named `Notification` under `App\Models` — distinct from
 * `Illuminate\Notifications\Notification`; no ambiguity since callers
 * always import the fully-qualified class.
 */
class Notification extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationFactory> */
    use HasFactory, HasUuids;

    /** No `updated_at` column — matches the frontend type (createdAt only). */
    const UPDATED_AT = null;

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'recipient_employee_uuid',
        'type',
        'title_key',
        'params',
        'resource_type',
        'resource_uuid',
        'is_read',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'is_read' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
