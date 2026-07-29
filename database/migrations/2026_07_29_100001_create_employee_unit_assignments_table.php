<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Employee → unit assignment history (PLAN_PHASE_C.md §1.2). Matches the
     * frontend `Assignment` (src/types/domain.ts) verbatim. Phase C only
     * writes the create-time PRIMARY row (100% workload) + reads via
     * `listAssignments`; transfer/combination/close-old-assignment logic is
     * Phase D. No `updated_at` — matches the frontend type (createdAt only).
     */
    public function up(): void
    {
        Schema::create('employee_unit_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('employee_uuid')->index();
            $table->uuid('unit_uuid')->index();
            $table->string('position_id')->index();
            $table->boolean('is_primary')->default(true);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedTinyInteger('workload_percent');
            $table->enum('type', ['PRIMARY', 'COMBINATION', 'ACTING', 'TEMPORARY']);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_unit_assignments');
    }
};
