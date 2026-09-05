<?php

namespace App\Jobs;

use App\Models\Recording;
use App\Services\RecordingLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Stops a recording that was started by RecordingScheduleService once its
 * configured duration has elapsed. Dispatched with a delay at schedule
 * trigger time rather than polled for, so no extra scheduler tick is
 * needed to notice a scheduled recording is due to end.
 */
class StopScheduledRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $recordingId) {}

    public function handle(RecordingLifecycleService $lifecycle): void
    {
        $recording = Recording::query()->find($this->recordingId);

        // Idempotent no-op if the recording doesn't exist (deleted) or has
        // already been stopped/completed/failed by the time this runs —
        // RecordingLifecycleService::stop() itself is idempotent too.
        if ($recording === null) {
            return;
        }

        $lifecycle->stop($recording);
    }
}
