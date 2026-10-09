<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Auto-stop a video recording after this many minutes. Null = no
            // limit. Applies to manual and rule-triggered video starts.
            $table->unsignedInteger('max_video_minutes')->nullable()->after('alert_defaults');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('max_video_minutes');
        });
    }
};
