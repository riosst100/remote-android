<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Recording;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class RecordingDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $query = Recording::query()->with('device')->withCount('chunks')->latest('id');

        if ($request->filled('device_id')) {
            $query->where('device_id', $request->integer('device_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $recordings = $query->paginate(25)->withQueryString();

        return view('recordings.index', compact('recordings'));
    }

    public function show(Recording $recording): View
    {
        $recording->load(['device', 'chunks', 'commands']);

        return view('recordings.show', compact('recording'));
    }

    public function download(Recording $recording)
    {
        abort_unless($recording->file_path, 404);

        $disk = config('recorder.storage_disk');

        abort_unless(Storage::disk($disk)->exists($recording->file_path), 404);

        return Storage::disk($disk)->download(
            $recording->file_path,
            "recording-{$recording->uuid}.".pathinfo($recording->file_path, PATHINFO_EXTENSION)
        );
    }
}
