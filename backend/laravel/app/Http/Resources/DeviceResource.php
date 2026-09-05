<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_uuid' => $this->device_uuid,
            'name' => $this->name,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'android_version' => $this->android_version,
            'app_version' => $this->app_version,
            'capabilities' => $this->capabilities,
            'status' => $this->status->value,
            'last_seen_at' => optional($this->last_seen_at)->toIso8601String(),
            'current_recording' => $this->whenLoaded('recordings', function () {
                $active = $this->recordings->firstWhere(fn ($r) => ! $r->status->isTerminal());

                return $active ? [
                    'uuid' => $active->uuid,
                    'status' => $active->status->value,
                ] : null;
            }),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
