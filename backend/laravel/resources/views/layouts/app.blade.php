<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') &middot; Remote Recorder</title>
    <style>
        :root {
            --bg: #0f1115; --panel: #161922; --panel-2: #1d212c; --border: #2a2f3c;
            --text: #e6e8ee; --muted: #8b93a7; --accent: #4f8cff; --danger: #ff5c5c;
            --ok: #35d07f; --warn: #f5b942;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.5 -apple-system, Segoe UI, Roboto, sans-serif; }
        a { color: var(--accent); text-decoration: none; }
        header.topbar { display: flex; align-items: center; justify-content: space-between; padding: 12px 24px; border-bottom: 1px solid var(--border); }
        header.topbar nav a { margin-right: 18px; color: var(--muted); }
        header.topbar nav a.active, header.topbar nav a:hover { color: var(--text); }
        main { padding: 24px; max-width: 1200px; margin: 0 auto; }
        .card { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 18px; }
        .grid { display: grid; gap: 16px; }
        .grid.cols-4 { grid-template-columns: repeat(4, 1fr); }
        .grid.cols-2 { grid-template-columns: repeat(2, 1fr); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border); }
        th { color: var(--muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .badge.ONLINE, .badge.COMPLETED { background: rgba(53,208,127,.15); color: var(--ok); }
        .badge.OFFLINE { background: rgba(139,147,167,.15); color: var(--muted); }
        .badge.RECORDING, .badge.STARTING, .badge.STOPPING, .badge.PROCESSING { background: rgba(79,140,255,.15); color: var(--accent); }
        .badge.ERROR, .badge.FAILED { background: rgba(255,92,92,.15); color: var(--danger); }
        .badge.PENDING { background: rgba(245,185,66,.15); color: var(--warn); }
        button, .btn { cursor: pointer; border: 1px solid var(--border); background: var(--panel-2); color: var(--text); padding: 7px 14px; border-radius: 6px; font-size: 13px; }
        button.primary { background: var(--accent); border-color: var(--accent); color: white; }
        button.danger { background: var(--danger); border-color: var(--danger); color: white; }
        button:disabled { opacity: .5; cursor: not-allowed; }
        .stat { font-size: 28px; font-weight: 700; }
        .stat-label { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .muted { color: var(--muted); }
        select, input[type=text], input[type=email], input[type=password] {
            background: var(--panel-2); border: 1px solid var(--border); color: var(--text); padding: 8px 10px; border-radius: 6px;
        }
        .toast { position: fixed; bottom: 20px; right: 20px; background: var(--panel-2); border: 1px solid var(--border); padding: 10px 16px; border-radius: 8px; display: none; }
        code { background: var(--panel-2); padding: 1px 6px; border-radius: 4px; }
    </style>
    @stack('head')
</head>
<body>
@auth
<header class="topbar">
    <div><strong>Remote Recorder</strong></div>
    <nav>
        <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
        <a href="{{ route('devices.index') }}" class="{{ request()->routeIs('devices.*') ? 'active' : '' }}">Devices</a>
        <a href="{{ route('recordings.index') }}" class="{{ request()->routeIs('recordings.*') ? 'active' : '' }}">Recordings</a>
        <a href="{{ route('logs.index') }}" class="{{ request()->routeIs('logs.*') ? 'active' : '' }}">System Logs</a>
    </nav>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Log out ({{ auth()->user()->email }})</button>
    </form>
</header>
@endauth
<main>
    @if (session('status'))
        <div class="card" style="margin-bottom:16px;">{{ session('status') }}</div>
    @endif
    @yield('content')
</main>
<div id="toast" class="toast"></div>
<script>
    window.showToast = function (message, isError) {
        const el = document.getElementById('toast');
        el.textContent = message;
        el.style.borderColor = isError ? 'var(--danger)' : 'var(--border)';
        el.style.display = 'block';
        clearTimeout(window.__toastTimer);
        window.__toastTimer = setTimeout(() => { el.style.display = 'none'; }, 4000);
    };

    window.apiFetch = async function (url, options = {}) {
        const token = document.querySelector('meta[name="csrf-token"]').content;
        const response = await fetch(url, {
            ...options,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                ...(options.headers || {}),
            },
            credentials: 'same-origin',
        });

        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(body.message || 'Request failed.');
        }
        return body;
    };
</script>
@stack('scripts')
</body>
</html>
