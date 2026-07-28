<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `SessionUser` contract exactly
 * (frontend/dashboard/src/stores/useAuthStore.ts):
 * `{ uuid, email, fullName, roles, mustChangePassword }`.
 * `status` and `createdAt` are additive — used by the admin Users page
 * (frontend/dashboard/src/features/users) list/table, ignored by the
 * plain auth/session consumers.
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'email' => $this->email,
            'fullName' => $this->full_name,
            'roles' => $this->getRoleNames()->values(),
            'mustChangePassword' => (bool) $this->must_change_password,
            'status' => $this->status,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
