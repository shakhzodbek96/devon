<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ERI signature records (PLAN_PHASE_F2.md §1). Matches the frontend
     * `SignatureRecord` (src/types/domain.ts) verbatim. Shared by documents
     * AND letters — `resource_type` disambiguates; letter signing lands in
     * phase G and reuses this same table untouched. `resource_uuid` is
     * polymorphic (document OR letter uuid) so it carries NO foreign key,
     * same "thin, FK-less" convention as the rest of the schema.
     *
     * `certificate_uuid` DOES carry a real FK to `digital_certificates` —
     * a signature can never reference a certificate row that doesn't exist,
     * and certificates are never hard-deleted (only revoked), so this FK is
     * safe with no cascade behaviour needed.
     *
     * BLOCKED(e-imzo): `signature_hex` is a client-generated fake (the
     * frontend's `FakeEriSigner.ts`, private key never reaches the server).
     * Real PKCS#7 signing is out of scope for this phase — see
     * PLAN_PHASE_F2.md §0. This table only ever persists signature
     * *metadata*, which IS real.
     */
    public function up(): void
    {
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('resource_type', ['document', 'letter']);
            $table->uuid('resource_uuid')->index();
            $table->uuid('employee_uuid')->index();
            $table->uuid('certificate_uuid');
            $table->string('algorithm')->default('RSA-PKCS7');
            $table->text('signature_hex');
            $table->timestamp('signed_at');

            $table->foreign('certificate_uuid')->references('uuid')->on('digital_certificates');
            $table->index(['resource_type', 'resource_uuid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
