<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Organization-level "the whole registry was sent to Accounting" marker
     * (PLAN_tabel-davomat.md §1 node 11, §2) — one row per period, written
     * once ALL active units' tabels reach HR_ACCEPTED for that period
     * (`TabelService::maybeDispatchRegistry`). `period` (YYYY-MM) is the
     * primary key — no separate uuid/id needed, there is at most one
     * dispatch per period. `dispatched_by` is a plain uuid column with NO
     * foreign key (same "thin, FK-less" convention used across the schema).
     */
    public function up(): void
    {
        Schema::create('tabel_registry_dispatches', function (Blueprint $table) {
            $table->string('period')->primary();
            $table->timestamp('dispatched_at');
            $table->uuid('dispatched_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tabel_registry_dispatches');
    }
};
