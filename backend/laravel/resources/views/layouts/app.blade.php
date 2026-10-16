<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="reverb-port" content="{{ config('reverb.apps.apps.0.options.port', 8080) }}">
    <meta name="reverb-scheme" content="{{ config('reverb.apps.apps.0.options.scheme', 'http') }}">
    <meta name="reverb-key" content="{{ config('reverb.apps.apps.0.key') }}">
    <title>@yield('title', 'Dashboard') &middot; Remote Recorder</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    <style>
        :root {
            color-scheme: dark;
            --bg: #0b0f17; --panel: #111827; --panel-2: #1f2937; --border: #374151;
            --text: #f9fafb; --muted: #9ca3af; --accent: #3b82f6; --danger: #ef4444;
            --ok: #10b981; --warn: #f59e0b; --recording: #dc2626;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.6 'Instrument Sans', ui-sans-serif, system-ui, -apple-system, sans-serif; -webkit-font-smoothing: antialiased; }
        a { color: var(--accent); text-decoration: none; transition: color 0.2s; }
        a:hover { color: #60a5fa; text-decoration: none; }
        
        header.topbar { 
            display: flex; align-items: center; justify-content: space-between; gap: 24px; 
            padding: 0 32px; height: 64px; border-bottom: 1px solid var(--border); 
            background: var(--panel); position: sticky; top: 0; z-index: 50;
        }
        header.topbar .logo { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 16px; letter-spacing: -0.01em; color: var(--text); }
        header.topbar .logo .icon { width: 24px; height: 24px; background: var(--accent); border-radius: 6px; display: flex; align-items: center; justify-content: center; }
        
        header.topbar nav { display: flex; align-items: center; gap: 4px; height: 100%; }
        header.topbar nav a { 
            height: 100%; display: flex; align-items: center; padding: 0 16px; 
            color: var(--muted); font-weight: 500; font-size: 14px; border-bottom: 2px solid transparent;
        }
        header.topbar nav a.active { color: var(--text); border-bottom-color: var(--accent); background: linear-gradient(to top, rgba(59, 130, 246, 0.05), transparent); }
        header.topbar nav a:hover:not(.active) { color: var(--text); background: var(--panel-2); }
        
        .user-menu { display: flex; align-items: center; gap: 12px; }
        .user-email { font-size: 13px; color: var(--muted); }
        
        main { padding: 40px 32px 64px; max-width: 1440px; margin: 0 auto; }
        
        h1, h2, h3, h4 { margin: 0; line-height: 1.2; letter-spacing: -0.02em; font-weight: 700; }
        h1 { font-size: 32px; }
        h2 { font-size: 24px; }
        h3 { font-size: 18px; }
        
        .card { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 24px; overflow: hidden; }
        .grid { display: grid; gap: 20px; }
        .grid.cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .grid.cols-5 { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        .grid.cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .grid.cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        
        @media (max-width: 1024px) { 
            .grid.cols-5, .grid.cols-4, .grid.cols-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); } 
        }
        @media (max-width: 640px) {
            header.topbar { padding: 0 16px; height: auto; flex-direction: column; py: 12px; }
            header.topbar nav { width: 100%; overflow-x: auto; height: 48px; }
            main { padding: 24px 16px; }
            .grid { grid-template-columns: 1fr !important; }
        }

        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        th { text-align: left; padding: 12px 16px; border-bottom: 1px solid var(--border); color: var(--muted); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; background: rgba(255,255,255,0.02); }
        td { padding: 14px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tr:last-child td { border-bottom: 0; }
        tbody tr:hover td { background: rgba(255,255,255,0.02); }
        
        .badge { 
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 99px; 
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em;
            background: rgba(156, 163, 175, 0.1); color: var(--muted);
        }
        .badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .badge.ONLINE, .badge.COMPLETED { background: rgba(16, 185, 129, 0.1); color: var(--ok); }
        .badge.OFFLINE { background: rgba(156, 163, 175, 0.1); color: var(--muted); }
        .badge.RECORDING, .badge.STARTING, .badge.STOPPING, .badge.PROCESSING { background: rgba(59, 130, 246, 0.1); color: var(--accent); }
        .badge.RECORDING::before { animation: pulse 2s infinite; background: var(--recording); color: var(--recording); }
        .badge.ERROR, .badge.FAILED { background: rgba(239, 68, 68, 0.1); color: var(--danger); }
        .badge.PENDING { background: rgba(245, 158, 11, 0.1); color: var(--warn); }
        
        @keyframes pulse { 0% { transform: scale(0.95); opacity: 1; } 70% { transform: scale(1.5); opacity: 0; } 100% { transform: scale(0.95); opacity: 0; } }

        button, .btn { 
            cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; 
            border: 1px solid var(--border); background: var(--panel-2); color: var(--text); 
            padding: 10px 18px; border-radius: 8px; font: inherit; font-size: 13px; font-weight: 600; 
            transition: all 0.2s; white-space: nowrap;
        }
        button:hover, .btn:hover { background: #2d3748; border-color: #4a5568; }
        button.primary, .btn.primary { background: var(--accent); border-color: var(--accent); color: white; }
        button.primary:hover, .btn.primary:hover { background: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25); }
        button.danger { color: var(--danger); }
        button.danger:hover { background: rgba(239, 68, 68, 0.1); border-color: var(--danger); }
        button:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; box-shadow: none !important; }
        
        .stat-value { font-size: 32px; font-weight: 800; line-height: 1; margin-bottom: 8px; }
        .stat-label { color: var(--muted); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        
        input:not([type=checkbox]):not([type=radio]), select, textarea {
            background: var(--panel-2); border: 1px solid var(--border); color: var(--text); 
            padding: 10px 14px; border-radius: 8px; font: inherit; font-size: 14px; width: 100%;
            transition: border-color 0.2s, outline 0.2s;
        }
        input:focus, select:focus, textarea:focus { border-color: var(--accent); outline: 3px solid rgba(59, 130, 246, 0.2); }
        
        .toast { 
            position: fixed; bottom: 32px; right: 32px; z-index: 100; min-width: 320px;
            background: var(--panel); border: 1px solid var(--border); box-shadow: 0 12px 32px rgba(0,0,0,0.4); 
            padding: 16px 20px; border-radius: 12px; display: none; animation: slideUp 0.3s ease-out;
        }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        
        code { font-family: 'JetBrains Mono', 'Geist Mono', monospace; background: rgba(255,255,255,0.05); padding: 2px 6px; border-radius: 4px; color: var(--accent); font-size: 0.9em; }
        .muted { color: var(--muted); }
    </style>
    @stack('head')
</head>
<body>
@auth
<header class="topbar">
    <div class="logo">
        <div class="icon">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M2 12h20"/></svg>
        </div>
        Remote Recorder
    </div>
    <nav>
        <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
        <a href="{{ route('devices.index') }}" class="{{ request()->routeIs('devices.*') ? 'active' : '' }}">Devices</a>
        <a href="{{ route('recordings.index') }}" class="{{ request()->routeIs('recordings.*') ? 'active' : '' }}">Recordings</a>
        <a href="{{ route('logs.index') }}" class="{{ request()->routeIs('logs.*') ? 'active' : '' }}">System Logs</a>
    </nav>
    <div class="user-menu">
        <span class="user-email">{{ auth()->user()->email }}</span>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn-sm" style="padding:6px 12px;font-size:12px;">Logout</button>
        </form>
    </div>
</header>
@endauth
<main>
    @if (session('status'))
        <div class="card" style="margin-bottom:24px;border-color:var(--ok);background:rgba(16,185,129,0.05);">{{ session('status') }}</div>
    @endif
    @yield('content')
</main>
<div id="toast" class="toast"></div>
@auth
<script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js"></script>
@endauth
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

    @auth
    /**
     * Thin realtime layer over pusher-js talking directly to Reverb
     * (Pusher-protocol compatible), rather than pulling in the full
     * laravel-echo package — Echo's API surface beyond subscribe/bind
     * isn't needed here and this avoids requiring a JS build step for a
     * server-rendered dashboard. The host is read from the page's own
     * location rather than a fixed .env value so this works identically
     * whether the dashboard is opened via 127.0.0.1 or a LAN IP.
     */
    window.realtimeReady = new Promise((resolve) => {
        const pusher = new Pusher(document.querySelector('meta[name="reverb-key"]').content, {
            // Reverb is self-hosted, not Pusher Cloud, so there is no real
            // cluster — but pusher-js 8.x throws "Options object must provide
            // a cluster" unless one is present. Any non-empty value works
            // because wsHost/wsPort below point the client straight at Reverb.
            cluster: 'reverb',
            wsHost: window.location.hostname,
            wsPort: Number(document.querySelector('meta[name="reverb-port"]').content),
            wssPort: Number(document.querySelector('meta[name="reverb-port"]').content),
            forceTLS: document.querySelector('meta[name="reverb-scheme"]').content === 'https',
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            authorizer: (channel) => ({
                authorize: (socketId, callback) => {
                    window.apiFetch('/api/broadcasting/auth', {
                        method: 'POST',
                        body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
                    }).then((data) => callback(false, data)).catch((err) => callback(true, err));
                },
            }),
        });

        pusher.connection.bind('connected', () => resolve(pusher));
    });
    @endauth
</script>
@stack('scripts')
</body>
</html>
