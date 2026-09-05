@extends('layouts.app')

@section('title', 'Log in')

@section('content')
<div style="max-width:360px;margin:80px auto;">
    <div class="card">
        <h2 style="margin-top:0;">Remote Recorder Admin</h2>
        @if ($errors->any())
            <p style="color:var(--danger);">{{ $errors->first() }}</p>
        @endif
        <form method="POST" action="{{ route('login.attempt') }}">
            @csrf
            <div style="margin-bottom:12px;">
                <label style="display:block;margin-bottom:4px;color:var(--muted);">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus style="width:100%;">
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:block;margin-bottom:4px;color:var(--muted);">Password</label>
                <input type="password" name="password" required style="width:100%;">
            </div>
            <button type="submit" class="primary" style="width:100%;">Log in</button>
        </form>
    </div>
</div>
@endsection
