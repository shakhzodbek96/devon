<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Daily check-in/check-out, self-service (PLAN_tabel-davomat.md §2).
     * `employee_uuid` is a plain uuid column with NO foreign key (same
     * "thin, FK-less" convention used across the schema). `face_status`
     * is BLOCKED(face-recognition) — a self-reported "the employee tapped
     * the button" flag, never real biometric confirmation (PLAN
     * §0). One row per employee per day.
     */
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('employee_uuid')->index();
            $table->date('work_date')->index();
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->enum('status', ['OK', 'LATE', 'EARLY_EXIT', 'ABSENT', 'HOLIDAY_WORK', 'AUTO_CLOSED', 'MANUAL']);
            $table->unsignedInteger('late_minutes')->nullable();
            $table->unsignedInteger('early_exit_minutes')->nullable();
            $table->boolean('network_ok')->default(false);
            $table->enum('face_status', ['SELF_CONFIRMED', 'SKIPPED'])->nullable();
            $table->enum('source', ['SELF_CHECKIN', 'HR_MANUAL', 'AUTO_CLOSE'])->default('SELF_CHECKIN');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['employee_uuid', 'work_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
