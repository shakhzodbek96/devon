<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Incoming/outgoing correspondence (PLAN_PHASE_G.md §1, BP-3). Matches
     * the frontend `Letter` (src/types/domain.ts) verbatim. `routed_to_unit_uuid`
     * / `assigned_employee_uuid` / `response_document_uuid` /
     * `linked_incoming_uuid` / `registered_by_uuid` are plain uuid columns
     * with NO foreign key — same "thin, FK-less" convention used across the
     * schema (PLAN_PHASE_AB.md). Letter ERI signing reuses the shared
     * `signatures` table (`resource_type='letter'`, PLAN_PHASE_F2.md) —
     * nothing new needed there.
     */
    public function up(): void
    {
        Schema::create('letters', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('direction', ['INCOMING', 'OUTGOING']);
            $table->string('number')->unique();
            $table->string('external_org');
            $table->string('subject');
            $table->enum('channel', ['POCHTA', 'EMAIL', 'KURYER', 'QOGOZ']);
            $table->json('file_meta')->nullable();
            $table->date('received_at')->nullable();
            $table->date('deadline')->nullable();
            $table->uuid('routed_to_unit_uuid')->nullable();
            $table->uuid('assigned_employee_uuid')->nullable();
            $table->boolean('requires_signature')->default(false);
            $table->text('execution_comment')->nullable();
            $table->json('response_file_meta')->nullable();
            $table->uuid('response_document_uuid')->nullable();
            $table->uuid('linked_incoming_uuid')->nullable();
            $table->enum('status', [
                'REGISTERED', 'ROUTED', 'ASSIGNED', 'IN_PROGRESS', 'EXECUTED',
                'ON_SIGNATURE', 'RESPONDED', 'DISPATCHED', 'CLOSED', 'CLOSED_NO_RESPONSE',
            ])->default('REGISTERED');
            $table->uuid('registered_by_uuid')->index();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('direction');
            $table->index('routed_to_unit_uuid');
            $table->index('assigned_employee_uuid');
            $table->index('linked_incoming_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('letters');
    }
};
