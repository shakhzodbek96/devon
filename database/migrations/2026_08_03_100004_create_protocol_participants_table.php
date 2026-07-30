<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The quorum subset of a `CollegialBody`'s roster selected to sign
     * THIS particular `Protocol` (PLAN_kollegial-organ.md §2.2, §3) — every
     * `employee_uuid` here must also be a `collegial_body_members` row for
     * the same body (`ProtocolService::create`'s `not-body-member` check).
     * PENDING/SIGNED/DECLINED tracks each member's independent (parallel,
     * no ordering) ERI decision — SIGNED rows have a matching `signatures`
     * table entry (`resource_type='protocol'`); DECLINED carries the
     * rejection reason in `note`. No FK on `protocol_uuid` /
     * `employee_uuid`, same "thin" convention as the rest of the schema.
     */
    public function up(): void
    {
        Schema::create('protocol_participants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('protocol_uuid')->index();
            $table->uuid('employee_uuid')->index();
            $table->enum('decision', ['PENDING', 'SIGNED', 'DECLINED'])->default('PENDING');
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
        Schema::dropIfExists('protocol_participants');
    }
};
