<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The chronological clarification / feedback thread for a task
     * (PLAN_PHASE_H.md §1, BP-2). Matches the frontend `TaskComment`
     * (src/types/domain.ts) verbatim. Separate table (not an embedded jsonb
     * array) — append-only, mirrors `approval_steps`'s child-row convention.
     * No `updated_at` — rows never change after insert, same convention as
     * `audit_logs` / `notifications`.
     */
    public function up(): void
    {
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('task_uuid')->index();
            $table->uuid('author_uuid')->index();
            $table->enum('kind', [
                'CLARIFICATION_REQUEST', 'CLARIFICATION_REPLY', 'RETURN_FEEDBACK', 'REJECT_REASON', 'NOTE',
            ]);
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('task_uuid')->references('uuid')->on('tasks')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_comments');
    }
};
