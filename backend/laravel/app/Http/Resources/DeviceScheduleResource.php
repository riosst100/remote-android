<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'day_of_week' => $this->day_of_week,
            'time_of_day' => $this->time_of_day,
            'preset' => $this->preset->value,
            'duration_minutes' => $this->duration_minutes,
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
