@if(session('domain_quarantine'))
  @php
    $quarantine = session('domain_quarantine');
    $assetKey = trim((string) ($quarantine['asset_key'] ?? ''));
    $quarantineEndsAt = trim((string) ($quarantine['quarantined_until'] ?? ''));
  @endphp
  <div class="es-animate rounded-2xl border border-[#FCB900]/30 bg-[#FCB900]/10 p-5 text-sm text-[#FFF3D1] shadow-sm backdrop-blur-md">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
      <div class="flex items-start gap-3">
        <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-[#FCB900]/20 bg-[#171C26]">
          <img src="{{ asset('duotone/triangle-exclamation.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-5 w-5">
        </span>
        <div>
          <div class="text-[11px] font-bold uppercase tracking-[0.18em] text-[#FCB900]">Domain temporarily locked</div>
          <p class="mt-1 font-semibold text-white">
            {{ $assetKey !== '' ? $assetKey : 'This domain' }} was recently removed from VerifySky.
          </p>
          <p class="mt-1 text-xs leading-relaxed text-[#FFF3D1]">
            @if($quarantineEndsAt !== '')
              The quarantine is scheduled to end at <span class="font-mono text-white">{{ $quarantineEndsAt }} UTC</span>.
            @else
              This domain is still inside its quarantine window.
            @endif
            Upgrade to a paid plan to reactivate it now, or contact support.
          </p>
        </div>
      </div>
      <a href="{{ route('billing.index') }}" class="es-btn shrink-0 px-4 py-2 text-sm">Open Billing</a>
    </div>
  </div>
@endif

@if($error && !session('domain_origin_detection_failed') && !session('domain_quarantine'))
  <div class="es-animate rounded-xl border border-[#D47B78]/38 bg-[#D47B78]/12 p-4 text-sm font-medium text-[#FFE6E3] shadow-sm backdrop-blur-md">
    <div class="flex items-center gap-3">
      <img src="{{ asset('duotone/triangle-exclamation.svg') }}" alt="error" class="es-duotone-icon es-icon-tone-coral h-4 w-4">
      {{ $error }}
    </div>
  </div>
@endif

@if(session('warning'))
  <div class="es-animate rounded-xl border border-[#FCB900]/30 bg-[#FCB900]/10 p-4 text-sm font-medium text-[#FFF3D1] shadow-sm backdrop-blur-md">
    <div class="flex items-start gap-3">
      <img src="{{ asset('duotone/triangle-exclamation.svg') }}" alt="warning" class="es-duotone-icon es-icon-tone-brass mt-0.5 h-4 w-4">
      <div class="space-y-1">
        @foreach(preg_split("/\\r\\n|\\r|\\n/", (string) session('warning')) as $line)
          @if(trim($line) !== '')
            <div>{{ $line }}</div>
          @endif
        @endforeach
      </div>
    </div>
  </div>
@endif

@if(session('domain_setup'))
  @php
    $setup = session('domain_setup');
    $setupDomains = is_array($setup['domains'] ?? null) ? $setup['domains'] : [];
    $setupTarget = (string) ($setup['cname_target'] ?? 'customers.verifysky.com');
    $setupOrigin = trim((string) ($setup['origin_server'] ?? ''));
    $setupWarnings = array_values(array_filter((array) ($setup['warnings'] ?? []), fn ($warning): bool => trim((string) $warning) !== ''));
  @endphp
  @if(count($setupDomains) > 0)
    <div class="es-animate rounded-2xl border border-[#FCB900]/26 bg-[#FCB900]/10 p-5 shadow-lg backdrop-blur-md">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-xl">
          <div class="inline-flex items-center rounded-full border border-[#FCB900]/32 bg-[#FCB900]/14 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.2em] text-[#FFFFFF]">
            DNS Setup
          </div>
          <h3 class="mt-3 text-lg font-black tracking-tight text-[#FFFFFF]">Add this DNS record</h3>
          <p class="mt-1.5 text-sm leading-relaxed text-[#D7E1F5]">Copy these DNS values into your DNS provider. Keep this card open until setup finishes.</p>
        </div>
        <div class="rounded-lg border border-white/10 bg-[#202632] px-4 py-3 text-xs text-[#D7E1F5]">
          <div class="font-bold uppercase tracking-widest text-[#959BA7]">DNS Target</div>
          <div class="mt-1 font-mono text-[#FFFFFF]">{{ $setupTarget }}</div>
          @if($setupOrigin !== '')
            <div class="mt-3 font-bold uppercase tracking-widest text-[#959BA7]">Server</div>
            <div class="mt-1 font-mono text-[#FFFFFF]">{{ $setupOrigin }}</div>
          @endif
        </div>
      </div>

      @if($setupWarnings !== [])
        <div class="mt-4 rounded-lg border border-[#FCB900]/24 bg-[#202632] px-4 py-3 text-sm text-[#FFF3D1]">
          @foreach($setupWarnings as $warning)
            <div>{{ $warning }}</div>
          @endforeach
        </div>
      @endif

      <div class="mt-5 grid gap-3">
        @foreach($setupDomains as $domain)
          @php
            $domainName = strtolower(trim((string) $domain));
            $recordName = str_starts_with($domainName, 'www.') ? 'www' : '@';
          @endphp
          <div class="rounded-lg border border-white/8 bg-[#202632] p-4">
            <div class="grid gap-3 md:grid-cols-[0.8fr_0.8fr_1.6fr_auto] md:items-center">
              <div>
                <div class="text-[11px] font-bold uppercase tracking-widest text-[#959BA7]">Type</div>
                <div class="mt-1 font-mono text-sm font-bold text-[#FFFFFF]">CNAME</div>
              </div>
              <div>
                <div class="text-[11px] font-bold uppercase tracking-widest text-[#959BA7]">Name</div>
                <div class="mt-1 font-mono text-sm text-white">{{ $recordName }}</div>
              </div>
              <div>
                <div class="text-[11px] font-bold uppercase tracking-widest text-[#959BA7]">Value</div>
                <div class="mt-1 font-mono text-sm text-[#FFFFFF] break-all">{{ $setupTarget }}</div>
              </div>
              <div class="flex gap-2 md:justify-end">
                <button type="button" x-on:click="copy('CNAME', 'setup-type-{{ $loop->index }}')" class="es-btn es-btn-secondary es-btn-compact">Copy Type</button>
                <button type="button" x-on:click="copy(@js($setupTarget), 'setup-target-{{ $loop->index }}')" class="es-btn es-btn-compact">Copy Value</button>
              </div>
            </div>
            <div class="mt-3 text-xs text-[#959BA7]">Domain: <span class="font-mono text-[#D7E1F5]">{{ $domainName }}</span></div>
          </div>
        @endforeach
      </div>
    </div>
  @endif
@endif
