@extends('layouts.app')

@section('title', 'System Logs')

@section('content')
<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:24px;margin-bottom:32px;">
    <div>
        <h1 style="margin-bottom:8px;">System Logs</h1>
        <p class="muted" style="margin:0;">Recent system events and internal server diagnostics.</p>
    </div>
</div>

<div class="card" style="padding:0;border-color:var(--border);">
    <div style="background:rgba(255,255,255,0.02);padding:12px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
        <span class="muted" style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;">storage/logs/laravel.log</span>
        <span class="muted" style="font-size:11px;">Showing last {{ count($lines) }} lines</span>
    </div>
    <pre style="white-space:pre-wrap;word-break:break-word;max-height:75vh;overflow:auto;margin:0;padding:24px;font-family:'JetBrains Mono', 'Geist Mono', monospace;font-size:12px;line-height:1.6;color:var(--muted);">@foreach ($lines as $line){{ $line }}@endforeach</pre>
</div>
@endsection
