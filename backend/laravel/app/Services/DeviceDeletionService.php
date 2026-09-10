<?php

namespace App\Services;

use App\Exceptions\DeviceHasActiveRecordingException;
use App\Enums\RecordingStatus;
use App\Models\Device;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeviceDeletionService
{
    public function __construct(private readonly ChunkStorageService $paths) {}

    /**
     * Permanently removes a device and everything that belongs to it:
     * recordings, chunks, commands, and schedules are all handled by the
     * DB's cascadeOnDelete foreign keys (see the recordings/recording_chunks/
     * device_commands/recording_schedules migrations) once the device row
     * itself is deleted. What cascade can't do is remove the audio files
     * those recordings point to on the storage disk — those live outside
     * the database entirely, so they're deleted here first, before the DB
     * rows (and the file_path/chunk paths needed to find them) disappear.
     *
     * @throws DeviceHasActiveRecordingException if the device has a
     *   recording still in progress — deleting mid-recording would cut off
     *   the device's ability to ever register/complete it (the recording
     *   row cascade-deletes along with the device), silently losing
     *   whatever audio it was in the middle of capturing.
     */
    public function delete(Device $device): void
    {
        $device->load('recordings');

        if ($device->recordings->contains(fn ($recording) => ! $recording->status->isTerminal())) {
            throw new DeviceHasActiveRecordingException(
                'This device has a recording in progress. Stop it before deleting the device.'
            );
        }

        $disk = Storage::disk(config('recorder.storage_disk'));

        foreach ($device->recordings as $recording) {
            $directory = $this->paths->finalDirectoryFor($recording->uuid, $recording->created_at);

            try {
                $disk->deleteDirectory($directory);
            } catch (\Throwable $e) {
                // A missing/already-cleaned-up directory (e.g. a recording
                // that never got past STARTING and never wrote any file)
                // must not block deleting the device itself — log and move
                // on rather than leaving the device stuck undeletable.
                Log::warning('Failed to delete recording directory during device deletion', [
                    'device_id' => $device->id,
                    'recording_uuid' => $recording->uuid,
                    'directory' => $directory,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        DB::transaction(function () use ($device) {
            $device->delete();
        });
    }
}
