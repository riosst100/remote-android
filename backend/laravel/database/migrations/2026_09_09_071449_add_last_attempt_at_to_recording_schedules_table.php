<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recording_schedules', function (Blueprint $table) {
            // First time this occurrence was seen due but couldn't start
            // (device offline). Marks the start of the retry window; cleared
            // once the occurrence starts successfully or the window lapses.
            $table->timestamp('last_attempt_at')->nullable()->after('last_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('recording_schedules', function (Blueprint $table) {
            $table->dropColumn('last_attempt_at');
        });
    }
};
