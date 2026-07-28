<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Cross-cutting append-only audit trail (PLAN_PHASE_AB.md §1.1). Matches
     * the frontend `AuditEntry` (src/types/domain.ts) verbatim. No
     * `updated_at` — rows are immutable once written (enforced in
     * `App\Models\AuditLog`, blocked even for ROLE_SUPER_ADMIN).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('actor_uuid');
            $table->string('actor_name');
            $table->string('action');
            $table->string('resource_type');
            $table->uuid('resource_uuid');
            $table->string('resource_label');
            $table->json('changes')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['resource_type', 'resource_uuid']);
            $table->index('actor_uuid');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
