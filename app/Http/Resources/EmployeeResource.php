<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches the frontend `Employee` (src/types/domain.ts) verbatim.
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'userUuid' => $this->user_uuid,
            'lastName' => $this->last_name,
            'firstName' => $this->first_name,
            'middleName' => $this->middle_name,
            'fullNameGenerated' => $this->full_name_generated,
            'gender' => $this->gender,
            'birthDate' => $this->birth_date,
            'pinfl' => $this->pinfl,
            'passportSeries' => $this->passport_series,
            'workPhone' => $this->work_phone,
            'internalExtension' => $this->internal_extension,
            'mobilePhone' => $this->mobile_phone,
            'corporateEmail' => $this->corporate_email,
            'personalEmail' => $this->personal_email,
            'primaryUnitUuid' => $this->primary_unit_uuid,
            'positionId' => $this->position_id,
            'employmentType' => $this->employment_type,
            'hireDate' => $this->hire_date,
            'employmentOrderExtract' => $this->employment_order_extract,
            'positionInstruction' => $this->position_instruction,
            'terminationDate' => $this->termination_date?->toIso8601String(),
            'terminationOrderExtract' => $this->termination_order_extract,
            'status' => $this->status,
            'avatarUrl' => $this->avatar_url,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
