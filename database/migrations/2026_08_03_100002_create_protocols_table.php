<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Bayonnoma (protocol) — one round of a `CollegialBody`'s decision
     * workflow (PLAN_kollegial-organ.md §1, §3):
     * DRAFT -> SENT_FOR_REVIEW -> (REVIEW_REJECTED back to DRAFT-equivalent)
     * -> SENT_TO_MEMBERS -> (MEMBERS_REJECTED) -> SENT_TO_CHAIRMAN ->
     * (CHAIRMAN_REJECTED) -> APPROVED. ALL rejection stations bounce back
     * to an editable state (§2 simplification #1) — `review_note` /
     * `chairman_note` carry the rejection reason; `MEMBERS_REJECTED`'s
     * reason lives on the declining `protocol_participants` row instead
     * (mirrors it not needing a duplicate top-level column). `number` is
     * unique, auto-generated (`BAY-{year}/{seq}`, `nextLetterNumber`
     * convention). `collegial_body_uuid` / `created_by` are plain uuid
     * columns with NO foreign key, same "thin" convention as the rest of
     * the schema.
     */
    public function up(): void
    {
        Schema::create('protocols', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number')->unique();
            $table->uuid('collegial_body_uuid')->index();
            $table->date('protocol_date');
            $table->string('template_key');
            $table->text('agenda');
            $table->enum('status', [
                'DRAFT', 'SENT_FOR_REVIEW', 'REVIEW_REJECTED',
                'SENT_TO_MEMBERS', 'MEMBERS_REJECTED',
                'SENT_TO_CHAIRMAN', 'CHAIRMAN_REJECTED', 'APPROVED',
            ])->default('DRAFT');
            $table->text('review_note')->nullable();
            $table->text('chairman_note')->nullable();
            $table->uuid('created_by');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('members_completed_at')->nullable();
            $table->timestamp('chairman_signed_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('protocols');
    }
};
