<div class="es-domain-card">
  @php
    $syncGroupUrl = isset($domainTenant)
      ? route('admin.tenants.domains.sync_group', [$domainTenant, $group['display_domain']])
      : route('domains.sync_group', ['domain' => $group['display_domain']]);
    $tuningUrl = isset($domainTenant)
      ? route('admin.tenants.domains.show', [$domainTenant, $group['primary_domain']])
      : route('domains.tuning', ['domain' => $group['primary_domain']]);
    $destroyGroupUrl = isset($domainTenant)
      ? route('admin.tenants.domains.destroy_group', [$domainTenant, $group['display_domain']])
      : route('domains.destroy_group', ['domain' => $group['display_domain']]);
    $securityModeUrl = isset($domainTenant)
      ? route('admin.tenants.domains.security_mode.update', [$domainTenant, $group['primary_domain']])
      : route('domains.security_mode', ['domain' => $group['primary_domain']]);
    $forceCaptchaUrl = isset($domainTenant)
      ? route('admin.tenants.domains.force_captcha.update', [$domainTenant, $group['primary_domain']])
      : route('domains.force_captcha', ['domain' => $group['primary_domain']]);
    $statusUrl = isset($domainTenant)
      ? route('admin.tenants.domains.status.update', [$domainTenant, $group['primary_domain']])
      : route('domains.status', ['domain' => $group['primary_domain']]);
    $isActive = strtolower((string) ($group['status'] ?? 'active')) === 'active';
    $forceCaptchaEnabled = (int) ($group['force_captcha'] ?? 0) === 1;
    $healthRows = is_array($group['health_rows'] ?? null) ? $group['health_rows'] : [];
    $totalChecks = (int) ($group['total_checks'] ?? max(count($healthRows), 1));
    $dnsActive = (int) ($group['dns_active_count'] ?? 0);
    $sslActive = (int) ($group['ssl_active_count'] ?? 0);
    $overallProgress = (int) ($group['health_score'] ?? 0);
    $liveStatus = is_array($group['live_status'] ?? null) ? $group['live_status'] : [];
    $statusChipClass = (string) ($liveStatus['badge_class'] ?? $group['overall_badge_class'] ?? 'border-white/10 bg-white/5 text-[#D7E1F5]');
    $statusLabel = (string) ($liveStatus['label'] ?? strtoupper((string) $group['overall_status']));
    $statusDescription = (string) ($liveStatus['description'] ?? 'Domain setup is being checked.');
    $isProvisioningLocked = (bool) ($liveStatus['locked'] ?? false);
    $isPolling = (bool) ($liveStatus['polling'] ?? false);
    $modeChipClass = match ($group['mode']) {
      'aggressive' => 'border-[#D47B78]/28 bg-[#D47B78]/12 text-[#FFE6E3]',
      'monitor' => 'border-white/10 bg-white/5 text-[#D7E1F5]',
      default => 'border-white/10 bg-white/5 text-[#FFFFFF]',
    };
    $hostnameState = strtolower((string) ($group['primary_hostname_status'] ?? 'pending'));
    $sslState = strtolower((string) ($group['primary_ssl_status'] ?? 'pending_validation'));
  @endphp

  <div class="es-domain-detail-shell" data-domain-live="{{ $group['display_domain'] }}" data-domain-polling="{{ $isPolling ? '1' : '0' }}" data-domain-locked="{{ $isProvisioningLocked ? '1' : '0' }}">
    <div class="flex flex-col gap-4 border-b border-[#303540]/50 bg-[#1B202A] p-6 md:flex-row md:items-center md:justify-between">
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-3">
          <h3 class="es-domain-title truncate font-bold leading-tight tracking-wide text-[#DEE2F0]">{{ $group['display_domain'] }}</h3>
          <span class="rounded border px-2 py-1 text-[10px] font-bold uppercase tracking-wider {{ $statusChipClass }}" data-domain-status-badge>
            <span class="inline-flex items-center gap-1.5">
              <span class="es-live-spinner {{ $isProvisioningLocked ? '' : 'hidden' }}" data-domain-status-spinner></span>
              <span data-domain-status-label>{{ $statusLabel }}</span>
            </span>
          </span>
          <span class="rounded border px-2 py-1 text-[10px] font-bold uppercase tracking-wider {{ $modeChipClass }}">
            {{ strtoupper($group['mode']) }}
          </span>
        </div>

        <div class="es-managed-route mt-2 flex flex-wrap items-center gap-x-2 gap-y-2 font-mono text-sm text-[#D4C4AB]">
          <img src="{{ asset('duotone/spider-web.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
          <span data-domain-status-description>{{ $statusDescription }}</span>
        </div>
      </div>

      <div class="es-domain-actions flex flex-wrap items-center gap-2.5">
        <form method="POST" action="{{ $syncGroupUrl }}">
          @csrf
          <button class="es-icon-btn h-9 w-9" type="submit" title="Refresh">
            <img src="{{ asset('duotone/arrows-rotate.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
          </button>
        </form>
        <a href="{{ $tuningUrl }}" class="es-icon-btn h-9 w-9 {{ $isProvisioningLocked ? 'pointer-events-none opacity-50' : '' }}" title="{{ $isProvisioningLocked ? 'Available after setup finishes' : 'Settings' }}" data-domain-action-guard>
          <img src="{{ asset('duotone/sliders.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
        </a>
        <div class="hidden h-6 w-px bg-[#303540] md:block"></div>
        <form method="POST" action="{{ $destroyGroupUrl }}" x-on:submit="confirmRemoval($event)">
          @csrf
          @method('DELETE')
          <button class="rounded bg-[#303540] px-4 py-1.5 text-sm font-bold text-[#FFB4AB] transition hover:bg-[#93000A]/20 disabled:cursor-not-allowed disabled:opacity-50" type="submit" data-domain-action-guard @disabled($isProvisioningLocked)>
            Delete
          </button>
        </form>
      </div>
    </div>

    <div class="flex flex-col gap-8 bg-[#171C26] p-6">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div class="es-status-tile">
        <img src="{{ asset('duotone/globe.svg') }}" alt="coverage" class="es-status-tile-icon es-duotone-icon es-icon-tone-muted h-6 w-6">
        <div class="text-xs font-medium text-[#D4C4AB]">Health Score</div>
        <div class="es-status-value font-mono leading-none text-[#D7E1F5]"><span data-domain-health-score>{{ $overallProgress }}</span>%</div>
      </div>
      <div class="es-status-tile">
        <img src="{{ asset('duotone/server.svg') }}" alt="dns status" class="es-status-tile-icon es-duotone-icon es-icon-tone-muted h-6 w-6">
        <div class="text-xs font-medium text-[#D4C4AB]">DNS Status</div>
        <div class="es-status-value font-mono leading-none {{ $hostnameState === 'active' ? 'text-[#10B981]' : 'text-[#D7E1F5]' }}" data-domain-dns-class>
          @if($hostnameState === 'active')
            <div class="flex items-center gap-1.5"><img src="{{ asset('duotone/circle-check.svg') }}" class="es-duotone-icon es-icon-tone-success h-4 w-4"> <span data-domain-dns-label>Active</span></div>
          @else
            <span data-domain-dns-label>Pending</span>
          @endif
        </div>
      </div>
      <div class="es-status-tile">
        <img src="{{ asset('duotone/lock-keyhole.svg') }}" alt="ssl" class="es-status-tile-icon es-duotone-icon es-icon-tone-brass h-6 w-6">
        <div class="text-xs font-medium {{ $sslState === 'active' ? 'text-[#10B981]' : 'text-[#D4C4AB]' }}">SSL Certificate</div>
        <div class="es-status-value font-mono leading-none {{ $sslState === 'active' ? 'text-[#10B981]' : 'text-[#D7E1F5]' }}" data-domain-ssl-class>
          @if($sslState === 'active')
            <div class="flex items-center gap-1.5"><img src="{{ asset('duotone/circle-check.svg') }}" class="es-duotone-icon es-icon-tone-success h-4 w-4"> <span data-domain-ssl-label>Active</span></div>
          @else
            <span data-domain-ssl-label>Pending</span>
          @endif
        </div>
      </div>
      <div class="es-status-tile">
        <img src="{{ asset('duotone/microchip.svg') }}" alt="runtime" class="es-status-tile-icon es-duotone-icon es-icon-tone-muted h-6 w-6">
        <div class="text-xs font-medium text-[#D4C4AB]">Protection</div>
        <div class="es-status-value font-mono leading-none {{ $isActive ? 'text-[#10B981]' : 'text-[#D7E1F5]' }}" data-domain-runtime-class>
          @if($isActive)
            <div class="flex items-center gap-1.5"><img src="{{ asset('duotone/shield-check.svg') }}" class="es-duotone-icon es-icon-tone-success h-4 w-4"> <span data-domain-runtime-label>Enabled</span></div>
          @else
            <span data-domain-runtime-label>Disabled</span>
          @endif
        </div>
      </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
      <section class="es-domain-panel es-detail-panel p-5">
        <h4 class="es-detail-heading">Connection</h4>

        <div class="mt-4 space-y-4">
          <div class="es-summary-row flex items-center justify-between gap-4">
            <div class="flex items-center gap-2 text-sm text-[#D7E1F5]">
              <img src="{{ asset('duotone/shield-check.svg') }}" alt="verification" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
              <span>VerifySky Check</span>
            </div>
            <span class="rounded-md bg-[#0E131D] px-3 py-1.5 font-mono text-sm {{ $group['primary_verified'] ? 'text-[#10B981]' : 'text-[#D7E1F5]' }}" data-domain-edge-class>
              @if($group['primary_verified'])
                <div class="flex items-center gap-1.5"><img src="{{ asset('duotone/circle-check.svg') }}" class="es-duotone-icon es-icon-tone-success h-3.5 w-3.5"> <span data-domain-edge-label>Success</span></div>
              @else
                <span data-domain-edge-label>Pending</span>
              @endif
            </span>
          </div>

          <div class="es-summary-row flex items-center justify-between gap-4">
            <div class="flex items-center gap-2 text-sm text-[#D7E1F5]">
              <img src="{{ asset('duotone/share-nodes.svg') }}" alt="domain" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
              <span>Domain</span>
            </div>
            <span class="rounded-md bg-[#0E131D] px-3 py-1.5 font-mono text-sm text-[#D7E1F5]">{{ $group['display_domain'] }}</span>
          </div>

          <div class="space-y-2">
            <div class="text-sm text-[#D7E1F5]">Protected domain</div>
            <div class="es-hostname-box flex items-center justify-between rounded-md bg-[#0E131D] px-3.5 py-3">
              <span class="es-hostname-value break-all font-mono text-[#FCB900]">{{ $group['primary_domain'] }}</span>
              <button type="button" x-on:click="copy(@js($group['primary_domain']), 'domain-{{ $groupIndex }}')" class="text-[#D4C4AB] hover:text-[#FFFFFF]">
                <img src="{{ asset('duotone/clipboard.svg') }}" alt="copy domain" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
              </button>
            </div>
          </div>
        </div>

        @if(!$group['primary_verified'])
          <div class="mt-5 rounded-lg border border-[#FCB900]/16 bg-[rgba(252,185,0,0.07)] p-4">
            <div class="text-[10px] font-bold uppercase tracking-[0.16em] text-[#FCB900]">Check DNS</div>
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

      <section class="es-domain-panel es-detail-panel p-5">
        <div class="es-control-header flex items-center justify-between gap-3">
          <h4 class="es-detail-heading">Settings</h4>
          <span class="text-xs font-bold text-[#FCB900]">Apply</span>
        </div>

        <form method="POST" action="{{ $securityModeUrl }}" class="mt-4 space-y-3.5">
          @csrf
          <div class="space-y-2">
            <label class="block text-xs font-medium text-[#D7E1F5]">Protection level</label>
            <select name="security_mode" class="es-input es-domain-select h-10 w-full text-xs" data-domain-action-guard @disabled($isProvisioningLocked)>
              <option value="balanced" @selected($group['mode'] === 'balanced')>Balanced</option>
              <option value="monitor" @selected($group['mode'] === 'monitor')>Monitor</option>
              <option value="aggressive" @selected($group['mode'] === 'aggressive')>Aggressive</option>
            </select>
          </div>
          <button class="es-btn w-full" type="submit" data-domain-action-guard @disabled($isProvisioningLocked)>Apply</button>
        </form>

        <div class="mt-5 space-y-4">
          <form method="POST" action="{{ $forceCaptchaUrl }}">
            @csrf
            <input type="hidden" name="force_captcha" value="{{ $forceCaptchaEnabled ? 0 : 1 }}">
            <button class="es-inline-switch disabled:cursor-not-allowed disabled:opacity-50" type="submit" aria-label="Toggle forced captcha" data-domain-action-guard @disabled($isProvisioningLocked)>
              <span class="es-inline-switch-copy">
                <span class="es-inline-switch-title">Force CAPTCHA</span>
                <span class="es-inline-switch-note">Ask every visitor to solve a check</span>
              </span>
              <span class="es-toggle-shell {{ $forceCaptchaEnabled ? 'es-toggle-shell-on' : '' }}"><span class="es-toggle-knob"></span></span>
            </button>
          </form>

          <form method="POST" action="{{ $statusUrl }}">
            @csrf
            <input type="hidden" name="status" value="{{ $isActive ? 'paused' : 'active' }}">
            <button class="es-inline-switch disabled:cursor-not-allowed disabled:opacity-50" type="submit" aria-label="Toggle runtime status" data-domain-action-guard @disabled($isProvisioningLocked)>
              <span class="es-inline-switch-copy">
                <span class="es-inline-switch-title">Protection</span>
                <span class="es-inline-switch-note">Turn security on or off</span>
              </span>
              <span class="es-toggle-shell {{ $isActive ? 'es-toggle-shell-on' : '' }}"><span class="es-toggle-knob"></span></span>
            </button>
          </form>
        </div>
      </section>
    </div>
    </div>
  </div>
</div>
