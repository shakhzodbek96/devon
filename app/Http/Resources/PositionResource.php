<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `Position` (src/types/domain.ts) verbatim.
 */
class PositionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nameUz' => $this->name_uz,
            'allowedUnitTypes' => $this->allowed_unit_types,
        ];
    }
}
