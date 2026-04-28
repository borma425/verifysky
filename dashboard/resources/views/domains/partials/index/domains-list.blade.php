@if(count($preparedDomainGroups) > 0)
  <div class="es-domain-workspace flex-1">
    <aside class="es-domain-master-list">
      <div class="space-y-3 lg:max-h-[calc(100vh-13rem)] lg:overflow-y-auto lg:pb-8">
          @foreach($preparedDomainGroups as $group)
            @php
              $totalChecks = (int) ($group['total_checks'] ?? 1);
              $dnsActive = (int) ($group['dns_active_count'] ?? 0);
              $sslActive = (int) ($group['ssl_active_count'] ?? 0);
              $overallProgress = (int) ($group['health_score'] ?? 0);
              $isActive = strtolower((string) ($group['status'] ?? 'active')) === 'active';
              $liveStatus = is_array($group['live_status'] ?? null) ? $group['live_status'] : [];
              $rowStatusClass = match (strtolower((string) ($group['overall_status'] ?? 'active'))) {
                'failed', 'error' => 'text-[#F3B5AE]',
                'pending', 'provisioning', 'dns pending', 'ssl pending' => 'text-[#FCB900]',
                default => 'text-[#D7E1F5]',
              };
            @endphp
            <button type="button" class="es-domain-asset-row" x-on:click="selectDomain({{ $loop->index }})" x-bind:class="selectedDomain === {{ $loop->index }} ? 'es-domain-asset-row-active' : ''" data-domain-row="{{ $group['display_domain'] }}">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="flex items-center gap-2">
                    <div class="truncate text-[0.95rem] font-black tracking-[-0.02em] text-[#FFFFFF]">{{ $group['display_domain'] }}</div>
                  </div>
                  <div class="mt-1 truncate font-mono text-[0.75rem] {{ $loop->first ? 'text-[#FCB900]' : 'text-[#B4C0D5]' }}">{{ $group['primary_domain'] }}</div>
                </div>
                <span class="es-pulse-dot {{ $liveStatus['dot_class'] ?? ($isActive ? 'es-pulse-dot-active' : 'es-pulse-dot-muted') }}" data-domain-row-dot></span>
              </div>

              <div class="mt-3.5 flex items-center justify-between gap-3">
                <div class="flex w-[62%] items-center gap-2">
                  <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-[#303540]">
                  <div class="es-progress-fill" style="width: {{ $overallProgress }}%" data-domain-progress></div>
                  </div>
                  <div class="font-mono text-[11px] {{ $rowStatusClass }}" data-domain-row-count>{{ max($dnsActive, $sslActive) }}/{{ $totalChecks }}</div>
                </div>
                <span class="rounded bg-[#303540] px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider {{ $rowStatusClass }}" data-domain-row-status>
                  {{ $liveStatus['label'] ?? strtoupper($group['mode']) }}
                </span>
              </div>
            </button>
          @endforeach
      </div>
    </aside>

    <div class="min-w-0 flex-1">
      @foreach($preparedDomainGroups as $group)
        <div x-show="selectedDomain === {{ $loop->index }}" x-cloak>
          @include('domains.partials.index.domain-card', ['group' => $group, 'groupIndex' => $loop->index])
        </div>
      @endforeach
    </div>
  </div>
@else
  <div class="es-domain-workspace flex-1">
    <div class="w-full flex-1">
      @include('domains.partials.index.empty-state')
    </div>
  </div>
@endif
