<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Organizational structure tree (PLAN_PHASE_AB.md §2.1). Matches the
     * frontend `Unit` (src/types/domain.ts) verbatim. `path` is a
     * materialized path (`/root-uuid/.../self-uuid/`) used for descendant
     * detection (cycle prevention) and recursive level/path recompute on
     * parent move. `head_employee_uuid` / `deputy_employee_uuid` are plain
     * nullable uuids with NO foreign key — the employees table doesn't
     * exist yet (milestone C); full referential integrity lands then.
     */
    public function up(): void
    {
        Schema::create('structural_units', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name_uz');
            $table->string('name_ru')->nullable();
            $table->string('short_name')->nullable();
            $table->string('code')->unique();
            $table->enum('type', ['DEPARTMENT', 'DIRECTORATE', 'DIVISION', 'DEPARTMENT_SUB', 'SECTION', 'OTHER']);
            $table->uuid('parent_uuid')->nullable();
            $table->unsignedTinyInteger('level')->default(0);
            $table->string('path');
            $table->uuid('head_employee_uuid')->nullable();
            $table->uuid('deputy_employee_uuid')->nullable();
            $table->enum('status', ['ACTIVE', 'ARCHIVED'])->default('ACTIVE');
            $table->text('description')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index('parent_uuid');
            $table->index('path');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('structural_units');
    }
};
