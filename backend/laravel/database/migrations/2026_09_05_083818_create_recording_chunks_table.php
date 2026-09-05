<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recording_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recording_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_number');
            $table->string('file_path');
            $table->unsignedBigInteger('size');
            $table->string('checksum');
            $table->unsignedInteger('duration')->nullable();
            $table->string('mime_type');
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->unique(['recording_id', 'chunk_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_chunks');
    }
};
