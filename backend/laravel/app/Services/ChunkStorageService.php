<?php

namespace App\Services;

class ChunkStorageService
{
    /**
     * Directory (relative to the "recordings" disk) a recording's chunk
     * and final files live under, partitioned by date for manageability:
     * recordings/YYYY/MM/DD/{recording-uuid}/
     */
    public function directoryFor(string $recordingUuid, \DateTimeInterface $createdAt): string
    {
        return sprintf(
            '%s/%s/%s',
            $createdAt->format('Y/m/d'),
            $recordingUuid,
            'chunks'
        );
    }

    public function finalDirectoryFor(string $recordingUuid, \DateTimeInterface $createdAt): string
    {
        return sprintf('%s/%s', $createdAt->format('Y/m/d'), $recordingUuid);
    }
}
