@extends('layouts.app')

@section('content')
  <div class="es-card es-animate mb-4 p-5 md:p-6">
    <h2 class="es-title mb-3">Security Logs</h2>
    <form method="GET" action="{{ route('logs.index') }}" class="flex flex-col gap-3 md:flex-row md:items-end md:flex-wrap">
      <div class="md:w-72">
        <label class="mb-1 block text-sm text-sky-100">Filter by domain</label>
        <select class="es-input" name="domain_name">
          <option value="">All domains</option>
          @foreach(($domainOptions ?? []) as $optionDomain)
            <option value="{{ $optionDomain }}" @selected(($domainName ?? '') === $optionDomain)>{{ $optionDomain }}</option>
          @endforeach
        </select>
      </div>
      <div class="md:w-72">
        <label class="mb-1 block text-sm text-sky-100">Filter by event type</label>
        <select class="es-input" name="event_type">
          <option value="">All events</option>
          @foreach(($eventTypeOptions ?? []) as $optionEvent)
            <option value="{{ $optionEvent }}" @selected(($eventType ?? '') === $optionEvent)>{{ $optionEvent }}</option>
          @endforeach
        </select>
      </div>
      <div class="md:w-72">
        <label class="mb-1 block text-sm text-sky-100">Filter by IP</label>
        <input class="es-input" name="ip_address" value="{{ $ipAddress ?? '' }}" placeholder="e.g. 203.0.113.10">
      </div>
      <button class="es-btn" type="submit">Filter</button>
      <a class="es-btn es-btn-secondary" href="{{ route('logs.index') }}">Reset</a>
    </form>
    @if($error)<div class="mt-3 rounded-xl border border-rose-400/30 bg-rose-500/15 px-4 py-3 text-sm text-rose-200">{{ $error }}</div>@endif
  </div>

  <div class="es-card es-animate es-animate-delay relative left-1/2 w-[98vw] max-w-none -translate-x-1/2 p-5 md:p-6">
    <div class="overflow-x-auto">
    <table class="es-table min-w-[1400px]">
      <thead><tr><th>Domain</th><th>Event</th><th>IP</th><th>Requests</th><th>Allow</th><th>ASN</th><th>Country</th><th>Path</th><th>Details</th><th>Time</th></tr></thead>
      <tbody>
      @forelse($logs as $row)
        <tr>
          <td class="whitespace-nowrap">{{ $row['domain'] ?? '-' }}</td>
          <td class="whitespace-nowrap">{{ $row['event_type'] ?? '' }}</td>
          <td class="whitespace-nowrap">{{ $row['ip_address'] ?? '' }}</td>
          <td class="font-semibold text-cyan-200">{{ $row['requests'] ?? 0 }}</td>
          <td>
            @php($canAllow = !empty($row['ip_address']) && !empty($row['domain']) && ($row['domain'] !== '-'))
            @if($canAllow)
              <form method="POST" action="{{ route('logs.allow_ip') }}">
                @csrf
                <input type="hidden" name="ip" value="{{ $row['ip_address'] }}">
                <input type="hidden" name="domain" value="{{ $row['domain'] }}">
                <button type="submit" class="es-btn es-btn-success">Allow</button>
              </form>
            @else
              <span class="text-xs es-muted">N/A</span>
            @endif
          </td>
          <td class="whitespace-nowrap">{{ $row['asn'] ?? '' }}</td>
          <td class="whitespace-nowrap">{{ $row['country'] ?? '' }}</td>
          <td class="max-w-[320px] break-all">{{ $row['target_path'] ?? '' }}</td>
          <td class="max-w-[440px] break-words">{{ $row['details'] ?? '' }}</td>
          <td class="whitespace-nowrap">{{ $row['created_at'] ?? '' }}</td>
        </tr>
      @empty
        <tr><td colspan="10" class="text-slate-300">No logs.</td></tr>
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
