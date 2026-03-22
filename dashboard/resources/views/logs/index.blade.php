@extends('layouts.app')

@section('content')
  <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="mb-3 text-xl font-semibold">Security Logs</h2>
    <form method="GET" action="{{ route('logs.index') }}" class="flex flex-col gap-3 md:flex-row md:items-end md:flex-wrap">
      <div class="md:w-72">
        <label class="mb-1 block text-sm text-slate-600">Filter by domain</label>
        <select class="w-full rounded-lg border border-slate-300 px-3 py-2" name="domain_name">
          <option value="">All domains</option>
          @foreach(($domainOptions ?? []) as $optionDomain)
            <option value="{{ $optionDomain }}" @selected(($domainName ?? '') === $optionDomain)>{{ $optionDomain }}</option>
          @endforeach
        </select>
      </div>
      <div class="md:w-72">
        <label class="mb-1 block text-sm text-slate-600">Filter by event type</label>
        <select class="w-full rounded-lg border border-slate-300 px-3 py-2" name="event_type">
          <option value="">All events</option>
          @foreach(($eventTypeOptions ?? []) as $optionEvent)
            <option value="{{ $optionEvent }}" @selected(($eventType ?? '') === $optionEvent)>{{ $optionEvent }}</option>
          @endforeach
        </select>
      </div>
      <div class="md:w-72">
        <label class="mb-1 block text-sm text-slate-600">Filter by IP</label>
        <input class="w-full rounded-lg border border-slate-300 px-3 py-2" name="ip_address" value="{{ $ipAddress ?? '' }}" placeholder="e.g. 203.0.113.10">
      </div>
      <button class="rounded-lg bg-sky-500 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-400" type="submit">Filter</button>
      <a class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" href="{{ route('logs.index') }}">Reset</a>
    </form>
    @if($error)<div class="mt-3 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $error }}</div>@endif
  </div>

  <div class="relative left-1/2 w-[98vw] max-w-none -translate-x-1/2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="overflow-x-auto">
    <table class="w-full min-w-[1400px] text-sm">
      <thead class="bg-slate-50 text-left text-slate-600"><tr><th class="px-3 py-2">Domain</th><th class="px-3 py-2">Event</th><th class="px-3 py-2">IP</th><th class="px-3 py-2">requests</th><th class="px-3 py-2">allow</th><th class="px-3 py-2">ASN</th><th class="px-3 py-2">Country</th><th class="px-3 py-2">Path</th><th class="px-3 py-2">Details</th><th class="px-3 py-2">Time</th></tr></thead>
      <tbody>
      @forelse($logs as $row)
        <tr class="border-b border-slate-100">
          <td class="px-3 py-2 whitespace-nowrap">{{ $row['domain'] ?? '-' }}</td>
          <td class="px-3 py-2 whitespace-nowrap">{{ $row['event_type'] ?? '' }}</td>
          <td class="px-3 py-2 whitespace-nowrap">{{ $row['ip_address'] ?? '' }}</td>
          <td class="px-3 py-2 font-semibold text-slate-800">{{ $row['requests'] ?? 0 }}</td>
          <td class="px-3 py-2">
            @php($canAllow = !empty($row['ip_address']) && !empty($row['domain']) && ($row['domain'] !== '-'))
            @if($canAllow)
              <form method="POST" action="{{ route('logs.allow_ip') }}">
                @csrf
                <input type="hidden" name="ip" value="{{ $row['ip_address'] }}">
                <input type="hidden" name="domain" value="{{ $row['domain'] }}">
                <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-500">allow</button>
              </form>
            @else
              <span class="text-xs text-slate-400">غير متاح</span>
            @endif
          </td>
          <td class="px-3 py-2 whitespace-nowrap">{{ $row['asn'] ?? '' }}</td>
          <td class="px-3 py-2 whitespace-nowrap">{{ $row['country'] ?? '' }}</td>
          <td class="px-3 py-2 max-w-[320px] break-all">{{ $row['target_path'] ?? '' }}</td>
          <td class="px-3 py-2 max-w-[440px] break-words">{{ $row['details'] ?? '' }}</td>
          <td class="px-3 py-2 whitespace-nowrap">{{ $row['created_at'] ?? '' }}</td>
        </tr>
      @empty
        <tr><td colspan="10" class="px-3 py-3 text-slate-500">No logs.</td></tr>
      @endforelse
      </tbody>
    </table>
    </div>

    @if($logs->hasPages())
      <div class="mt-4">
        {{ $logs->onEachSide(1)->links() }}
      </div>
    @endif
  </div>
@endsection
