<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conference-room registry (PLAN_conference-room.md §2, §3 — Tier 2,
     * simplest new module). Matches the frontend `ConferenceRoom`
     * (src/types/domain.ts). `created_by`/`updated_by` are plain uuid
     * columns with NO foreign key — same "thin, FK-less" convention used
     * across the schema (PLAN_PHASE_AB.md).
     */
    public function up(): void
    {
        Schema::create('conference_rooms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->unsignedInteger('capacity');
            $table->string('location')->nullable();
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
        Schema::dropIfExists('conference_rooms');
    }
};
