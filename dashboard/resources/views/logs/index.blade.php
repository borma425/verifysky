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
      <thead><tr><th>Domain</th><th>Event</th><th>IP</th><th>Attacks</th><th>Action</th><th>ASN</th><th>Country</th><th>Path</th><th>Details</th><th>Time</th></tr></thead>
      <tbody>
      @forelse($logs as $row)
        @php
          $eventTypeValue = trim((string) ($row['worst_event_type'] ?? ''));
          if ($eventTypeValue === '') {
            $eventTypeValue = (string) ($row['event_type'] ?? '');
          }
          $eventScoreDefaults = [
            'challenge_issued' => 35,
            'challenge_solved' => 10,
            'challenge_failed' => 65,
            'hard_block' => 70,
            'session_created' => 5,
            'session_rejected' => 60,
            'turnstile_failed' => 55,
            'replay_detected' => 75,
            'waf_rule_created' => 80,
            'WAF_MERGE_NEW' => 80,
            'WAF_MERGE_UPDATED' => 75,
            'mode_escalated' => 65,
            'ai_defense' => 55,
            'WAF_MERGE_SKIPPED' => 45,
          ];
          $fallbackScore = $eventScoreDefaults[$eventTypeValue] ?? 50;
          $rawScore = $row['worst_event_score'] ?? ($row['max_risk_score'] ?? $row['risk_score'] ?? null);
          $eventScore = is_numeric($rawScore) ? (int) $rawScore : $fallbackScore;
          $eventScore = max(0, min(100, $eventScore));
          $eventScoreClass = $eventScore >= 70
            ? 'border-rose-400/40 bg-rose-500/20 text-rose-100'
            : ($eventScore >= 40
              ? 'border-amber-400/40 bg-amber-500/20 text-amber-100'
              : 'border-emerald-400/40 bg-emerald-500/20 text-emerald-100');
          $isRepeatOffender = (int) ($row['requests_today'] ?? 0) >= 20
            || (int) ($row['requests_yesterday'] ?? 0) >= 40
            || (int) ($row['requests_month'] ?? 0) >= 120;
        @endphp
        <tr>
          <td class="whitespace-nowrap">{{ $row['domain'] ?? '-' }}</td>
          <td class="whitespace-nowrap align-top">
            <div class="flex items-center gap-2">
              <span>{{ $eventTypeValue }}</span>
              <span class="rounded-md border px-1.5 py-0.5 text-[11px] font-semibold leading-none {{ $eventScoreClass }}">{{ $eventScore }}%</span>
            </div>
            @if($isRepeatOffender)
              <div class="mt-1">
                <span class="rounded-md border border-rose-400/40 bg-rose-500/15 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-rose-100">REPEAT OFFENDER</span>
              </div>
            @endif
          </td>
          <td class="whitespace-nowrap">{{ $row['ip_address'] ?? '' }}</td>
          <td class="whitespace-nowrap">
            <div class="flex flex-col items-start gap-1 text-[11px] leading-tight text-cyan-100">
              <span class="rounded-md border border-cyan-300/25 bg-cyan-400/10 px-2 py-0.5 font-semibold">T: {{ $row['requests_today'] ?? 0 }}</span>
              <span class="rounded-md border border-sky-300/20 bg-sky-400/10 px-2 py-0.5 font-semibold">Y: {{ $row['requests_yesterday'] ?? 0 }}</span>
              <span class="rounded-md border border-indigo-300/20 bg-indigo-400/10 px-2 py-0.5 font-semibold">M: {{ $row['requests_month'] ?? 0 }}</span>
            </div>
          </td>
          <td>
            @php($canAllow = !empty($row['ip_address']) && $row['ip_address'] !== 'N/A' && !empty($row['domain']) && ($row['domain'] !== '-'))
            @if($canAllow)
              @if(($row['prefer_block_action'] ?? false) === true)
                <form method="POST" action="{{ route('logs.block_ip') }}">
                  @csrf
                  <input type="hidden" name="ip" value="{{ $row['ip_address'] }}">
                  <input type="hidden" name="domain" value="{{ $row['domain'] }}">
                  <button type="submit" class="es-icon-btn es-icon-btn-danger" title="Block IP for 24h" aria-label="Block IP">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                      <rect x="5" y="11" width="14" height="10" rx="2"></rect>
                      <path d="M8 11V8a4 4 0 0 1 8 0v3"></path>
                      <path d="M12 15v2"></path>
                    </svg>
                  </button>
                </form>
              @else
                <form method="POST" action="{{ route('logs.allow_ip') }}">
                  @csrf
                  <input type="hidden" name="ip" value="{{ $row['ip_address'] }}">
                  <input type="hidden" name="domain" value="{{ $row['domain'] }}">
                  <button type="submit" class="es-icon-btn es-icon-btn-success" title="Allow-list IP and reset ban" aria-label="Allow-list IP and reset ban">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                      <path d="M21 12a9 9 0 1 1-2.64-6.36"></path>
                      <path d="M21 3v6h-6"></path>
                    </svg>
                  </button>
                </form>
              @endif
            @else
              <span class="text-xs es-muted">N/A</span>
            @endif
          </td>
          <td class="whitespace-nowrap">{{ $row['asn'] ?? '' }}</td>
          <td class="whitespace-nowrap">{{ $row['country'] ?? '' }}</td>
          <td class="max-w-[320px]">
            @php($recentPaths = is_array($row['recent_paths'] ?? null) ? $row['recent_paths'] : [])
            @php($topPaths = is_array($row['top_paths'] ?? null) ? $row['top_paths'] : [])
            <div class="space-y-1">
              @forelse($topPaths as $path)
                <div class="max-w-[290px] truncate font-mono text-[11px] text-slate-200">{{ $path }}</div>
              @empty
                <span class="text-xs es-muted">-</span>
              @endforelse
            </div>
            @if(count($recentPaths) > 2)
              <details class="es-path-tooltip mt-1">
                <summary class="es-path-tooltip-trigger" title="Show last 50 paths" aria-label="Show last 50 paths">+</summary>
                <div class="es-path-tooltip-panel">
                  <div class="mb-2 text-[11px] font-semibold text-sky-100">Last {{ count($recentPaths) }} paths</div>
                  <div class="space-y-1">
                    @foreach($recentPaths as $path)
                      <div class="break-all font-mono text-[11px] text-slate-200">{{ $path }}</div>
                    @endforeach
                  </div>
                </div>
              </details>
            @endif
          </td>
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

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const pathTooltips = Array.from(document.querySelectorAll('.es-path-tooltip'));
      if (pathTooltips.length === 0) return;

      document.addEventListener('click', function (event) {
        pathTooltips.forEach(function (tooltip) {
          if (!(tooltip instanceof HTMLDetailsElement)) return;
          if (!tooltip.open) return;
          if (tooltip.contains(event.target)) return;
          tooltip.open = false;
        });
      });

      document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        pathTooltips.forEach(function (tooltip) {
          if (tooltip instanceof HTMLDetailsElement) {
            tooltip.open = false;
          }
        });
      });
    });
  </script>
@endsection
