<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Monthly timesheet, unit × period (PLAN_tabel-davomat.md §1, §2 — the
     * 4-station ERI approval chain: DRAFT -> SENT_TO_HEAD -> HEAD_SIGNED
     * (auto) -> SENT_TO_HR -> HR_ACCEPTED, with HEAD_REJECTED/HR_REJECTED
     * bounce-back to DRAFT-equivalent editable states). `unit_uuid` /
     * `created_by` are plain uuid columns with NO foreign key (same
     * "thin, FK-less" convention used across the schema). `head_note` /
     * `hr_note` carry the rejection reason for HEAD_REJECTED/HR_REJECTED.
     * ERI signing (`head-decide` approve path) reuses the shared
     * `signatures` table with `resource_type='tabel'` — nothing new
     * needed there.
     */
    public function up(): void
    {
        Schema::create('tabels', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('unit_uuid')->index();
            $table->string('period')->index();
            $table->enum('status', [
                'DRAFT', 'SENT_TO_HEAD', 'HEAD_REJECTED', 'HEAD_SIGNED',
                'SENT_TO_HR', 'HR_REJECTED', 'HR_ACCEPTED',
            ])->default('DRAFT');
            $table->uuid('created_by');
            $table->text('head_note')->nullable();
            $table->text('hr_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('head_signed_at')->nullable();
            $table->timestamp('hr_accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['unit_uuid', 'period']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tabels');
    }
};
