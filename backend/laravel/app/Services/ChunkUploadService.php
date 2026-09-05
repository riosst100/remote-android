<?php

namespace App\Services;

use App\Enums\RecordingStatus;
use App\Events\ChunkUploaded;
use App\Exceptions\ChecksumMismatchException;
use App\Models\Recording;
use App\Models\RecordingChunk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ChunkUploadService
{
    public function __construct(private readonly ChunkStorageService $paths) {}

    /**
     * Store an uploaded chunk. Idempotent on (recording_id, chunk_number):
     * if the chunk already exists, the existing record is returned as-is
     * (the newly uploaded file is discarded) rather than creating a
     * duplicate or overwriting previously accepted audio.
     *
     * @throws ChecksumMismatchException
     */
    public function store(Recording $recording, UploadedFile $file, int $chunkNumber, string $checksum, ?int $duration): RecordingChunk
    {
        $actualChecksum = hash_file('sha256', $file->getRealPath());

        if (! hash_equals($actualChecksum, $checksum)) {
            throw new ChecksumMismatchException("Checksum mismatch for chunk {$chunkNumber}.");
        }

        return DB::transaction(function () use ($recording, $file, $chunkNumber, $checksum, $duration) {
            $existing = RecordingChunk::query()
                ->where('recording_id', $recording->id)
                ->where('chunk_number', $chunkNumber)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $disk = config('recorder.storage_disk');
            $directory = $this->paths->directoryFor($recording->uuid, $recording->created_at);
            $filename = sprintf('%05d-%s.%s', $chunkNumber, substr($checksum, 0, 12), $file->getClientOriginalExtension() ?: 'aac');

            $storedPath = $file->storeAs($directory, $filename, ['disk' => $disk]);

            $chunk = RecordingChunk::query()->create([
                'recording_id' => $recording->id,
                'chunk_number' => $chunkNumber,
                'file_path' => $storedPath,
                'size' => Storage::disk($disk)->size($storedPath),
                'checksum' => $checksum,
                'duration' => $duration,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'uploaded_at' => now(),
            ]);

            if ($recording->status === RecordingStatus::STARTING) {
                $recording->forceFill(['status' => RecordingStatus::RECORDING])->save();
            }

            ChunkUploaded::dispatch($chunk);

            return $chunk;
        });
    }
}
