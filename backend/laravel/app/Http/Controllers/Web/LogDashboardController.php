<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class LogDashboardController extends Controller
{
    private const MAX_LINES = 400;

    public function __invoke(): View
    {
        $path = storage_path('logs/laravel.log');
        $lines = [];

        if (is_file($path)) {
            $handle = fopen($path, 'r');
            $buffer = [];

            while (($line = fgets($handle)) !== false) {
                $buffer[] = $line;
                if (count($buffer) > self::MAX_LINES) {
                    array_shift($buffer);
                }
            }

            fclose($handle);
            $lines = array_reverse($buffer);
        }

        return view('logs.index', ['lines' => $lines]);
    }
}
