@extends('layouts.app')

@section('content')
  <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="mb-3 text-xl font-semibold">Security Logs</h2>
    <form method="GET" action="{{ route('logs.index') }}" class="flex flex-col gap-3 md:flex-row md:items-end">
      <div class="flex-1">
        <label class="mb-1 block text-sm text-slate-600">Filter by event type</label>
        <input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="event_type" value="{{ $eventType }}" placeholder="challenge_failed / hard_block / ai_defense">
      </div>
      <button class="rounded-lg bg-sky-500 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-400" type="submit">Filter</button>
    </form>
    @if($error)<div class="mt-3 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $error }}</div>@endif
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50 text-left text-slate-600"><tr><th class="px-3 py-2">ID</th><th class="px-3 py-2">Event</th><th class="px-3 py-2">IP</th><th class="px-3 py-2">ASN</th><th class="px-3 py-2">Country</th><th class="px-3 py-2">Path</th><th class="px-3 py-2">Details</th><th class="px-3 py-2">Time</th></tr></thead>
      <tbody>
      @forelse($logs as $row)
        <tr class="border-b border-slate-100">
          <td class="px-3 py-2">{{ $row['id'] ?? '' }}</td>
          <td class="px-3 py-2">{{ $row['event_type'] ?? '' }}</td>
          <td class="px-3 py-2">{{ $row['ip_address'] ?? '' }}</td>
          <td class="px-3 py-2">{{ $row['asn'] ?? '' }}</td>
          <td class="px-3 py-2">{{ $row['country'] ?? '' }}</td>
          <td class="px-3 py-2">{{ $row['target_path'] ?? '' }}</td>
          <td class="px-3 py-2">{{ $row['details'] ?? '' }}</td>
          <td class="px-3 py-2">{{ $row['created_at'] ?? '' }}</td>
        </tr>
      @empty
        <tr><td colspan="8" class="px-3 py-3 text-slate-500">No logs.</td></tr>
      @endforelse
      </tbody>
    </table>
    </div>
  </div>
@endsection
