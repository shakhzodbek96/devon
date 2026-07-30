<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Collegial body ("Kollegial organ") — a STANDING structure
     * (PLAN_kollegial-organ.md §2.2): name, a permanent chairman, a
     * permanent secretary, and a quorum minimum. The permanent member
     * roster itself lives in `collegial_body_members`; each individual
     * `Protocol` then selects a (>= quorum_min) subset of that roster to
     * actually sign. `chairman_employee_uuid` / `secretary_employee_uuid`
     * / `created_by` / `updated_by` are plain uuid columns with NO
     * foreign key — same "thin, FK-less" convention used across the
     * schema (Employee has no enforced FK anywhere else either).
     */
    public function up(): void
    {
        Schema::create('collegial_bodies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->uuid('chairman_employee_uuid');
            $table->uuid('secretary_employee_uuid');
            $table->unsignedInteger('quorum_min');
            $table->enum('status', ['ACTIVE', 'ARCHIVED'])->default('ACTIVE');
            $table->uuid('created_by');
            $table->uuid('updated_by');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collegial_bodies');
    }
};
