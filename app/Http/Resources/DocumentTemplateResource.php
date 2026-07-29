<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `DocumentTemplate` (src/types/domain.ts) verbatim.
 */
class DocumentTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'nameUz' => $this->name_uz,
            'descriptionUz' => $this->description_uz,
            'bodyTemplate' => $this->body_template,
            'fields' => $this->fields,
        ];
    }
}
