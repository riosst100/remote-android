<?php

namespace App\Services;

use App\Enums\RecordingStatus;
use App\Events\RecordingCompleted;
use App\Events\RecordingFailed;
use App\Exceptions\FinalizationException;
use App\Models\Recording;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RecordingFinalizationService
{
    public function __construct(private readonly ChunkStorageService $paths) {}

    /**
     * Finalize a recording by verifying its chunk sequence and
     * concatenating them into one final file. Idempotent: calling this on
     * an already-COMPLETED recording just returns it unchanged.
     *
     * AAC-ADTS and Opus/Ogg chunk streams can be safely byte-concatenated
     * without re-encoding, which is what we do here to avoid the CPU cost
     * and quality loss of a transcode. If a future encoder/container needs
     * real re-muxing, isolate that behind this same service so callers
     * never need to know the difference.
     *
     * Failure handling is deliberately kept outside the success-path
     * transaction: if merging throws partway through, we still want the
     * recording's FAILED status to be committed rather than rolled back
     * along with the aborted merge.
     *
     * @throws FinalizationException
     */
    public function finalize(Recording $recording): Recording
    {
        $recording = $recording->fresh();

        if ($recording->status === RecordingStatus::COMPLETED) {
            return $recording;
        }

        try {
            return DB::transaction(function () use ($recording) {
                $recording = Recording::query()->lockForUpdate()->findOrFail($recording->id);

                if ($recording->status === RecordingStatus::COMPLETED) {
                    return $recording;
                }

                $chunks = $recording->chunks()->orderBy('chunk_number')->get();

                $this->assertContiguousSequence($chunks);
                $this->assertChecksums($chunks);

                $disk = config('recorder.storage_disk');
                $storage = Storage::disk($disk);
                $finalDir = $this->paths->finalDirectoryFor($recording->uuid, $recording->created_at);
                $mimeType = $chunks->first()?->mime_type ?? 'application/octet-stream';
                $finalPath = "{$finalDir}/final.".$this->extensionFor($mimeType);

                $storage->makeDirectory($finalDir);
                $this->concatenateChunks($storage, $chunks, $finalPath);

                $recording->forceFill([
                    'status' => RecordingStatus::COMPLETED,
                    'file_path' => $finalPath,
                    'file_size' => $storage->size($finalPath),
                    'mime_type' => $mimeType,
                    'duration' => $recording->duration ?? $chunks->sum('duration'),
                    'error_message' => null,
                ])->save();

                // Chunks are intermediate storage only; once merged into the
                // final file both the file and its DB record are removed to
                // avoid keeping two copies of the audio.
                foreach ($chunks as $chunk) {
                    $storage->delete($chunk->file_path);
                    $chunk->delete();
                }

                RecordingCompleted::dispatch($recording->fresh());

                return $recording->fresh();
            });
        } catch (FinalizationException $e) {
            Log::error('Recording finalization failed', [
                'recording_id' => $recording->uuid,
                'error' => $e->getMessage(),
            ]);

            $recording->forceFill([
                'status' => RecordingStatus::FAILED,
                'error_message' => $e->getMessage(),
            ])->save();

            RecordingFailed::dispatch($recording->fresh());

            throw $e;
        }
    }

    private function concatenateChunks($storage, $chunks, string $finalPath): void
    {
        $stream = fopen($storage->path($finalPath), 'wb');
        if ($stream === false) {
            throw new FinalizationException('Unable to open destination file for writing.');
        }

        foreach ($chunks as $chunk) {
            $chunkStream = $storage->readStream($chunk->file_path);
            if ($chunkStream === null) {
                fclose($stream);
                throw new FinalizationException("Missing chunk file for chunk {$chunk->chunk_number}.");
            }
            stream_copy_to_stream($chunkStream, $stream);
            fclose($chunkStream);
        }

        fclose($stream);
    }

    private function assertContiguousSequence($chunks): void
    {
        if ($chunks->isEmpty()) {
            throw new FinalizationException('No chunks were uploaded for this recording.');
        }

        $expected = 1;
        foreach ($chunks as $chunk) {
            if ($chunk->chunk_number !== $expected) {
                throw new FinalizationException("Chunk sequence gap: expected chunk {$expected}, found {$chunk->chunk_number}.");
            }
            $expected++;
        }
    }

    private function assertChecksums($chunks): void
    {
        $disk = config('recorder.storage_disk');
        $storage = Storage::disk($disk);

        foreach ($chunks as $chunk) {
            if (! $storage->exists($chunk->file_path)) {
                throw new FinalizationException("Chunk file missing on disk for chunk {$chunk->chunk_number}.");
            }

            $actual = hash_file('sha256', $storage->path($chunk->file_path));
            if (! hash_equals($actual, $chunk->checksum)) {
                throw new FinalizationException("Stored chunk {$chunk->chunk_number} failed checksum verification.");
            }
        }
    }

    private function extensionFor(string $mimeType): string
    {
        return match ($mimeType) {
            'audio/aac', 'audio/aac-adts' => 'aac',
            'audio/opus', 'audio/ogg' => 'ogg',
            'audio/flac' => 'flac',
            default => 'audio',
        };
    }
}
