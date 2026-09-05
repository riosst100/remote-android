<?php

namespace App\Services;

use App\Enums\CommandStatus;
use App\Enums\RecordingStatus;
use App\Events\RecordingFailed;
use App\Models\DeviceCommand;
use Illuminate\Support\Facades\DB;

class CommandAcknowledgementService
{
    public function __construct(private readonly RecordingLifecycleService $lifecycle) {}

    public function acknowledge(DeviceCommand $command, string $event, array $payload): void
    {
        match ($event) {
            'command_received' => $this->markReceived($command),
            'recording_started' => $this->lifecycle->acknowledgeStarted($command, $payload['configuration'] ?? []),
            'recording_stopped' => $this->lifecycle->acknowledgeStopped($command),
            'recording_error' => $this->markFailed($command, $payload),
            default => null,
        };
    }

    private function markReceived(DeviceCommand $command): void
    {
        if ($command->status === CommandStatus::ACKNOWLEDGED || $command->status === CommandStatus::COMPLETED) {
            return;
        }

        $command->forceFill([
            'status' => CommandStatus::ACKNOWLEDGED,
            'received_at' => now(),
        ])->save();
    }

    private function markFailed(DeviceCommand $command, array $payload): void
    {
        DB::transaction(function () use ($command, $payload) {
            $command->forceFill([
                'status' => CommandStatus::FAILED,
                'received_at' => $command->received_at ?? now(),
                'completed_at' => now(),
                'error_message' => $payload['error_message'] ?? ($payload['error_code'] ?? 'Unknown error'),
            ])->save();

            $recording = $command->recording;
            if ($recording && ! $recording->status->isTerminal()) {
                $recording->forceFill([
                    'status' => RecordingStatus::FAILED,
                    'error_message' => $payload['error_message'] ?? ($payload['error_code'] ?? 'Unknown error'),
                ])->save();

                RecordingFailed::dispatch($recording->fresh());
            }
        });
    }
}
