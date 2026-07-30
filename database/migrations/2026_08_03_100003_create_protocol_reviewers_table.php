<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Reviewers ("tekshiruvchi bo'linmalar") freely picked by the
     * secretary per-protocol (PLAN_kollegial-organ.md §2.4, §3) — NOT
     * restricted to the collegial body's roster. Decision-tracking row,
     * `approval_steps`-shaped: PENDING/APPROVED/REJECTED, `decided_at`,
     * `note`. Re-submitting a protocol (`ProtocolService::submitForReview`)
     * resets every row back to PENDING. No FK on `protocol_uuid` /
     * `employee_uuid`, same "thin" convention as the rest of the schema —
     * cascade-delete is handled at the application layer (rows are
     * replaced wholesale on `update()`, not the DB).
     */
    public function up(): void
    {
        Schema::create('protocol_reviewers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('protocol_uuid')->index();
            $table->uuid('employee_uuid')->index();
            $table->enum('decision', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->text('note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['protocol_uuid', 'employee_uuid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('protocol_reviewers');
    }
};
