<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Task delegation / Kanban (PLAN_PHASE_H.md §1, BP-2, the last mock
     * module). Matches the frontend `TaskEntity` (src/types/domain.ts)
     * verbatim. `assigner_uuid` / `assignee_uuid` / `attached_document_uuid`
     * are plain uuid columns with NO foreign key — same "thin, FK-less"
     * convention used across the schema (PLAN_PHASE_AB.md). The comment
     * thread lives in a separate `task_comments` table (PLAN_PHASE_H.md §1
     * picks the separate-table option over an embedded jsonb array).
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number')->unique();
            $table->string('title');
            $table->text('description');
            $table->enum('priority', ['HIGH', 'MEDIUM', 'STANDARD']);
            $table->enum('status', ['NEW', 'IN_PROGRESS', 'UNDER_REVIEW', 'DONE', 'REJECTED'])
                ->default('NEW');
            $table->uuid('assigner_uuid')->index();
            $table->uuid('assignee_uuid')->index();
            $table->date('deadline');
            $table->uuid('attached_document_uuid')->nullable();
            $table->json('attached_file')->nullable();
            $table->json('deliverable')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedInteger('round')->default(1);
            $table->boolean('late_submission')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('deadline');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
