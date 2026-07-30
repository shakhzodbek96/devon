<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Standard work shift, unit-level (PLAN_tabel-davomat.md §2, §0 — the
     * individual/unit/organization shift hierarchy is deliberately
     * simplified to a single shift per unit or one organization-wide
     * default). `unit_uuid` null = the organization-wide default (exactly
     * one such row is expected — enforced by the seeder, not the schema).
     */
    public function up(): void
    {
        Schema::create('work_shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('unit_uuid')->nullable()->unique();
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('late_grace_min')->default(10);
            $table->unsignedInteger('early_exit_grace_min')->default(15);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_shifts');
    }
};
