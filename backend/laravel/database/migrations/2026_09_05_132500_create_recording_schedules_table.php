<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recording_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            // 0 = Sunday ... 6 = Saturday, matching Carbon::dayOfWeek.
            $table->unsignedTinyInteger('day_of_week');

            // Wall-clock time the schedule fires at, always interpreted in
            // Asia/Jakarta (UTC+7) regardless of the server's own timezone
            // (UTC) — see RecordingScheduleService.
            $table->time('time_of_day');

            $table->string('preset')->default('HIGH');
            $table->unsignedSmallInteger('duration_minutes');
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            $table->index(['device_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_schedules');
    }
};
