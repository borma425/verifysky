<div class="es-card es-animate es-animate-delay relative left-1/2 w-[98vw] max-w-none -translate-x-1/2 p-5 md:p-6">
  <div class="mb-4 flex flex-col gap-1 border-b border-sky-500/15 pb-4">
    <h3 class="text-base font-semibold text-white">{{ $isTenantScoped ? 'Recent Security Events' : 'Recent Security Log Events' }}</h3>
    <p class="text-sm text-sky-100/65">
      {{ $isTenantScoped ? 'Only events for the domains assigned to your tenant are shown here.' : 'Grouped by IP and domain to help with investigation and enforcement.' }}
    </p>
  </div>
  <div class="overflow-x-auto">
    <table class="es-table min-w-[{{ $canManageLogActions ? '1400px' : '1280px' }}]">
      <thead>
      <tr>
        <th>Domain</th>
        <th>Event</th>
        <th>IP</th>
        <th>Attacks</th>
        @if($canManageLogActions)
          <th>Action</th>
        @endif
        <th>ASN</th>
        <th>Country</th>
        <th>Path</th>
        <th>Details</th>
        <th>Time</th>
      </tr>
      </thead>
      <tbody>
      @forelse($logs as $row)
        <tr>
          <td class="whitespace-nowrap">{{ $row['domain'] ?? '-' }}</td>
          <td class="whitespace-nowrap align-top">
            <div class="flex items-center gap-2">
              <span>{{ $row['event_display'] ?? '' }}</span>
              <span class="rounded-md border px-1.5 py-0.5 text-[11px] font-semibold leading-none {{ $row['event_score_class'] ?? '' }}">{{ $row['event_score'] ?? 0 }}%</span>
            </div>
            @if(!empty($row['is_repeat_offender']))
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
          @if($canManageLogActions)
            <td>
              @if(!empty($row['can_allow']))
                @if(!empty($row['prefer_block_action']))
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
          @endif
          <td class="whitespace-nowrap">{{ $row['asn'] ?? '' }}</td>
          <td class="whitespace-nowrap">{{ $row['country'] ?? '' }}</td>
          <td class="max-w-[320px]">
            <div class="space-y-1">
              @forelse(($row['top_paths'] ?? []) as $path)
                <div class="max-w-[290px] truncate font-mono text-[11px] text-slate-200">{{ $path }}</div>
              @empty
                <span class="text-xs es-muted">-</span>
              @endforelse
            </div>
            @if(count($row['recent_paths'] ?? []) > 2)
              <details class="es-path-tooltip mt-1">
                <summary class="es-path-tooltip-trigger" title="Show last 50 paths" aria-label="Show last 50 paths">+</summary>
                <div class="es-path-tooltip-panel">
                  <div class="mb-2 text-[11px] font-semibold text-sky-100">Last {{ count($row['recent_paths']) }} paths</div>
                  <div class="space-y-1">
                    @foreach($row['recent_paths'] as $path)
                      <div class="break-all font-mono text-[11px] text-slate-200">{{ $path }}</div>
                    @endforeach
                  </div>
                </div>
              </details>
            @endif
          </td>
          <td class="max-w-[440px] break-words">
            @if(!empty($row['details_items']))
              <div class="space-y-1 text-[11.5px] leading-snug">
                @foreach($row['details_items'] as $detail)
                  <div>
                    <span class="font-bold text-sky-200">{{ $detail['label'] }}:</span>
                    <span class="text-slate-300">{{ $detail['value'] }}</span>
                  </div>
                @endforeach
              </div>
            @else
              <span class="text-[11.5px] leading-snug">{{ $row['details_fallback'] ?? '' }}</span>
            @endif
          </td>
          <td class="whitespace-nowrap">{{ $row['created_at'] ?? '' }}</td>
        </tr>
      @empty
        <tr><td colspan="{{ $canManageLogActions ? 10 : 9 }}" class="text-slate-300">{{ $emptyStateMessage ?? 'No logs.' }}</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>

  @if($logs->hasPages())
    <div class="mt-4">{{ $logs->onEachSide(1)->links() }}</div>
  @endif
</div>
