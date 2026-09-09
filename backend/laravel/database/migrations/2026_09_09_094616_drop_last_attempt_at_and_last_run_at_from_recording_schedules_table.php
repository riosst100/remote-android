<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recording_schedules', function (Blueprint $table) {
            $table->dropColumn(['last_attempt_at', 'last_run_at']);
        });
    }

    public function down(): void
    {
        Schema::table('recording_schedules', function (Blueprint $table) {
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable()->after('last_run_at');
        });
    }
};
