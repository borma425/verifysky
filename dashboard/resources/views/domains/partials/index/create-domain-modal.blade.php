<div x-show="showWizard" style="display: none;"
     x-transition:enter="transition ease-out duration-250"
     x-transition:enter-start="opacity-0 translate-y-3 sm:scale-95"
     x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
     x-transition:leave="transition ease-in duration-180"
     x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
     x-transition:leave-end="opacity-0 translate-y-3 sm:scale-95"
     class="relative z-50" aria-labelledby="modal-title" role="dialog" aria-modal="true">
  <div class="fixed inset-0 bg-[#171C26]/84 backdrop-blur-sm"></div>
  <div class="fixed inset-0 z-10 overflow-y-auto">
    <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-6">
      <div x-on:click.away="showWizard = false" class="relative w-full max-w-4xl overflow-hidden rounded-lg border border-white/10 bg-[#202632] text-left shadow-[0_24px_64px_rgba(10,13,18,0.42)]">
        <div class="grid lg:grid-cols-[1.52fr_0.82fr]">
          <div>
            <div class="flex items-center justify-between border-b border-white/8 bg-gradient-to-r from-[#202633] to-[#1B202A] px-5 py-4 sm:px-6 sm:py-5">
              <div>
                <div class="text-[10px] font-bold uppercase tracking-[0.2em] text-[#FCB900]">VerifySky Onboarding</div>
                <h3 class="mt-1 text-lg font-extrabold tracking-tight text-[#FFFFFF]" id="modal-title">Add New Domain</h3>
              </div>
              <button x-on:click="showWizard = false" class="rounded-lg p-1.5 text-[#959BA7] transition hover:bg-white/8 hover:text-[#FFFFFF]">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
              </button>
            </div>

            <form method="POST"
                  action="{{ route('domains.store') }}"
                  class="px-5 py-5 sm:px-6 sm:py-6"
                  x-data="domainCreateForm({
                    domainName: @js((string) old('domain_name', '')),
                    dnsTarget: @js((string) $cnameTarget),
                    manualOrigin: @js((string) old('origin_server', '')),
                    forceManualOrigin: @js((bool) session('domain_origin_detection_failed'))
                  })"
                  x-on:submit="submit()">
              @csrf

              @if(! $can_add_domain)
                <div class="mb-6 rounded-xl border border-[#FCB900]/24 bg-[#FCB900]/10 p-4 text-sm text-[#FFFFFF]">
                  <div class="text-[10px] font-bold uppercase tracking-[0.18em] text-[#FCB900]">Plan Limit Reached</div>
                  <p class="mt-2 leading-relaxed">Upgrade your plan to add more domains.</p>
                </div>
              @endif

              <div class="mb-6 flex items-center justify-between gap-3 overflow-hidden">
                <div class="es-step-chip" :class="step >= 1 ? 'es-step-chip-active' : ''">
                  <span class="es-step-badge" :class="step >= 1 ? 'es-step-badge-active' : ''">1</span>
                  <span class="es-step-label" :class="step >= 1 ? 'text-[#FFFFFF]' : 'text-[#959BA7]'">Domain</span>
                </div>
                <div class="es-step-connector"></div>
                <div class="es-step-chip" :class="step >= 2 ? 'es-step-chip-active' : ''">
                  <span class="es-step-badge" :class="step >= 2 ? 'es-step-badge-active' : ''">2</span>
                  <span class="es-step-label" :class="step >= 2 ? 'text-[#FFFFFF]' : 'text-[#959BA7]'">DNS</span>
                </div>
                <div class="es-step-connector"></div>
                <div class="es-step-chip" :class="step >= 3 ? 'es-step-chip-active' : ''">
                  <span class="es-step-badge" :class="step >= 3 ? 'es-step-badge-active' : ''">3</span>
                  <span class="es-step-label" :class="step >= 3 ? 'text-[#FFFFFF]' : 'text-[#959BA7]'">Verify</span>
                </div>
              </div>

              <div x-show="step === 1" class="space-y-5">
                @if(session('domain_origin_detection_failed'))
                  <div class="es-wizard-tulip">
                    <div class="flex items-start gap-3">
                      <span class="mt-0.5 inline-flex h-8 w-8 items-center justify-center rounded-full bg-[#171C26]">
                        <img src="{{ asset('duotone/triangle-exclamation.svg') }}" alt="attention" class="es-duotone-icon es-icon-tone-brass h-4 w-4">
                      </span>
                      <div>
                        <div class="text-[10px] font-bold uppercase tracking-[0.18em] text-[#171C26]">Backend Detection Needs Help</div>
                        <p class="mt-2 text-sm leading-relaxed text-[#FFFFFF]">VerifySky could not find the real server automatically because this domain is already routed through another edge or proxy. Enter the <strong>server IP</strong> directly so setup can continue.</p>
                      </div>
                    </div>
                  </div>
                @endif

                <div>
                  <label class="mb-2 block text-sm font-bold text-[#FFFFFF]">Enter your domain</label>
                  <input class="es-input h-10 w-full text-sm font-mono"
                         name="domain_name"
                         x-model="domainName"
                         value="{{ old('domain_name') }}"
                         placeholder="example.com"
                         required
                         @disabled(! $can_add_domain)>
                  <p class="mt-2 text-[11px] leading-snug text-[#D7E1F5]">Enter the customer domain you want VerifySky to protect. If you enter an apex domain, VerifySky will prepare the `www` route first to keep onboarding predictable and safe.</p>
                </div>

                <div class="space-y-3 rounded-lg border border-white/10 bg-[#171C26] p-4">
                  <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                      <label class="block text-sm font-bold text-[#FFFFFF]">How should VerifySky find your server?</label>
                      <p class="mt-1 text-[11px] leading-snug text-[#D7E1F5]">Automatic mode tries to detect the real server from DNS. If that server is hidden behind another proxy, enter the server IP manually.</p>
                    </div>
                    <button type="button" x-on:click="useAutomaticOrigin = !useAutomaticOrigin" class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60" x-bind:class="useAutomaticOrigin ? 'border-[#FCB900]/34 bg-[#FCB900]/12 text-[#FFFFFF]' : 'border-[#FCB900]/34 bg-[#FCB900]/12 text-[#FFFFFF]'" @disabled(! $can_add_domain)>
                      <span x-text="useAutomaticOrigin ? 'Detect Automatically' : 'Enter Server IP'"></span>
                    </button>
                  </div>

                  <div x-show="useAutomaticOrigin" x-transition.opacity class="rounded-lg border border-[#FCB900]/24 bg-[#FCB900]/12 px-4 py-3 text-sm text-[#FFFFFF]">
                    VerifySky will inspect DNS and try to detect the real server automatically after you continue.
                  </div>

                  <div x-show="!useAutomaticOrigin" x-transition.opacity class="space-y-2" style="display: none;" x-on:verifysky-focus-server-ip.window="step = 1; useAutomaticOrigin = false; showWizard = true; $nextTick(() => $refs.serverIpInput && $refs.serverIpInput.focus())">
                    <label class="mb-2 block text-sm font-bold text-[#FFFFFF]">Server IP</label>
                    <input x-ref="serverIpInput" class="es-input h-10 w-full text-sm font-mono" name="origin_server" x-model="manualOrigin" x-bind:disabled="useAutomaticOrigin" x-bind:required="!useAutomaticOrigin" placeholder="198.51.100.23" @disabled(! $can_add_domain)>
                    <p class="text-[11px] text-[#D7E1F5]"><strong class="text-[#FCB900]">SSL note:</strong> this server must accept HTTPS on port 443 with a valid TLS certificate.</p>
                    @if(session('domain_origin_detection_failed'))
                      <p class="rounded-lg border border-[#FCB900]/22 bg-[#FCB900]/10 px-3 py-2 text-[11px] text-[#FFFFFF]">Server IP is required for this domain before verification can begin.</p>
                    @endif
                  </div>
                </div>

                <div>
                  <label class="mb-2 block text-sm font-bold text-[#FFFFFF]">Initial Security Policy</label>
                  @if($isAdmin)
                    <select class="es-input text-sm" name="security_mode" @disabled(! $can_add_domain)>
                      <option value="balanced" selected>Balanced (Recommended)</option>
                      <option value="monitor">Monitor Only</option>
                      <option value="aggressive">Aggressive</option>
                    </select>
                  @else
                    <input type="hidden" name="security_mode" value="balanced">
                    <div class="rounded-lg border border-[#FCB900]/24 bg-[#FCB900]/12 px-4 py-2.5 text-sm font-medium text-[#FFFFFF]">Balanced is applied automatically for the operator role.</div>
                  @endif
                </div>
              </div>

              <div x-show="step === 2" x-cloak class="space-y-5">
                <div>
                  <div class="text-[10px] font-bold uppercase tracking-[0.18em] text-[#FCB900]">DNS Setup</div>
                  <h4 class="mt-2 text-lg font-bold text-[#FFFFFF]">Add this DNS record first</h4>
                  <p class="mt-2 text-sm leading-relaxed text-[#D7E1F5]">Create the record below at your registrar or DNS provider. Once saved, VerifySky can start monitoring propagation and verification for the protected route.</p>
                </div>

                <div class="grid gap-3 rounded-lg border border-white/10 bg-[#171C26] p-4 md:grid-cols-[0.8fr_0.95fr_1.65fr]">
                  <div class="es-domain-subpanel px-4 py-3.5">
                    <div class="text-[10px] font-bold uppercase tracking-[0.16em] text-[#959BA7]">Type</div>
                    <div class="mt-2 font-mono text-sm font-semibold text-[#FFFFFF]">CNAME</div>
                  </div>
                  <div class="es-domain-subpanel px-4 py-3.5">
                    <div class="flex items-center justify-between gap-3">
                      <div class="text-[10px] font-bold uppercase tracking-[0.16em] text-[#959BA7]">Name</div>
                      <button type="button" x-on:click="copy(dnsRecordName(), 'wizard-dns-name')" class="es-copy-btn">
                        <img src="{{ asset('duotone/clipboard.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-3.5 w-3.5">
                        <span x-show="copied !== 'wizard-dns-name'">Copy</span>
                        <span x-show="copied === 'wizard-dns-name'">Copied</span>
                      </button>
                    </div>
                    <div class="mt-2 font-mono text-sm font-semibold text-[#FFFFFF]" x-text="dnsRecordName() || '--'"></div>
                  </div>
                  <div class="es-domain-subpanel px-4 py-3.5">
                    <div class="flex items-center justify-between gap-3">
                      <div class="text-[10px] font-bold uppercase tracking-[0.16em] text-[#959BA7]">Value</div>
                      <button type="button" x-on:click="copy(dnsTarget, 'wizard-dns-target')" class="es-copy-btn">
                        <img src="{{ asset('duotone/clipboard.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-3.5 w-3.5">
                        <span x-show="copied !== 'wizard-dns-target'">Copy</span>
                        <span x-show="copied === 'wizard-dns-target'">Copied</span>
                      </button>
                    </div>
                    <div class="es-copy-value mt-2 font-mono text-sm font-semibold text-[#FFFFFF]" x-text="dnsTarget || '--'"></div>
                  </div>
                </div>

                <div class="rounded-lg border border-white/10 bg-[#171C26] p-4">
                  <div class="text-[10px] font-bold uppercase tracking-[0.16em] text-[#959BA7]">Protected Route</div>
                  <div class="mt-2 font-mono text-sm text-[#FFFFFF]" x-text="protectedHostname() || '--'"></div>
                  <p class="mt-3 text-[12px] leading-relaxed text-[#D7E1F5]">If you entered an apex domain, VerifySky starts with the `www` route first. This keeps onboarding straightforward, avoids ambiguous DNS behavior, and gives operators a clean verification path.</p>
                </div>
              </div>

              <div x-show="step === 3" x-cloak class="space-y-5">
                <div>
                  <div class="text-[10px] font-bold uppercase tracking-[0.18em] text-[#FCB900]">Verification</div>
                  <h4 class="mt-2 text-lg font-bold text-[#FFFFFF]">Start monitoring and verification</h4>
                  <p class="mt-2 text-sm leading-relaxed text-[#D7E1F5]">After you continue, VerifySky will create the protected route, watch DNS propagation, and begin certificate verification for the hostname below.</p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                  <div class="es-domain-subpanel px-4 py-3.5">
                    <div class="text-[10px] font-bold uppercase tracking-[0.16em] text-[#959BA7]">Entered Domain</div>
                    <div class="mt-2 font-mono text-sm font-semibold text-[#FFFFFF]" x-text="normalizeDomain(domainName) || '--'"></div>
                  </div>
                  <div class="es-domain-subpanel px-4 py-3.5">
                    <div class="text-[10px] font-bold uppercase tracking-[0.16em] text-[#959BA7]">Protected Route</div>
                    <div class="mt-2 font-mono text-sm font-semibold text-[#FFFFFF]" x-text="protectedHostname() || '--'"></div>
                  </div>
                </div>

                <div class="rounded-lg border border-[#FCB900]/18 bg-[#FCB900]/8 p-4 text-sm text-[#FFFFFF]">
                  Continue only after the DNS record is saved. Verification may remain pending until propagation completes.
                </div>
              </div>

              <div class="mt-6 flex flex-col-reverse gap-3 border-t border-white/8 pt-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex w-full gap-3 sm:w-auto">
                  <button type="button" x-on:click="showWizard = false" class="es-btn es-btn-secondary es-modal-action-btn w-full px-6 sm:w-auto">
                    <img src="{{ asset('duotone/panel-ews.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
                    Cancel
                  </button>
                  <button type="button" x-show="step > 1" x-cloak x-on:click="back(step - 1)" class="es-btn es-btn-secondary es-modal-action-btn w-full px-6 sm:w-auto">
                    <img src="{{ asset('duotone/arrows-rotate.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-4 w-4">
                    Back
                  </button>
                </div>

                <div class="flex w-full gap-3 sm:w-auto">
                  <button type="button" x-show="step === 1" x-on:click="nextFromDetails()" class="es-btn es-modal-action-btn w-full px-8 sm:w-auto" x-bind:disabled="@js(! $can_add_domain) || !domainName.trim() || (!useAutomaticOrigin && !manualOrigin.trim())">
                    <img src="{{ asset('duotone/share-nodes.svg') }}" alt="" class="es-duotone-icon h-4 w-4" style="filter: brightness(0);">
                    Continue
                  </button>
                  <button type="button" x-show="step === 2" x-cloak x-on:click="confirmDnsAdded()" class="es-btn es-modal-action-btn w-full px-8 sm:w-auto" @disabled(! $can_add_domain)>
                    <img src="{{ asset('duotone/circle-check.svg') }}" alt="" class="es-duotone-icon h-4 w-4" style="filter: brightness(0);">
                    Done
                  </button>
                  <button type="submit" x-show="step === 3" x-cloak class="es-btn es-modal-action-btn w-full px-8 sm:w-auto" x-bind:disabled="@js(! $can_add_domain) || validating" x-bind:class="{ 'cursor-wait opacity-75': validating }">
                    <span x-show="!validating" class="inline-flex items-center gap-2">
                      <img src="{{ asset('duotone/circle-check.svg') }}" alt="" class="es-duotone-icon h-4 w-4" style="filter: brightness(0);">
                      <span>Start Verification</span>
                    </span>
                    <span x-show="validating" class="inline-flex items-center gap-2">
                      <img src="{{ asset('duotone/arrows-rotate.svg') }}" alt="" class="es-duotone-icon h-4 w-4" style="filter: brightness(0);">
                      <span>Starting...</span>
                    </span>
                  </button>
                </div>
              </div>
            </form>
          </div>

          <aside class="border-t border-white/8 bg-[#1B202A] p-5 sm:p-6 lg:border-l lg:border-t-0">
            <h4 class="inline-flex rounded-md border border-[#FCB900]/32 bg-[#FCB900]/10 px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.2em] text-[#FFFFFF]">Onboarding Flow</h4>
            <ul class="mt-5 space-y-4 text-sm text-[#D7E1F5]">
              <li class="border-l border-[#FCB900]/36 pl-3">Define the customer hostname and choose how VerifySky should find the real server.</li>
              <li class="border-l border-[#FCB900]/36 pl-3">Add the DNS record shown in the next step before starting verification.</li>
              <li class="border-l border-[#FCB900]/36 pl-3">VerifySky then monitors DNS propagation, ownership checks, and certificate readiness.</li>
              <li class="border-l border-white/20 pl-3">The setup card remains available on the page immediately after the route is created.</li>
            </ul>
            <div class="mt-6 rounded-lg border border-white/5 bg-[#202632]/50 p-4 text-xs text-[#D7E1F5] shadow-inner">
              Keep the entered hostname exact. The DNS record name is generated from that value and should be copied as shown.
            </div>
          </aside>
        </div>
      </div>
    </div>
  </div>
</div>
