<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Last-used alert popup settings (title, message, volume,
            // brightness), so the dashboard form remembers them per device.
            $table->jsonb('alert_defaults')->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('alert_defaults');
        });
    }
};
