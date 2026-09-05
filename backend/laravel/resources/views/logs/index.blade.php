@extends('layouts.app')

@section('title', 'System Logs')

@section('content')
<h2 style="margin-top:0;">System Logs</h2>
<p class="muted">Most recent {{ count($lines) }} lines of storage/logs/laravel.log.</p>
<div class="card">
    <pre style="white-space:pre-wrap;word-break:break-word;max-height:70vh;overflow:auto;margin:0;font-size:12px;">@foreach ($lines as $line){{ $line }}@endforeach</pre>
</div>
@endsection
