<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Document templates (PLAN_PHASE_F1.md §1, milestone 2 — BPMN 3.4).
     * Matches the frontend `DocumentTemplate` (src/types/domain.ts)
     * verbatim. Seeded catalogue only — not user-created in the demo (same
     * convention as `positions`).
     */
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('code', ['BUYRUQ', 'XIZMAT_XATI', 'MALUMOTNOMA', 'ARIZA', 'BILDIRISHNOMA'])->unique();
            $table->string('name_uz');
            $table->string('description_uz');
            $table->text('body_template');
            $table->json('fields');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
