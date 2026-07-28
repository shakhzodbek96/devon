<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `Unit` (src/types/domain.ts) verbatim.
 */
class UnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'nameUz' => $this->name_uz,
            'nameRu' => $this->name_ru,
            'shortName' => $this->short_name,
            'code' => $this->code,
            'type' => $this->type,
            'parentUuid' => $this->parent_uuid,
            'level' => $this->level,
            'path' => $this->path,
            'headEmployeeUuid' => $this->head_employee_uuid,
            'deputyEmployeeUuid' => $this->deputy_employee_uuid,
            'status' => $this->status,
            'description' => $this->description,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'createdBy' => $this->created_by,
            'updatedBy' => $this->updated_by,
        ];
    }
}
