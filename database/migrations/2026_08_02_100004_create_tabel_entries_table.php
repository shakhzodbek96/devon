<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Editable per-employee-per-day cell of a `Tabel` (PLAN_tabel-davomat.md
     * §2). `tabel_uuid` / `employee_uuid` are plain uuid columns with NO
     * foreign key (same "thin, FK-less" convention used across the
     * schema). Seeded from `attendance_records` at generation time, then
     * freely editable while the parent tabel is DRAFT/HEAD_REJECTED/
     * HR_REJECTED.
     */
    public function up(): void
    {
        Schema::create('tabel_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('tabel_uuid')->index();
            $table->uuid('employee_uuid')->index();
            $table->date('work_date');
            $table->enum('status', [
                'OK', 'LATE', 'EARLY_EXIT', 'ABSENT', 'HOLIDAY_WORK', 'AUTO_CLOSED', 'MANUAL',
                'VACATION', 'SICK_LEAVE', 'BUSINESS_TRIP', 'DAY_OFF',
            ]);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['tabel_uuid', 'employee_uuid', 'work_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tabel_entries');
    }
};
