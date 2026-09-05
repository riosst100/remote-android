<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApkDownloadController extends Controller
{
    private const APK_FILENAME = 'app-latest.apk';

    public function show(): View
    {
        $path = public_path('downloads/'.self::APK_FILENAME);
        $available = is_file($path);

        return view('download', [
            'available' => $available,
            'sizeMb' => $available ? round(filesize($path) / 1024 / 1024, 1) : null,
            'builtAt' => $available ? filemtime($path) : null,
        ]);
    }

    public function download(): BinaryFileResponse
    {
        $path = public_path('downloads/'.self::APK_FILENAME);

        abort_unless(is_file($path), 404, 'No build has been published yet.');

        return response()->download($path, 'remote-recorder-agent.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }
}
