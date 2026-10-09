<?php

namespace App\Jobs;

use App\Enums\MediaKind;
use App\Models\Recording;
use App\Services\RecordingLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Auto-stops a video recording once the device's configured maximum duration
 * (max_video_minutes) has elapsed. Scheduled by RecordingLifecycleService
 * when a video starts; a no-op if the recording already stopped on its own.
 */
class StopVideoRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $recordingId) {}

    public function handle(RecordingLifecycleService $lifecycle): void
    {
        $recording = Recording::query()->find($this->recordingId);

        if ($recording && $recording->media_kind === MediaKind::VIDEO && ! $recording->status->isTerminal()) {
            $lifecycle->stopVideo($recording);
        }
    }
}
