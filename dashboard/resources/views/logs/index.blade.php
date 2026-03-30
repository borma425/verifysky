@extends('layouts.app')

@section('content')
  @if(isset($generalStats))
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4 es-animate">
    <div class="es-card p-5 border border-sky-500/20 bg-sky-900/10 rounded-xl relative overflow-hidden group">
      <div class="absolute -right-4 -top-4 w-24 h-24 bg-rose-500/20 rounded-full blur-2xl group-hover:bg-rose-500/30 transition-all"></div>
      <h3 class="text-sm font-medium text-sky-100 flex items-center flex-wrap gap-2 mb-2">
        <span class="flex items-center gap-1.5">
          <svg class="w-4 h-4 text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
          Attacks Blocked This Month
        </span>
        <span class="text-[10px] text-sky-200/50 uppercase tracking-widest pl-1 border-l border-sky-500/20">{{ $domainName ?: 'ALL DOMAINS' }}</span>
      </h3>
      <div class="text-3xl font-bold text-white tracking-tight">{{ number_format($generalStats['total_attacks'] ?? 0) }}</div>
    </div>
    <div class="es-card p-5 border border-sky-500/20 bg-sky-900/10 rounded-xl relative overflow-hidden group">
      <div class="absolute -right-4 -top-4 w-24 h-24 bg-emerald-500/20 rounded-full blur-2xl group-hover:bg-emerald-500/30 transition-all"></div>
      <h3 class="text-sm font-medium text-sky-100 flex items-center flex-wrap gap-2 mb-2">
        <span class="flex items-center gap-1.5">
          <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
          Verified Users This Month
        </span>
        <span class="text-[10px] text-sky-200/50 uppercase tracking-widest pl-1 border-l border-sky-500/20">{{ $domainName ?: 'ALL DOMAINS' }}</span>
      </h3>
      <div class="text-3xl font-bold text-white tracking-tight">{{ number_format($generalStats['total_visitors'] ?? 0) }}</div>
    </div>
    <div class="es-card p-5 border border-sky-500/20 bg-sky-900/10 rounded-xl relative overflow-hidden group">
      <h3 class="text-sm font-medium text-sky-100 flex items-center flex-wrap gap-2 mb-3">
        <span class="flex items-center gap-1.5">
          <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
          Top Attacking Countries (This Month)
        </span>
        <span class="text-[10px] text-sky-200/50 uppercase tracking-widest pl-1 border-l border-sky-500/20">{{ $domainName ?: 'ALL DOMAINS' }}</span>
      </h3>
      <div class="flex flex-col gap-2">
        @forelse($generalStats['top_countries'] ?? [] as $tc)
          @if(($tc['country'] ?? '') !== '')
            <div class="flex items-center justify-between text-sm">
              <div class="flex items-center gap-2">
                <img src="https://flagcdn.com/w20/{{ strtolower($tc['country']) }}.png" srcset="https://flagcdn.com/w40/{{ strtolower($tc['country']) }}.png 2x" alt="{{ $tc['country'] }}" class="w-5 h-auto rounded-sm border border-gray-700/50 object-cover opacity-90 hover:opacity-100 transition-opacity">
                <span class="text-slate-200 font-medium">{{ strtoupper($tc['country']) }}</span>
              </div>
              <span class="text-xs font-bold text-rose-300 bg-rose-500/20 px-1.5 py-0.5 rounded-md border border-rose-500/30">{{ number_format($tc['attack_count'] ?? 0) }}</span>
            </div>
          @endif
        @empty
          <div class="text-xs text-sky-300/50">No data available</div>
        @endforelse
      </div>
    </div>
  </div>
  @endif

  <div class="es-card es-animate mb-4 p-5 md:p-6">
    <div class="flex flex-col md:flex-row md:items-center justify-between mb-4 border-b border-sky-500/20 pb-4">
      <h2 class="es-title m-0">Security Logs</h2>
      <form method="POST" action="{{ route('logs.clear') }}" class="mt-3 md:mt-0 flex items-center justify-end gap-2" onsubmit="return confirm('Are you sure you want to clear these logs? This cannot be undone.');">
        @csrf
        <select name="period" class="es-input text-xs py-1.5 px-2 h-auto w-auto bg-gray-900 border-gray-700 text-gray-300 focus:ring-rose-500">
          <option value="7d">Older than 7 days</option>
          <option value="30d">Older than 30 days</option>
          <option value="all">All Logs (Reset)</option>
        </select>
        <button type="submit" class="es-btn px-3 py-1.5 text-xs bg-rose-600 hover:bg-rose-500 border-rose-600 flex items-center gap-1.5 text-white/90">
          <svg class="w-3.5 h-3.5 opacity-80" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
          Clear
        </button>
      </form>
    </div>
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
            @php
              $labels = [
                'farm_block' => 'IP Farm Blocks (Permanent)',
                'temp_block' => 'Temporary Blocks (Tuning)',
                'challenge_issued' => 'Challenge Issued',
                'challenge_solved' => 'Challenge Solved',
                'challenge_failed' => 'Challenge Failed',
                'turnstile_failed' => 'Turnstile Failed',
                'session_created' => 'Session Created (Passed)',
                'session_rejected' => 'Session Rejected',
              ];
              $displayOpt = $labels[$optionEvent] ?? $optionEvent;
            @endphp
            <option value="{{ $optionEvent }}" @selected(($eventType ?? '') === $optionEvent)>{{ $displayOpt }}</option>
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
      @php
        $formatDetails = function($str) {
            $str = is_array($str) ? implode(', ', $str) : (string) $str;
            $map = [
                'Temporarily banned IP' => 'Blocked IP Automatically',
                'Auto-banned by IP rate policy' => 'Blocked for exceeding requests limit (DDoS)',
                'hard_block' => 'Hard Blocked',
                'Auto-banned by malicious signature' => 'Blocked due to malicious payload',
                'challenge_issued' => 'Challenged User Verification',
            ];
            $str = str_replace(array_keys($map), array_values($map), $str);
            return preg_replace('/\((\d+)s window\)/', '(for $1 seconds)', $str);
        };
      @endphp
      @forelse($logs as $row)
        @php
          $eventTypeValue = trim((string) ($row['worst_event_type'] ?? ''));
          if ($eventTypeValue === '') {
            $eventTypeValue = (string) ($row['event_type'] ?? '');
          }

          $eventDisplayValue = $eventTypeValue;
          if ($eventTypeValue === 'hard_block') {
              if (!empty($row['is_in_ip_farm'])) {
                  $eventDisplayValue = 'hard block';
              } else {
                  $hours = $row['temp_ban_ttl_hours'] ?? 24;
                  $formattedHours = is_numeric($hours) ? rtrim(rtrim(number_format($hours, 2), '0'), '.') : '24';
                  $unit = $formattedHours == '1' ? 'hour' : 'hours';
                  $eventDisplayValue = "Blocked for {$formattedHours} {$unit}";
              }
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
              <span>{{ $eventDisplayValue }}</span>
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
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                      <path d="M16 21v-2a4 4 0 0 0-4-4H5c-2 0-4 1.8-4 4v2"></path>
                      <circle cx="8.5" cy="7" r="4"></circle>
                      <line x1="18" y1="8" x2="23" y2="13"></line>
                      <line x1="23" y1="8" x2="18" y2="13"></line>
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
          <td class="max-w-[440px] break-words">
            @if(is_array($parsed = json_decode($row['details'] ?? '', true)))
              <div class="space-y-1 text-[11.5px] leading-snug">
                @foreach($parsed as $k => $v)
                  @if((is_scalar($v) || is_array($v)) && $v !== '')
                    <div>
                      <span class="font-bold text-sky-200">{{ ucwords(str_replace(['_', '-'], ' ', $k)) }}:</span> 
                      <span class="text-slate-300">{{ $formatDetails($v) }}</span>
                    </div>
                  @endif
                @endforeach
              </div>
            @else
              <span class="text-[11.5px] leading-snug">{{ $formatDetails($row['details'] ?? '') }}</span>
            @endif
          </td>
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
