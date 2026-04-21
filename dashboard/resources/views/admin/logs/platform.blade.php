@extends('layouts.admin')

@section('content')
  <div class="mb-5 flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
    <div>
      <h1 class="es-title">System Logs</h1>
      <p class="es-subtitle mt-2">Read-only Laravel log viewer, newest entries first.</p>
    </div>
    <div class="flex gap-2">
      <a href="{{ route('admin.logs.security') }}" class="es-btn es-btn-secondary">Security Logs</a>
      <a href="{{ route('admin.logs.platform') }}" class="es-btn">Platform Logs</a>
    </div>
  </div>

  <div class="mb-5 grid gap-3 md:grid-cols-3">
    @forelse($logFiles as $file)
      <div class="es-card p-4">
        <div class="font-semibold text-white">{{ $file['name'] }}</div>
        <div class="mt-1 text-xs text-sky-100/60">{{ $file['updated_at'] }} / {{ number_format($file['size']) }} bytes</div>
      </div>
    @empty
      <div class="es-card p-4 text-sm text-sky-100/70">No log files found.</div>
    @endforelse
  </div>

  <div class="space-y-3">
    @forelse($entries as $entry)
      <div class="rounded-lg border border-white/10 bg-black/25 p-4">
        <div class="mb-2 text-xs font-bold uppercase tracking-[0.16em] text-cyan-200">{{ $entry['file'] }}</div>
        <pre class="max-h-80 overflow-auto whitespace-pre-wrap text-xs leading-6 text-sky-100/80">{{ $entry['text'] }}</pre>
      </div>
    @empty
      <div class="es-card p-5 text-sm text-sky-100/70">No platform log entries found.</div>
    @endforelse
  </div>
@endsection
