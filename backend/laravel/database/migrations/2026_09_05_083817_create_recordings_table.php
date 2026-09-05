<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recordings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('PENDING');
            $table->string('preset')->default('HIGH');

            $table->string('encoder')->nullable();
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedInteger('bitrate')->nullable();
            $table->unsignedTinyInteger('channels')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->unsignedInteger('duration')->nullable();

            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index(['device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recordings');
    }
};
