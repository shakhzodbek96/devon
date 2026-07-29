<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Documents (PLAN_PHASE_F1.md §1, milestone 2 — BPMN 3.4 / BP-4).
     * Matches the frontend `DocumentEntity` (src/types/domain.ts) verbatim.
     * `creator_uuid` / `recipient_uuid` / `signer_uuid` / `template_uuid`
     * are plain uuid columns with NO foreign key — same "thin, FK-less"
     * convention used across the schema (PLAN_PHASE_AB.md).
     *
     * ERI signing (SIGNED status, `signatures` table) is milestone F2 — this
     * table already carries `signed_at` so a document CAN reach SIGNED once
     * F2 lands the `signDocument` mutation, but nothing in F1 writes it via
     * a real code path (only the seeder, for historical fixture rows).
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number')->unique();
            $table->string('title');
            $table->enum('source', ['TEMPLATE', 'UPLOAD']);
            $table->uuid('template_uuid')->nullable();
            $table->json('values')->nullable();
            $table->text('rendered_body')->nullable();
            $table->json('file_meta')->nullable();
            $table->enum('confidentiality', ['ODDIY', 'MAXFIY'])->default('ODDIY');
            $table->uuid('creator_uuid')->index();
            $table->uuid('recipient_uuid')->index();
            $table->uuid('signer_uuid')->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->enum('status', ['DRAFT', 'IN_REVIEW', 'REJECTED', 'APPROVED', 'SIGNED', 'CLOSED'])
                ->default('DRAFT');
            $table->unsignedInteger('round')->default(1);
            $table->json('viewed_by')->nullable();
            $table->timestamp('sent_for_review_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->json('emailed_to')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
