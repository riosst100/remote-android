<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Last time this device fetched GET /devices/schedules. Compared
            // against each RecordingSchedule's updated_at to tell whether a
            // given schedule has actually reached the device yet, or is
            // still waiting on the next periodic pull (see DeviceScheduleController).
            $table->timestamp('schedules_synced_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('schedules_synced_at');
        });
    }
};
