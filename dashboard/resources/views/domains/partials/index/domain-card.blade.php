<div class="es-domain-card">
  @php
    $isActive = strtolower((string) ($group['status'] ?? 'active')) === 'active';
    $forceCaptchaEnabled = (int) ($group['force_captcha'] ?? 0) === 1;
    $healthRows = is_array($group['health_rows'] ?? null) ? $group['health_rows'] : [];
    $totalChecks = max(count($healthRows), 1);
    $dnsActive = 0;
    $sslActive = 0;
    foreach ($healthRows as $row) {
      if (strtolower((string) ($row['hostname_status_normalized'] ?? '')) === 'active') {
        $dnsActive++;
      }
      if (strtolower((string) ($row['ssl_status_normalized'] ?? '')) === 'active') {
        $sslActive++;
      }
    }
    $dnsProgress = (int) round(($dnsActive / $totalChecks) * 100);
    $sslProgress = (int) round(($sslActive / $totalChecks) * 100);
    $overallProgress = (int) round(($dnsProgress + $sslProgress) / 2);
    $statusChipClass = $group['primary_verified']
      ? 'border-[#FCB900]/22 bg-[#FCB900]/10 text-[#FFFFFF]'
      : 'border-white/10 bg-white/5 text-[#D7E1F5]';
    $modeChipClass = match ($group['mode']) {
      'aggressive' => 'border-[#D47B78]/28 bg-[#D47B78]/12 text-[#FFE6E3]',
      'monitor' => 'border-white/10 bg-white/5 text-[#D7E1F5]',
      default => 'border-white/10 bg-white/5 text-[#FFFFFF]',
    };
    $hostnameState = strtolower((string) ($group['primary_hostname_status'] ?? 'pending'));
    $sslState = strtolower((string) ($group['primary_ssl_status'] ?? 'pending_validation'));
  @endphp

  <div class="es-domain-detail-shell">
    <div class="flex flex-col gap-3.5 border-b border-white/6 p-3.5 md:p-4 xl:flex-row xl:items-start xl:justify-between">
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-3">
          <img src="https://www.google.com/s2/favicons?domain={{ $group['primary_domain'] }}&sz=64" alt="favicon" class="h-6 w-6 rounded bg-[#0E131D] p-1">
          <h3 class="es-domain-title truncate font-black leading-none tracking-[-0.05em] text-[#FFFFFF]">{{ $group['display_domain'] }}</h3>
          <span class="rounded-md border px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.15em] {{ $statusChipClass }}">
            {{ strtoupper($group['overall_status']) }}
          </span>
          <span class="rounded-md border px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.15em] {{ $modeChipClass }}">
            {{ strtoupper($group['mode']) }}
          </span>
        </div>

        <div class="es-managed-route mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 font-mono text-[#D4C4AB]">
          <img src="{{ asset('duotone/globe.svg') }}" alt="managed route" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
          <span>Managed via VerifySky Edge Network</span>
        </div>
      </div>

      <div class="es-domain-actions flex flex-wrap items-center gap-2.5">
        <form method="POST" action="{{ route('domains.sync_group', ['domain' => $group['display_domain']]) }}">
          @csrf
          <button class="es-icon-btn h-10 w-10" type="submit" title="Refresh">
            <img src="{{ asset('duotone/arrows-rotate.svg') }}" alt="refresh" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
          </button>
        </form>
        <a href="{{ route('domains.tuning', ['domain' => $group['primary_domain']]) }}" class="es-icon-btn h-10 w-10" title="Tuning">
          <img src="{{ asset('duotone/sliders.svg') }}" alt="tuning" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
        </a>
        <div class="hidden h-7 w-px bg-white/8 md:block"></div>
        <form method="POST" action="{{ route('domains.destroy_group', ['domain' => $group['display_domain']]) }}" x-on:submit="confirmRemoval($event)">
          @csrf
          @method('DELETE')
          <button class="es-btn es-btn-soft-danger px-3.5 py-2 text-sm" type="submit">
            Delete
          </button>
        </form>
      </div>
    </div>

    <div class="grid gap-2.5 p-3.5 md:grid-cols-2 md:p-4 xl:grid-cols-4">
      <div class="es-status-tile">
        <img src="{{ asset('duotone/globe.svg') }}" alt="coverage" class="es-status-tile-icon es-duotone-icon es-icon-tone-muted h-6 w-6">
        <div class="text-xs font-medium text-[#D4C4AB]">Health Score</div>
        <div class="es-status-value font-mono leading-none text-[#D7E1F5]">{{ $overallProgress }}%</div>
      </div>
      <div class="es-status-tile">
        <img src="{{ asset('duotone/server.svg') }}" alt="dns status" class="es-status-tile-icon es-duotone-icon es-icon-tone-muted h-6 w-6">
        <div class="text-xs font-medium text-[#D4C4AB]">DNS Record Status</div>
        <div class="es-status-value font-mono leading-none {{ $hostnameState === 'active' ? 'text-[#10B981]' : 'text-[#D7E1F5]' }}">
          @if($hostnameState === 'active')
            <div class="flex items-center gap-1.5"><img src="{{ asset('duotone/circle-check.svg') }}" class="es-duotone-icon es-icon-tone-success h-4 w-4"> Active</div>
          @else
            Pending
          @endif
        </div>
      </div>
      <div class="es-status-tile">
        <img src="{{ asset('duotone/lock-keyhole.svg') }}" alt="ssl" class="es-status-tile-icon es-duotone-icon es-icon-tone-brass h-6 w-6">
        <div class="text-xs font-medium {{ $sslState === 'active' ? 'text-[#10B981]' : 'text-[#D4C4AB]' }}">SSL Certificate</div>
        <div class="es-status-value font-mono leading-none {{ $sslState === 'active' ? 'text-[#10B981]' : 'text-[#D7E1F5]' }}">
          @if($sslState === 'active')
            <div class="flex items-center gap-1.5"><img src="{{ asset('duotone/circle-check.svg') }}" class="es-duotone-icon es-icon-tone-success h-4 w-4"> Active</div>
          @else
            Pending
          @endif
        </div>
      </div>
      <div class="es-status-tile">
        <img src="{{ asset('duotone/microchip.svg') }}" alt="runtime" class="es-status-tile-icon es-duotone-icon es-icon-tone-muted h-6 w-6">
        <div class="text-xs font-medium text-[#D4C4AB]">Runtime System</div>
        <div class="es-status-value font-mono leading-none {{ $isActive && $group['primary_verified'] ? 'text-[#10B981]' : 'text-[#D7E1F5]' }}">
          @if($isActive && $group['primary_verified'])
            <div class="flex items-center gap-1.5"><img src="{{ asset('duotone/shield-check.svg') }}" class="es-duotone-icon es-icon-tone-success h-4 w-4"> Enabled</div>
          @else
            {{ $isActive ? 'Pending...' : 'Disabled' }}
          @endif
        </div>
      </div>
    </div>

    <div class="grid gap-3 p-3.5 pt-0 md:p-4 md:pt-0 xl:grid-cols-2">
      <section class="es-domain-panel es-detail-panel p-3.5 md:p-4">
        <h4 class="es-detail-heading">Connection Summary</h4>

        <div class="mt-4 space-y-4">
          <div class="es-summary-row flex items-center justify-between gap-4">
            <div class="flex items-center gap-2 text-sm text-[#D7E1F5]">
              <img src="{{ asset('duotone/shield-check.svg') }}" alt="verification" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
              <span>Edge Verification</span>
            </div>
            <span class="rounded-md bg-[#0E131D] px-3 py-1.5 font-mono text-sm {{ $group['primary_verified'] ? 'text-[#10B981]' : 'text-[#D7E1F5]' }}">
              @if($group['primary_verified'])
                <div class="flex items-center gap-1.5"><img src="{{ asset('duotone/circle-check.svg') }}" class="es-duotone-icon es-icon-tone-success h-3.5 w-3.5"> Success</div>
              @else
                Pending
              @endif
            </span>
          </div>

          <div class="es-summary-row flex items-center justify-between gap-4">
            <div class="flex items-center gap-2 text-sm text-[#D7E1F5]">
              <img src="{{ asset('duotone/share-nodes.svg') }}" alt="domain" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
              <span>Customer Domain</span>
            </div>
            <span class="rounded-md bg-[#0E131D] px-3 py-1.5 font-mono text-sm text-[#D7E1F5]">{{ $group['display_domain'] }}</span>
          </div>

          <div class="space-y-2">
            <div class="text-sm text-[#D7E1F5]">Target Edge Hostname</div>
            <div class="es-hostname-box flex items-center justify-between rounded-md bg-[#0E131D] px-3.5 py-3">
              <span class="es-hostname-value break-all font-mono text-[#FCB900]">{{ $group['primary_domain'] }}</span>
              <button type="button" x-on:click="copy(@js($group['primary_domain']), 'hostname-{{ $groupIndex }}')" class="text-[#D4C4AB] hover:text-[#FFFFFF]">
                <img src="{{ asset('duotone/clipboard.svg') }}" alt="copy hostname" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
              </button>
            </div>
          </div>
        </div>

        @if(!$group['primary_verified'])
          <div class="mt-5 rounded-lg border border-[#FCB900]/16 bg-[rgba(252,185,0,0.07)] p-4">
            <div class="text-[10px] font-bold uppercase tracking-[0.16em] text-[#FCB900]">DNS Action Required</div>
            <div class="mt-3 space-y-2">
              @foreach($group['dns_rows'] as $row)
                <div class="es-domain-subpanel p-3">
                  <div class="grid gap-2 text-xs sm:grid-cols-[auto_1fr_auto] sm:items-center">
                    <span class="font-mono font-semibold text-[#FFFFFF]">{{ $row['record_type'] }}</span>
                    <span class="break-all font-mono text-[#D7E1F5]">{{ $row['record_name'] }} → {{ $row['target'] }}</span>
                    <button type="button" x-on:click="copy(@js($row['target']), 'target-{{ $groupIndex }}-{{ $loop->index }}')" class="es-btn es-btn-secondary es-btn-compact">Copy</button>
                  </div>
                </div>
              @endforeach
            </div>
          </div>
        @endif
      </section>

      <section class="es-domain-panel es-detail-panel p-3.5 md:p-4">
        <div class="es-control-header flex items-center justify-between gap-3">
          <h4 class="es-detail-heading">Control Settings</h4>
          <span class="text-xs font-bold text-[#FCB900]">Apply Changes</span>
        </div>

        <form method="POST" action="{{ route('domains.security_mode', ['domain' => $group['primary_domain']]) }}" class="mt-4 space-y-3.5">
          @csrf
          <div class="space-y-2">
            <label class="block text-xs font-medium text-[#D7E1F5]">Security Mode</label>
            <select name="security_mode" class="es-input es-domain-select h-10 w-full text-xs">
              <option value="balanced" @selected($group['mode'] === 'balanced')>Balanced</option>
              <option value="monitor" @selected($group['mode'] === 'monitor')>Monitor</option>
              <option value="aggressive" @selected($group['mode'] === 'aggressive')>Aggressive</option>
            </select>
          </div>
          <button class="es-btn w-full" type="submit">Apply Mode</button>
        </form>

        <div class="mt-5 space-y-4">
          <form method="POST" action="{{ route('domains.force_captcha', ['domain' => $group['primary_domain']]) }}">
            @csrf
            <input type="hidden" name="force_captcha" value="{{ $forceCaptchaEnabled ? 0 : 1 }}">
            <button class="es-inline-switch" type="submit" aria-label="Toggle forced captcha">
              <span class="es-inline-switch-copy">
                <span class="es-inline-switch-title">Force CAPTCHA</span>
                <span class="es-inline-switch-note">Challenge all incoming traffic</span>
              </span>
              <span class="es-toggle-shell {{ $forceCaptchaEnabled ? 'es-toggle-shell-on' : '' }}"><span class="es-toggle-knob"></span></span>
            </button>
          </form>

          <form method="POST" action="{{ route('domains.status', ['domain' => $group['primary_domain']]) }}">
            @csrf
            <input type="hidden" name="status" value="{{ $isActive ? 'paused' : 'active' }}">
            <button class="es-inline-switch" type="submit" aria-label="Toggle runtime status">
              <span class="es-inline-switch-copy">
                <span class="es-inline-switch-title">Runtime Protection</span>
                <span class="es-inline-switch-note">WAF and DDoS mitigation</span>
              </span>
              <span class="es-toggle-shell {{ $isActive ? 'es-toggle-shell-on' : '' }}"><span class="es-toggle-knob"></span></span>
            </button>
          </form>
        </div>
      </section>
    </div>
  </div>
</div>
