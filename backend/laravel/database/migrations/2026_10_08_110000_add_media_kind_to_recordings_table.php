<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            // AUDIO (the original behaviour) or VIDEO. Drives which recorder
            // runs on the device and how parts are merged on the server.
            $table->string('media_kind')->default('AUDIO')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('recordings', function (Blueprint $table) {
            $table->dropColumn('media_kind');
        });
    }
};
