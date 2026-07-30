<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Permanent member roster of a `CollegialBody` (PLAN_kollegial-organ.md
     * §3) — a plain pivot: `collegial_body_uuid` + `employee_uuid`, unique
     * together. NOT the same as `protocol_participants` — this is the
     * standing roster a given `Protocol` selects its quorum subset FROM
     * (`ProtocolService::create`'s `not-body-member` check). No FK on
     * either uuid column, same "thin" convention as the rest of the schema.
     */
    public function up(): void
    {
        Schema::create('collegial_body_members', function (Blueprint $table) {
            $table->id();
            $table->uuid('collegial_body_uuid')->index();
            $table->uuid('employee_uuid')->index();
            $table->timestamps();

            $table->unique(['collegial_body_uuid', 'employee_uuid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collegial_body_members');
    }
};
