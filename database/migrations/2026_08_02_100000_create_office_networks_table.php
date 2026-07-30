<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Wi-Fi perimeter configuration for daily check-in (PLAN_tabel-davomat.md
     * §2, §0 — the only piece of the daily attendance prototype that is
     * REAL, unlike Face ID which is BLOCKED). `unit_uuid` is a plain uuid
     * column with NO foreign key (same "thin, FK-less" convention used
     * across the schema, PLAN_PHASE_AB.md); null = organization-wide global
     * CIDR.
     */
    public function up(): void
    {
        Schema::create('office_networks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('cidr');
            $table->uuid('unit_uuid')->nullable()->index();
            $table->enum('status', ['ACTIVE', 'ARCHIVED'])->default('ACTIVE');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('office_networks');
    }
};
