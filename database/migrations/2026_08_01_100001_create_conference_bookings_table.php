<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conference-room bookings (PLAN_conference-room.md §2, §3). Matches
     * the frontend `ConferenceBooking` (src/types/domain.ts). `room_uuid`/
     * `booked_by`/`cancelled_by` are plain uuid columns with NO foreign key
     * — same "thin, FK-less" convention used across the schema
     * (PLAN_PHASE_AB.md). The composite index on (room_uuid, date, status)
     * backs the overlap query in `ConferenceBookingService::create`.
     */
    public function up(): void
    {
        Schema::create('conference_bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('room_uuid')->index();
            $table->uuid('booked_by')->index();
            $table->string('purpose');
            $table->date('date')->index();
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('status', ['BOOKED', 'CANCELLED'])->default('BOOKED');
            $table->uuid('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['room_uuid', 'date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conference_bookings');
    }
};
