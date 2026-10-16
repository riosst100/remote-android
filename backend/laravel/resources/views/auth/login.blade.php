@extends('layouts.app')

@section('title', 'Log in')

@section('content')
<div style="max-width:440px;margin:clamp(48px, 12vh, 120px) auto;">
    <div style="text-align:center;margin-bottom:32px;">
        <div style="width:56px;height:56px;background:var(--accent);border-radius:14px;display:inline-flex;align-items:center;justify-content:center;color:white;margin-bottom:16px;box-shadow:0 8px 24px rgba(59,130,246,0.3);">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M2 12h20"/></svg>
        </div>
        <h1 style="font-size:24px;margin-bottom:8px;">Welcome Back</h1>
        <p class="muted">Sign in to manage your remote recorder fleet.</p>
    </div>

    <div class="card" style="padding:40px;">
        @if ($errors->any())
            <div style="background:rgba(239,68,68,0.1);border-left:4px solid var(--danger);padding:12px 16px;border-radius:6px;margin-bottom:24px;color:var(--danger);font-size:13px;font-weight:600;">
                {{ $errors->first() }}
            </div>
        @endif
        <form method="POST" action="{{ route('login.attempt') }}">
            @csrf
            <div style="margin-bottom:20px;">
                <label for="email" class="stat-label" style="display:block;margin-bottom:8px;">Work Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus placeholder="admin@example.com">
            </div>
            <div style="margin-bottom:24px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <label for="password" class="stat-label">Password</label>
                    <a href="#" class="muted" style="font-size:11px;">Forgot password?</a>
                </div>
                <input id="password" type="password" name="password" autocomplete="current-password" required placeholder="••••••••">
            </div>
            <button type="submit" class="primary" style="width:100%;height:46px;font-size:15px;">Sign in to Dashboard</button>
        </form>
    </div>
    
    <p class="muted" style="text-align:center;margin-top:32px;font-size:12px;">
        &copy; {{ date('Y') }} Remote Recorder Systems. All rights reserved.
    </p>
</div>
@endsection
