@extends('layouts.app')

@section('content')
  <div class="es-card es-animate mb-4 p-5 md:p-6">
    <h2 class="es-title mb-2">Overview</h2>
    <p class="es-subtitle">Worker project path: <code class="rounded-md border border-white/15 bg-slate-900/70 px-2 py-1 text-xs text-sky-200">{{ $projectRoot }}</code></p>
  </div>

  <div class="mb-4 grid gap-3 md:grid-cols-5">
    <div class="es-card es-animate es-animate-delay p-4"><p class="text-3xl font-black text-cyan-200">{{ $stats['domains'] ?? 0 }}</p><p class="es-subtitle mt-1">Active Domains</p></div>
    <div class="es-card es-animate es-animate-delay p-4"><p class="text-3xl font-black text-cyan-200">{{ $stats['events_last_24h'] ?? 0 }}</p><p class="es-subtitle mt-1">Events (24h)</p></div>
    <div class="es-card es-animate es-animate-delay p-4"><p class="text-3xl font-black text-cyan-200">{{ $stats['challenges_issued'] ?? 0 }}</p><p class="es-subtitle mt-1">Challenges Issued</p></div>
    <div class="es-card es-animate es-animate-delay p-4"><p class="text-3xl font-black text-cyan-200">{{ $stats['challenges_solved'] ?? 0 }}</p><p class="es-subtitle mt-1">Challenges Solved</p></div>
    <div class="es-card es-animate es-animate-delay p-4"><p class="text-3xl font-black text-cyan-200">{{ $stats['hard_blocks'] ?? 0 }}</p><p class="es-subtitle mt-1">Hard Blocks</p></div>
  </div>

  <div class="es-card es-animate es-animate-delay-2 p-5 md:p-6">
    <h3 class="mb-3 text-lg font-bold text-sky-100">Recent Security Events</h3>
    <div class="overflow-x-auto">
    <table class="es-table min-w-full">
      <thead><tr><th>ID</th><th>Type</th><th>IP</th><th>Details</th><th>Created</th></tr></thead>
      <tbody>
      @forelse($recent as $row)
        <tr>
          <td>{{ $row['id'] ?? '' }}</td>
          <td>{{ $row['event_type'] ?? '' }}</td>
          <td>{{ $row['ip_address'] ?? '' }}</td>
          <td>{{ $row['details'] ?? '' }}</td>
          <td>{{ $row['created_at'] ?? '' }}</td>
        </tr>
      @empty
        <tr><td colspan="5" class="text-slate-300">No events yet.</td></tr>
      @endforelse
      </tbody>
    </table>
    </div>
  </div>
@endsection
