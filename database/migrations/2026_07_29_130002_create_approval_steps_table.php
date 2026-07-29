<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sequential approval chain steps (PLAN_PHASE_F1.md §1, BP-4). Matches
     * the frontend `ApprovalStep` (src/types/domain.ts) verbatim.
     * `order_no` avoids the reserved SQL word `order` while keeping the
     * intent obvious; the model exposes it as `order` via casts/accessors
     * where convenient. Rejected rounds are never deleted (BP-4: halted
     * chains stay as immutable history) — no cascade-delete besides the
     * document FK itself.
     */
    public function up(): void
    {
        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('document_uuid')->index();
            $table->unsignedInteger('round');
            $table->unsignedInteger('order_no');
            $table->uuid('employee_uuid')->index();
            $table->enum('decision', ['PENDING', 'APPROVED', 'APPROVED_WITH_COMMENT', 'REJECTED'])
                ->default('PENDING');
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('document_uuid')->references('uuid')->on('documents')->cascadeOnDelete();
            $table->index(['document_uuid', 'round']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_steps');
    }
};
