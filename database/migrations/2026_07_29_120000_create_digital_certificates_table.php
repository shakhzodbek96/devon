<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ERI certificate metadata + lifecycle (PLAN_PHASE_E.md §1, Flow 4).
     * Matches the frontend `Certificate` (src/types/domain.ts) verbatim.
     * No `updated_at` — matches the frontend type (createdAt only), same
     * convention as `employee_unit_assignments` / `audit_logs`.
     *
     * `employee_uuid` / `uploaded_by_uuid` / `approved_by_uuid` are plain
     * uuid columns with NO foreign key — same "thin, FK-less" convention
     * used across the schema (PLAN_PHASE_AB.md).
     *
     * `serial_number` / `thumbprint` are DB-unique as a defense-in-depth
     * backstop; the primary uniqueness check (case-insensitive, with the
     * `serial-taken` error code) lives in `CertificateService::upload`.
     *
     * BLOCKED(e-imzo): this table stores certificate *metadata* only — no
     * private key material, no PKCS#12 blob, ever reaches the server. Real
     * PFX crypto validation / challenge-response ownership proof / CRL-OCSP
     * checks are out of scope for this phase (see PLAN_PHASE_E.md §0).
     */
    public function up(): void
    {
        Schema::create('digital_certificates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('employee_uuid')->index();
            $table->string('serial_number')->unique();
            $table->string('thumbprint')->unique();
            $table->string('subject_pinfl', 14)->index();
            $table->string('subject_common_name');
            $table->string('subject_organization')->nullable();
            $table->string('issuer_name');
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            $table->json('key_usage');
            $table->enum('certificate_type', ['SIGNING', 'ENCRYPTION', 'BOTH']);
            $table->enum('status', ['PENDING_APPROVAL', 'ACTIVE', 'EXPIRED', 'REVOKED', 'REJECTED'])
                ->default('PENDING_APPROVAL');
            $table->string('rejection_reason')->nullable();
            $table->uuid('uploaded_by_uuid');
            $table->uuid('approved_by_uuid')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->enum('revocation_reason', ['EXPIRED', 'EMPLOYEE_TERMINATED', 'COMPROMISED', 'REPLACED', 'MANUAL'])
                ->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('digital_certificates');
    }
};
