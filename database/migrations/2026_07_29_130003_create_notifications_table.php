<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * In-app notifications (PLAN_PHASE_F1.md §1, milestone 2). Matches the
     * frontend `AppNotification` (src/types/domain.ts) verbatim.
     * `title_key` is always a fully-qualified i18n key, never literal copy
     * — enforced at the write site (`NotificationService::append`), not
     * the schema. Real SMTP email is out of scope (BLOCKED(email)) — this
     * table is the in-app bell only.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('recipient_employee_uuid')->index();
            $table->string('type');
            $table->string('title_key');
            $table->json('params');
            $table->enum('resource_type', ['document', 'letter', 'task']);
            $table->uuid('resource_uuid');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recipient_employee_uuid', 'is_read']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
