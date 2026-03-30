@extends('layouts.app')

@section('content')
  <div class="mb-4">
    <a href="{{ route('domains.index') }}" class="es-btn es-btn-secondary text-sm">&larr; Back to Domains</a>
  </div>

  <div x-data="domainAnalyzer('{{ $domain }}', {{ json_encode($thresholds) }})">
  <div class="es-card es-animate mb-4 p-5 md:p-6">
    <h2 class="es-title mb-3">Protection Tuning for {{ $domain }}</h2>
    <p class="es-subtitle mb-3">Control security instantly<br>No deploy needed</p>
    
    <div class="mb-5 rounded-xl border border-emerald-400/30 bg-emerald-500/15 px-4 py-3 text-sm text-emerald-200 leading-relaxed">
      <strong>💡 Note:</strong><br>
      • Only page visits and API calls count<br>
      • Images, CSS, JS do not count<br>
      <span class="mt-2 block font-semibold text-emerald-100">"Visit = opening a page or calling API"</span>
    </div>

    @if(session('status'))
      <div class="mb-4 rounded-xl border border-emerald-400/30 bg-emerald-500/15 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>
    @endif
    @if(session('error'))
      <div class="mb-4 rounded-xl border border-rose-400/30 bg-rose-500/15 px-4 py-3 text-sm text-rose-200">{{ session('error') }}</div>
    @endif
    @if($errors->any())
      <div class="mb-4 rounded-xl border border-rose-400/30 bg-rose-500/15 px-4 py-3 text-sm text-rose-200">
        <ul class="list-inside list-disc">
          @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <!-- 0. Live Traffic Analyzer -->
    <div class="es-card p-5 mb-6 border border-emerald-500/30 bg-emerald-900/10 rounded-xl relative overflow-hidden">
      <div class="flex flex-col md:flex-row md:items-center justify-between mb-5 border-b border-emerald-500/20 pb-4">
        <div>
          <h3 class="text-lg font-semibold text-emerald-100 flex items-center gap-2">
            <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
            Live Domain Analyzer
          </h3>
          <p class="text-sm text-emerald-200/80 mt-1">Scan your domain to auto-detect how many Pages + APIs fire per visit.</p>
        </div>
        <button type="button" @click="startAnalysis()" class="es-btn px-5 py-2 mt-3 md:mt-0 flex items-center justify-center gap-2 bg-emerald-600 hover:bg-emerald-500 border-emerald-500" :disabled="isAnalyzing">
          <span x-show="!isAnalyzing">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
          </span>
          <span x-show="isAnalyzing" class="animate-spin text-white">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
          </span>
          <span x-text="isAnalyzing ? 'Analyzing...' : 'Analyze Domain'"></span>
        </button>
      </div>

      <!-- Analyzer Results -->
      <div class="grid gap-4 md:grid-cols-2 mb-4">
        <!-- Pages -->
        <div class="bg-gray-800/40 border border-sky-500/20 rounded-lg p-4">
          <label class="mb-1 block text-sm font-medium text-sky-100">Pages Count</label>
          <p class="text-xs text-sky-300/60 mb-2">HTML pages loaded per visit (usually 1)</p>
          <div class="text-3xl font-bold text-white mt-1" x-text="pagesCount"></div>
        </div>
        
        <!-- APIs -->
        <div class="bg-gray-800/40 border border-sky-500/20 rounded-lg p-4 transition-all" :class="{'ring-1 ring-sky-400 shadow-[0_0_10px_rgba(56,189,248,0.2)]': isEditingApi}">
          <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">
            <span>API Count</span>
            <span class="text-[10px] text-sky-400/50 uppercase tracking-widest font-bold">Editable</span>
          </label>
          <p class="text-xs text-sky-300/60 mb-2">Background XHR/fetch calls per page. Edit manually if needed.</p>
          <input 
            type="number" 
            x-model.number="apiCount"
            @focus="isEditingApi = true" 
            @blur="isEditingApi = false"
            min="0"
            class="es-input w-full text-2xl font-bold py-1 px-3 h-auto leading-tight" 
            placeholder="0"
          >
        </div>
      </div>

      <!-- Analysis Progress Overlay -->
      <div x-show="isAnalyzing" style="display: none;" class="absolute inset-0 z-10 bg-gray-900/90 backdrop-blur-md flex flex-col items-center justify-center border-t border-emerald-500/50" x-transition.opacity>
        <div x-show="!iframeLoaded" class="flex flex-col items-center justify-center animate-pulse">
          <svg class="w-12 h-12 text-emerald-400 animate-spin mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
          <p class="text-xl font-bold text-white mb-2 tracking-wide">يتم فحص الموقع الآن…</p>
          <p class="text-sm text-emerald-200">انتظر لحظة، جاري تتبع طلبات الـ API..</p>
          <p class="text-xs text-amber-300/80 mt-2">إذا ظهر لك تحدي كابتشا، قم بحله بالأسفل.</p>
        </div>
        <template x-if="isAnalyzing">
          <iframe :src="iframeUrl" @load="iframeLoaded = true" class="w-full h-full max-h-[100%] rounded-lg border-2 border-emerald-500/30 p-1 bg-white" :class="iframeLoaded ? 'opacity-100' : 'opacity-0 absolute'"></iframe>
        </template>
      </div>

    </div>

    <form method="POST" action="{{ route('domains.update_tuning', ['domain' => $domain]) }}">
      @csrf
      <input type="hidden" name="api_count" :value="apiCount">

      <!-- 1. General & Session -->
      <div class="es-card p-5 mb-6 border border-sky-500/20 bg-sky-900/10 rounded-xl relative overflow-visible">
        <h3 class="mb-4 text-lg font-semibold text-white/90 border-b border-sky-500/20 pb-2">General</h3>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          
          <div>
            <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">
              <span>CAPTCHA</span>
              <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-gray-800 text-gray-400 border border-gray-700">
                <span x-text="Math.max(1, Math.round(netCaptcha * (pagesCount + apiCount)))"></span> Req
              </span>
            </label>
            <p class="text-xs text-sky-300/60 mb-1">After X visits in 3 minutes</p>
            <input type="hidden" name="visit_captcha_threshold" :value="Math.max(1, Math.round(netCaptcha * (pagesCount + apiCount)))">
            <input type="number" x-model.number="netCaptcha" min="1" max="5000" class="es-input w-full" required>
          </div>

          <div>
            <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">
              <span class="flex items-center gap-1">
                Daily Limit
                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/30">Danger</span>
              </span>
              <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-gray-800 text-gray-400 border border-gray-700">
                <span x-text="Math.max(1, Math.round(netDailyLimit * (pagesCount + apiCount)))"></span> Req
              </span>
            </label>
            <p class="text-xs text-sky-300/60 mb-1">Max visits per user per day</p>
            <input type="hidden" name="daily_visit_limit" :value="Math.max(1, Math.round(netDailyLimit * (pagesCount + apiCount)))">
            <input type="number" x-model.number="netDailyLimit" min="1" max="1000000" class="es-input w-full" required>
          </div>

          <div>
            <label class="mb-1 block text-sm font-medium text-sky-100">Session</label>
            <p class="text-xs text-sky-300/60 mb-1">How long user stays trusted (hours)</p>
            <input type="number" name="session_ttl_hours" value="{{ $thresholds['session_ttl_hours'] ?? 1 }}" min="1" max="720" class="es-input w-full" required>
          </div>
        </div>
      </div>

      <!-- 2. Network Limits -->
      <div class="es-card p-5 mb-6 border border-rose-500/20 bg-rose-900/10 rounded-xl relative overflow-visible">
        <h3 class="mb-4 text-lg font-semibold text-rose-100 border-b border-rose-500/20 pb-2">Network Limits</h3>
        <div class="grid gap-4 md:grid-cols-2">
          
          <div>
            <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">
              <span class="flex items-center gap-2">
                IP Ban
                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/30">Critical</span>
              </span>
              <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-gray-800 text-gray-400 border border-gray-700">
                <span x-text="Math.max(1, Math.round(netIpBan * (pagesCount + apiCount)))"></span> Req
              </span>
            </label>
            <p class="text-xs text-rose-300/60 mb-1">Max visits per IP per minute · All devices on same network share this limit</p>
            <input type="hidden" name="ip_hard_ban_rate" :value="Math.max(1, Math.round(netIpBan * (pagesCount + apiCount)))">
            <input type="number" x-model.number="netIpBan" min="1" max="50000" class="es-input w-full" required>
          </div>

          <div>
            <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">
              <span class="flex items-center gap-2">
                ASN Limit
                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">Warning</span>
              </span>
              <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-gray-800 text-gray-400 border border-gray-700">
                <span x-text="Math.max(1, Math.round(netAsnLimit * (pagesCount + apiCount)))"></span> Req
              </span>
            </label>
            <p class="text-xs text-sky-300/60 mb-1">Max visits per ISP per hour</p>
            <input type="hidden" name="asn_hourly_visit_limit" :value="Math.max(1, Math.round(netAsnLimit * (pagesCount + apiCount)))">
            <input type="number" x-model.number="netAsnLimit" min="10" max="1000000" class="es-input w-full" required>
          </div>
        </div>
      </div>

      <!-- 3. Smart & Penalties -->
      <div class="es-card p-5 mb-6 border border-emerald-500/20 bg-emerald-900/10 rounded-xl relative overflow-visible">
        <h3 class="mb-4 text-lg font-semibold text-emerald-100 border-b border-emerald-500/20 pb-2">Penalties</h3>
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3 mb-4">
          <div>
            <label class="mb-1 block text-sm font-medium text-sky-100">Failures</label>
            <p class="text-xs text-sky-300/60 mb-1">Fails before temp ban</p>
            <input type="number" name="max_challenge_failures" value="{{ $thresholds['max_challenge_failures'] ?? 8 }}" min="1" max="50" class="es-input w-full" required>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-sky-100">Ban Time</label>
            <p class="text-xs text-sky-300/60 mb-1">How long ban lasts (hours)</p>
            <input type="number" name="temp_ban_ttl_hours" value="{{ $thresholds['temp_ban_ttl_hours'] ?? 24 }}" min="0.01" max="720" step="0.01" class="es-input w-full" required>
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-sky-100">AI Rules</label>
            <p class="text-xs text-sky-300/60 mb-1">How long auto rules stay (days)</p>
            <input type="number" name="ai_rule_ttl_days" value="{{ $thresholds['ai_rule_ttl_days'] ?? 7 }}" min="0.1" max="365" step="0.1" class="es-input w-full" required>
          </div>
        </div>

        <div class="mt-4 pt-4 border-t border-emerald-500/20">
          <h4 class="mb-3 text-sm font-semibold text-white/80">Smart</h4>
          <label class="flex items-center space-x-3 cursor-pointer group w-max">
            <input type="checkbox" name="ad_traffic_strict_mode" class="es-checkbox" value="1" {{ ($thresholds['ad_traffic_strict_mode'] ?? true) ? 'checked' : '' }}>
            <span class="text-sm font-medium text-sky-100">
              Social Media Traffic Mode
              <span class="block text-xs text-sky-300/60 font-normal mt-0.5">Stricter for social traffic, block hidden attacks</span>
            </span>
          </label>
        </div>
      </div>

      <!-- 4. Advanced -->
      <details class="es-card group mb-6 rounded-xl border border-sky-500/30 bg-gray-800/40 p-5 cursor-pointer relative overflow-visible">
        <summary class="flex list-none items-center justify-between font-medium text-white/90 outline-none">
          <span class="flex items-center gap-2 text-amber-200">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path></svg>
            Advanced Settings (Flood & Escalation)
          </span>
          <span class="transition-transform group-open:rotate-180 text-amber-200">
            <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
          </span>
        </summary>
        
        <div class="mt-6 border-t border-sky-500/20 pt-5 cursor-default">
          <h4 class="mb-1 text-md font-semibold text-white/80">Flood</h4>
          <p class="text-xs text-sky-300/60 mb-4">Detect sudden bursts of traffic from a single IP</p>
          <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4 mb-6">
            <div>
              <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">
                <span>Burst Challenge</span>
                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-gray-800 text-gray-400 border border-gray-700">
                  <span x-text="Math.max(1, Math.round(netBurstChallenge * (pagesCount + apiCount)))"></span> Req
                </span>
              </label>
              <p class="text-xs text-sky-300/60 mb-1">Visits in 15s before challenge</p>
              <input type="hidden" name="flood_burst_challenge" :value="Math.max(1, Math.round(netBurstChallenge * (pagesCount + apiCount)))">
              <input type="number" x-model.number="netBurstChallenge" min="1" max="50000" class="es-input w-full" required>
            </div>
            <div>
              <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">
                <span>Burst Block</span>
                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-gray-800 text-gray-400 border border-gray-700">
                  <span x-text="Math.max(1, Math.round(netBurstBlock * (pagesCount + apiCount)))"></span> Req
                </span>
              </label>
              <p class="text-xs text-sky-300/60 mb-1">Visits in 15s before block</p>
              <input type="hidden" name="flood_burst_block" :value="Math.max(1, Math.round(netBurstBlock * (pagesCount + apiCount)))">
              <input type="number" x-model.number="netBurstBlock" min="1" max="50000" class="es-input w-full" required>
            </div>
            <div>
              <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">
                <span>Sustained Challenge</span>
                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-gray-800 text-gray-400 border border-gray-700">
                  <span x-text="Math.max(1, Math.round(netSustainedChallenge * (pagesCount + apiCount)))"></span> Req
                </span>
              </label>
              <p class="text-xs text-sky-300/60 mb-1">Visits in 60s before challenge</p>
              <input type="hidden" name="flood_sustained_challenge" :value="Math.max(1, Math.round(netSustainedChallenge * (pagesCount + apiCount)))">
              <input type="number" x-model.number="netSustainedChallenge" min="1" max="50000" class="es-input w-full" required>
            </div>
            <div>
              <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">
                <span class="flex items-center gap-1">
                  Sustained Block
                  <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">Warning</span>
                </span>
                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-gray-800 text-gray-400 border border-gray-700">
                  <span x-text="Math.max(1, Math.round(netSustainedBlock * (pagesCount + apiCount)))"></span> Req
                </span>
              </label>
              <p class="text-xs text-sky-300/60 mb-1">Visits in 60s before block</p>
              <input type="hidden" name="flood_sustained_block" :value="Math.max(1, Math.round(netSustainedBlock * (pagesCount + apiCount)))">
              <input type="number" x-model.number="netSustainedBlock" min="1" max="50000" class="es-input w-full" required>
            </div>
          </div>

          <h4 class="mb-1 text-md font-semibold text-white/80 border-t border-sky-500/20 pt-4">Auto Mode</h4>
          <p class="text-xs text-sky-300/60 mb-4">Auto aggressive during an active attack</p>
          <div class="grid gap-4 md:grid-cols-3">
            <div>
              <label class="mb-1 block text-sm font-medium text-sky-100">Window</label>
              <p class="text-xs text-sky-300/60 mb-1">Time to detect attack (minutes)</p>
              <input type="number" name="auto_aggr_pressure_minutes" value="{{ $thresholds['auto_aggr_pressure_minutes'] ?? 3 }}" min="1" max="30" step="0.5" class="es-input w-full" required>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-sky-100">Duration</label>
              <p class="text-xs text-sky-300/60 mb-1">How long aggressive mode runs (minutes)</p>
              <input type="number" name="auto_aggr_active_minutes" value="{{ $thresholds['auto_aggr_active_minutes'] ?? 10 }}" min="1" max="120" step="0.5" class="es-input w-full" required>
            </div>
            <div>
              <label class="mb-1 flex items-center gap-2 text-sm font-medium text-sky-100">
                Subnets
                <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">Warning</span>
              </label>
              <p class="text-xs text-sky-300/60 mb-1">Networks needed to trigger auto mode</p>
              <input type="number" name="auto_aggr_trigger_subnets" value="{{ $thresholds['auto_aggr_trigger_subnets'] ?? 8 }}" min="2" max="50" class="es-input w-full" required>
            </div>
          </div>
          
          <div class="mt-4 p-3 rounded-lg bg-amber-900/20 border border-amber-500/20 text-xs text-amber-300/80">
            💡 Example: 100 devices × 5 visits = IP blocked at limit of 5
          </div>
        </div>
      </details>

      <div class="mt-8 flex items-center justify-end">
        <button class="es-btn" type="submit">Save Threshold Settings</button>
      </div>
    </form>
  </div>
  </div>

  <script>
    document.addEventListener('alpine:init', () => {
      Alpine.data('domainAnalyzer', (domain, savedThresholds = {}) => {

        // initMult = total requests that 1 visit cost when data was last saved
        const savedApi   = Number(savedThresholds.api_count) || 0;
        const initMult   = 1 + savedApi; // pages(1) + apis

        // Convert a raw stored value back to "net pages" the user thinks in
        const toNet = (key, def) => {
          const raw = savedThresholds[key];
          if (raw !== undefined && raw !== null && raw !== '') {
            return Math.max(1, Math.round(Number(raw) / (initMult || 1)));
          }
          return def;
        };

        return {
          // --- Analyzer state (flat, top-level for reactivity) ---
          pagesCount  : 1,
          apiCount    : savedApi,
          isAnalyzing : false,
          iframeUrl   : '',
          iframeLoaded: false,
          isEditingApi: false,
          timeoutId   : null,

          // --- Net (human) values for every threshold ---
          netCaptcha           : toNet('visit_captcha_threshold', 6),
          netDailyLimit        : toNet('daily_visit_limit', 15),
          netIpBan             : toNet('ip_hard_ban_rate', 120),
          netAsnLimit          : toNet('asn_hourly_visit_limit', 200),
          netBurstChallenge    : toNet('flood_burst_challenge', 8),
          netBurstBlock        : toNet('flood_burst_block', 15),
          netSustainedChallenge: toNet('flood_sustained_challenge', 8),
          netSustainedBlock    : toNet('flood_sustained_block', 40),

          init() {
            window.addEventListener('message', (e) => {
              if (!e.origin.includes(domain) && e.origin !== window.location.origin) return;

              if (e.data && e.data.type === 'ES_ANALYZE_RESULT') {
                clearTimeout(this.timeoutId);
                this.pagesCount = 1;
                this.apiCount   = e.data.apiCount || 0;
                setTimeout(() => {
                  this.isAnalyzing = false;
                  this.iframeUrl   = '';
                }, 600);
              }
            });
          },

          startAnalysis() {
            this.isAnalyzing = true;
            this.iframeLoaded = false;
            this.apiCount    = 0;
            this.iframeUrl   = `https://${domain}/?es_analyzer=1&t=${Date.now()}`;

            this.timeoutId = setTimeout(() => {
              if (this.isAnalyzing) {
                this.isAnalyzing = false;
                this.iframeUrl   = '';
                alert('عفواً! انتهى وقت الجلسة. تأكد أن سكريبت الحماية بميزة التحليل متوفرة.');
              }
            }, 60000);
          }
        };
      });
    });
  </script>
@endsection
