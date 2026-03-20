@extends('layouts.app')

@section('content')
  <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="mb-1 text-xl font-semibold">Overview</h2>
    <p class="text-sm text-slate-500">Worker project path: <code class="rounded bg-slate-100 px-2 py-1 text-xs">{{ $projectRoot }}</code></p>
  </div>

  <div class="mb-4 grid gap-3 md:grid-cols-5">
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-2xl font-bold">{{ $stats['domains'] ?? 0 }}</p><p class="text-xs text-slate-500">Active Domains</p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-2xl font-bold">{{ $stats['events_last_24h'] ?? 0 }}</p><p class="text-xs text-slate-500">Events (24h)</p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-2xl font-bold">{{ $stats['challenges_issued'] ?? 0 }}</p><p class="text-xs text-slate-500">Challenges Issued</p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-2xl font-bold">{{ $stats['challenges_solved'] ?? 0 }}</p><p class="text-xs text-slate-500">Challenges Solved</p></div>
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-2xl font-bold">{{ $stats['hard_blocks'] ?? 0 }}</p><p class="text-xs text-slate-500">Hard Blocks</p></div>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h3 class="mb-3 text-lg font-semibold">Recent Security Events</h3>
    <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50 text-left text-slate-600"><tr><th class="px-3 py-2">ID</th><th class="px-3 py-2">Type</th><th class="px-3 py-2">IP</th><th class="px-3 py-2">Details</th><th class="px-3 py-2">Created</th></tr></thead>
      <tbody>
      @forelse($recent as $row)
        <tr class="border-b border-slate-100">
          <td class="px-3 py-2">{{ $row['id'] ?? '' }}</td>
          <td class="px-3 py-2">{{ $row['event_type'] ?? '' }}</td>
          <td class="px-3 py-2">{{ $row['ip_address'] ?? '' }}</td>
          <td class="px-3 py-2">{{ $row['details'] ?? '' }}</td>
          <td class="px-3 py-2">{{ $row['created_at'] ?? '' }}</td>
        </tr>
      @empty
        <tr><td colspan="5" class="px-3 py-3 text-slate-500">No events yet.</td></tr>
      @endforelse
      </tbody>
    </table>
    </div>
  </div>
@endsection
