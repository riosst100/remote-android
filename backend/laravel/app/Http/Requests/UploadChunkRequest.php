<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'chunk_number' => ['required', 'integer', 'min:1'],
            'checksum' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'duration' => ['nullable', 'integer', 'min:0'],
            // 1GB: recordings now upload as one file after stop() rather
            // than in small rotating chunks, so a long session's single
            // upload can be large. Matches upload_max_filesize/post_max_size
            // in php.ini and client_max_body_size in the Nginx site config.
            'file' => ['required', 'file', 'max:1048576'],
            // The device declares what it actually encoded — trusted over
            // sniffing the file's bytes, since FLAC and AAC-ADTS frame sync
            // codes are similar enough that content-based detection
            // misidentifies real FLAC chunks as AAC (see ChunkUploadService).
            'mime_type' => ['nullable', 'string', 'in:audio/aac,audio/flac,audio/opus'],
        ];
    }
}
