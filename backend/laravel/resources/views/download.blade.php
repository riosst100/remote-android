<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download Agent &middot; Remote Recorder</title>
    <style>
        :root {
            --bg: #0f1115; --panel: #161922; --panel-2: #1d212c; --border: #2a2f3c;
            --text: #e6e8ee; --muted: #8b93a7; --accent: #4f8cff; --ok: #35d07f;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: var(--bg); color: var(--text); font: 15px/1.6 -apple-system, Segoe UI, Roboto, sans-serif;
            padding: 24px;
        }
        .card {
            background: var(--panel); border: 1px solid var(--border); border-radius: 12px;
            padding: 36px; max-width: 440px; width: 100%; text-align: center;
        }
        h1 { font-size: 20px; margin: 0 0 6px; }
        p.lead { color: var(--muted); margin: 0 0 28px; font-size: 14px; }
        .btn {
            display: inline-block; width: 100%; padding: 14px; border-radius: 8px;
            background: var(--accent); color: white; font-weight: 600; text-decoration: none;
            font-size: 15px;
        }
        .btn:hover { filter: brightness(1.08); }
        .btn.disabled { background: var(--panel-2); color: var(--muted); pointer-events: none; }
        .meta { margin-top: 18px; color: var(--muted); font-size: 13px; }
        .meta code { background: var(--panel-2); padding: 1px 6px; border-radius: 4px; color: var(--text); }
        .steps { text-align: left; margin-top: 28px; padding-top: 24px; border-top: 1px solid var(--border); }
        .steps h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin: 0 0 12px; }
        .steps ol { margin: 0; padding-left: 20px; color: var(--text); font-size: 14px; }
        .steps li { margin-bottom: 8px; }
        .steps li:last-child { margin-bottom: 0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Device Service Agent</h1>
        <p class="lead">Install this on the Android phone or tablet you want to register as a recording device.</p>

        @if ($available)
            <a class="btn" href="{{ route('apk.download.file') }}">Download APK ({{ $sizeMb }} MB)</a>
            <div class="meta">Build published {{ \Illuminate\Support\Carbon::createFromTimestamp($builtAt)->diffForHumans() }}</div>
        @else
            <span class="btn disabled">No build available yet</span>
            <div class="meta">Check back once a build has been published.</div>
        @endif

        <div class="steps">
            <h2>Setup</h2>
            <ol>
                <li>Download and open the APK (allow installs from this source if prompted).</li>
                <li>Open <strong>Device Service</strong> and grant the microphone permission.</li>
                <li>Tap <strong>Register This Device</strong>.</li>
                <li>The device will appear in the admin dashboard once registered.</li>
            </ol>
        </div>
    </div>
</body>
</html>
