<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Employees module (PLAN_PHASE_C.md §1.1). Matches the frontend
     * `Employee` (src/types/domain.ts) verbatim. `primary_unit_uuid` /
     * `position_id` / `user_uuid` are plain uuid/string columns with NO
     * foreign key — same "thin, FK-less" convention already used by
     * `structural_units.head_employee_uuid` (PLAN_PHASE_AB.md), kept for
     * insert-ordering simplicity inside `EmployeeService::createFull`'s
     * transaction (employee + user rows reference each other's uuid).
     * `pinfl` is intentionally NOT a DB-unique column — the business rule
     * (mock's `createEmployeeFull`) only rejects a duplicate PINFL among
     * NON-TERMINATED employees, so a terminated employee's PINFL can be
     * re-issued; uniqueness is enforced in `EmployeeService`, not the schema.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('user_uuid')->index();
            $table->string('last_name');
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('full_name_generated');
            $table->enum('gender', ['M', 'F']);
            $table->date('birth_date')->nullable();
            $table->string('pinfl', 14)->index();
            $table->string('passport_series')->nullable();
            $table->string('work_phone')->nullable();
            $table->string('internal_extension')->nullable();
            $table->string('mobile_phone');
            $table->string('corporate_email')->unique();
            $table->string('personal_email')->nullable();
            $table->uuid('primary_unit_uuid')->index();
            $table->string('position_id')->index();
            $table->enum('employment_type', ['FULL_TIME', 'PART_TIME', 'CONTRACT', 'INTERN']);
            $table->date('hire_date');
            $table->json('employment_order_extract')->nullable();
            $table->json('position_instruction')->nullable();
            $table->timestamp('termination_date')->nullable();
            $table->json('termination_order_extract')->nullable();
            $table->enum('status', ['DRAFT', 'ACTIVE', 'ON_LEAVE', 'SUSPENDED', 'TERMINATED'])->default('ACTIVE');
            $table->string('avatar_url')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
