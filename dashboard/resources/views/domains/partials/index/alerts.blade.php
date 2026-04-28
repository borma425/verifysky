@if($error && !session('domain_origin_detection_failed'))
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
    $setupRecords = is_array($setup['dns_records'] ?? null) ? $setup['dns_records'] : [];
    $setupTarget = (string) ($setup['cname_target'] ?? 'customers.verifysky.com');
    $setupOrigin = trim((string) ($setup['origin_server'] ?? ''));
    $setupCanonical = trim((string) ($setup['canonical_hostname'] ?? ''));
    $redirectInstruction = is_array($setup['redirect_instruction'] ?? null) ? $setup['redirect_instruction'] : null;
    $providerNotes = array_values(array_filter((array) ($setup['provider_notes'] ?? []), fn ($note): bool => trim((string) $note) !== ''));
    $setupWarnings = array_values(array_filter((array) ($setup['warnings'] ?? []), fn ($warning): bool => trim((string) $warning) !== ''));
  @endphp
  @if(count($setupDomains) > 0 || count($setupRecords) > 0)
    <div class="es-animate rounded-2xl border border-[#FCB900]/26 bg-[#FCB900]/10 p-5 shadow-lg backdrop-blur-md">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-xl">
          <div class="inline-flex items-center rounded-full border border-[#FCB900]/32 bg-[#FCB900]/14 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.2em] text-[#FFFFFF]">
            DNS Setup
          </div>
          <h3 class="mt-3 text-lg font-black tracking-tight text-[#FFFFFF]">Add this DNS record at your registrar</h3>
          <p class="mt-1.5 text-sm leading-relaxed text-[#D7E1F5]">This setup card is shown immediately after route creation so operators can copy the exact DNS value and confirm propagation without list clutter.</p>
        </div>
        <div class="rounded-lg border border-white/10 bg-[#202632] px-4 py-3 text-xs text-[#D7E1F5]">
          <div class="font-bold uppercase tracking-widest text-[#959BA7]">DNS Target</div>
          <div class="mt-1 font-mono text-[#FFFFFF]">{{ $setupTarget }}</div>
          @if($setupCanonical !== '')
            <div class="mt-3 font-bold uppercase tracking-widest text-[#959BA7]">Canonical Hostname</div>
            <div class="mt-1 font-mono text-[#FFFFFF]">{{ $setupCanonical }}</div>
          @endif
          @if($setupOrigin !== '')
            <div class="mt-3 font-bold uppercase tracking-widest text-[#959BA7]">Origin</div>
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

      @if($providerNotes !== [])
        <div class="mt-4 rounded-lg border border-white/10 bg-[#202632] px-4 py-3 text-sm text-[#D7E1F5]">
          @foreach($providerNotes as $note)
            <div>{{ $note }}</div>
          @endforeach
        </div>
      @endif

      <div class="mt-5 grid gap-3">
        @foreach($setupRecords !== [] ? $setupRecords : $setupDomains as $record)
          @php
            $recordArray = is_array($record) ? $record : [];
            $domainName = strtolower(trim((string) ($recordArray['hostname'] ?? $record)));
            $recordName = (string) ($recordArray['name'] ?? (str_starts_with($domainName, 'www.') ? 'www' : '@'));
            $recordType = (string) ($recordArray['type'] ?? 'CNAME');
            $recordContent = (string) ($recordArray['content'] ?? $setupTarget);
          @endphp
          <div class="rounded-lg border border-white/8 bg-[#202632] p-4">
            <div class="grid gap-3 md:grid-cols-[0.8fr_0.8fr_1.6fr_auto] md:items-center">
              <div>
                <div class="text-[11px] font-bold uppercase tracking-widest text-[#959BA7]">Type</div>
                <div class="mt-1 font-mono text-sm font-bold text-[#FFFFFF]">{{ $recordType }}</div>
              </div>
              <div>
                <div class="text-[11px] font-bold uppercase tracking-widest text-[#959BA7]">Name</div>
                <div class="mt-1 font-mono text-sm text-white">{{ $recordName }}</div>
              </div>
              <div>
                <div class="text-[11px] font-bold uppercase tracking-widest text-[#959BA7]">Content</div>
                <div class="mt-1 font-mono text-sm text-[#FFFFFF] break-all">{{ $recordContent }}</div>
              </div>
              <div class="flex gap-2 md:justify-end">
                <button type="button" x-on:click="copy(@js($recordType), 'setup-type-{{ $loop->index }}')" class="es-btn es-btn-secondary es-btn-compact">Copy Type</button>
                <button type="button" x-on:click="copy(@js($recordContent), 'setup-target-{{ $loop->index }}')" class="es-btn es-btn-compact">Copy Target</button>
              </div>
            </div>
            <div class="mt-3 text-xs text-[#959BA7]">Hostname: <span class="font-mono text-[#D7E1F5]">{{ $domainName }}</span></div>
          </div>
        @endforeach
      </div>

      @if($redirectInstruction)
        <div class="mt-4 rounded-lg border border-[#FCB900]/24 bg-[#202632] p-4">
          <div class="text-[11px] font-bold uppercase tracking-widest text-[#959BA7]">Root Domain Redirect</div>
          <div class="mt-2 grid gap-3 md:grid-cols-[1fr_1fr_auto] md:items-center">
            <div>
              <div class="text-[11px] font-bold uppercase tracking-widest text-[#959BA7]">From</div>
              <div class="mt-1 font-mono text-sm text-[#FFFFFF]">{{ $redirectInstruction['from'] ?? '' }}</div>
            </div>
            <div>
              <div class="text-[11px] font-bold uppercase tracking-widest text-[#959BA7]">To</div>
              <div class="mt-1 break-all font-mono text-sm text-[#FFFFFF]">{{ $redirectInstruction['to'] ?? '' }}</div>
            </div>
            <button type="button" x-on:click="copy(@js((string) ($redirectInstruction['to'] ?? '')), 'setup-redirect-target')" class="es-btn es-btn-compact">Copy Target</button>
          </div>
          <p class="mt-3 text-xs text-[#D7E1F5]">Use a permanent 301 or 308 redirect. Temporary redirects are shown as warnings.</p>
        </div>
      @endif
    </div>
  @endif
@endif
