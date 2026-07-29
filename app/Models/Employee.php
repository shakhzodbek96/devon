<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee master record (PLAN_PHASE_C.md §1.1). Mirrors the frontend
 * `Employee` (src/types/domain.ts). Business rules (uniqueness, txn create,
 * termination) live in `App\Services\EmployeeService` — this model is
 * intentionally thin.
 */
class Employee extends Model
{
    /** @use HasFactory<\Database\Factories\EmployeeFactory> */
    use HasFactory, HasUuids;

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'user_uuid',
        'last_name',
        'first_name',
        'middle_name',
        'full_name_generated',
        'gender',
        'birth_date',
        'pinfl',
        'passport_series',
        'work_phone',
        'internal_extension',
        'mobile_phone',
        'corporate_email',
        'personal_email',
        'primary_unit_uuid',
        'position_id',
        'employment_type',
        'hire_date',
        'employment_order_extract',
        'position_instruction',
        'termination_date',
        'termination_order_extract',
        'status',
        'avatar_url',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'hire_date' => 'date:Y-m-d',
            'termination_date' => 'datetime',
            'employment_order_extract' => 'array',
            'position_instruction' => 'array',
            'termination_order_extract' => 'array',
        ];
    }
}
