<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Seeded position catalogue (PLAN_PHASE_AB.md §1.2). `id` is the string
     * code used verbatim by the frontend `Position.id` (e.g. 'POS-DIR') —
     * kept as the primary key so the API response needs no id mapping.
     * Foundation for the Employees module (milestone C); not yet
     * user-editable in this slice.
     */
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name_uz');
            $table->json('allowed_unit_types');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
