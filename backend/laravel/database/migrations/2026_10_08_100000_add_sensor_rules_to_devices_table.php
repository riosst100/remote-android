<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Sensor-automation rules: an array of { action, when, popup }
            // objects the device evaluates locally against its live sensor
            // readings to auto-trigger a popup or the flashlight.
            $table->jsonb('sensor_rules')->nullable()->after('alert_defaults');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('sensor_rules');
        });
    }
};
