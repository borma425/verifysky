@extends('layouts.app')

@section('content')
  <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
    <div>
      <h2 class="es-title text-2xl flex items-center gap-3">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-500/20 text-rose-400">
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
        </span>
        IP Farm — Permanent Ban Graveyard
      </h2>
      <p class="mt-1 text-sm es-muted">IPs that are permanently blocked across all domains. No TTL, no expiry — the graveyard.</p>
    </div>
  </div>

  @if(!empty($loadErrors))
    <div class="mb-4 rounded-xl border border-amber-400/35 bg-amber-500/15 px-4 py-3 text-sm text-amber-100">
      @foreach($loadErrors as $msg)
        <div>{{ $msg }}</div>
      @endforeach
    </div>
  @endif

  {{-- Stats Bar --}}
  <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
    <div class="es-card es-animate p-4 border-rose-500/20">
      <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-rose-500/15 text-rose-400">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
        </div>
        <div>
          <div class="text-2xl font-extrabold text-rose-100">{{ number_format($totalIps) }}</div>
          <div class="text-xs text-rose-300/70">Permanently Banned IPs</div>
        </div>
      </div>
    </div>
    <div class="es-card es-animate es-animate-delay p-4 border-slate-500/20">
      <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-500/15 text-indigo-400">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
        </div>
        <div>
          <div class="text-2xl font-extrabold text-sky-100">{{ $totalRules }}</div>
          <div class="text-xs text-sky-300/70">Farm Rules</div>
        </div>
      </div>
    </div>
    <div class="es-card es-animate es-animate-delay p-4 border-slate-500/20">
      <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-500/15 text-emerald-400">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div>
          <div class="text-lg font-bold text-emerald-100">
            @if($lastUpdated)
              {{ \Carbon\Carbon::parse($lastUpdated)->diffForHumans() }}
            @else
              Never
            @endif
          </div>
          <div class="text-xs text-emerald-300/70">Last Updated</div>
        </div>
      </div>
    </div>
  </div>

  {{-- Timeline Network --}}
  @if(count($farmRules) === 0)
    <div class="es-card es-animate p-8 text-center">
      <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-800/50">
        <svg class="h-8 w-8 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
      </div>
      <h3 class="text-lg font-bold text-slate-300">No IPs In the Graveyard</h3>
      <p class="mt-2 text-sm text-slate-500">The IP Farm is empty. Malicious IPs will automatically appear here when detected by the Worker engine.</p>
    </div>
  @else
    <div class="relative">
      {{-- Timeline Line --}}
      <div class="absolute left-6 top-0 bottom-0 w-0.5 bg-gradient-to-b from-rose-500/50 via-rose-500/20 to-transparent hidden md:block"></div>

      <div class="space-y-4">
        @foreach($farmRules as $idx => $rule)
          <div class="es-card es-animate p-0 border-rose-500/15 hover:border-rose-500/30 transition-all duration-300 md:ml-12 relative">
            {{-- Timeline Dot --}}
            <div class="absolute -left-[3.25rem] top-6 hidden md:flex h-5 w-5 items-center justify-center">
              <div class="h-3 w-3 rounded-full {{ $rule['paused'] ? 'bg-amber-500' : 'bg-rose-500' }} ring-4 ring-slate-950"></div>
            </div>

            {{-- Header --}}
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-rose-500/10 bg-rose-950/20 px-4 py-3 md:px-5">
              <div class="flex items-center gap-3">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg {{ $rule['paused'] ? 'bg-amber-500/20 text-amber-400' : 'bg-rose-500/20 text-rose-400' }}">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                </div>
                <div>
                  <h3 class="font-bold text-rose-100">{{ str_replace('[IP-FARM] ', '', $rule['description']) }}</h3>
                  <div class="mt-0.5 flex items-center gap-2 text-[10px] es-muted">
                    <span>Rule ID: {{ $rule['id'] }}</span>
                    <span>•</span>
                    <span>Domain: {{ $rule['domain_name'] }}</span>
                  </div>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <span class="es-chip {{ $rule['paused'] ? 'border-amber-400/35 bg-amber-500/20 text-amber-100' : 'border-rose-400/35 bg-rose-500/20 text-rose-100' }} text-xs">
                  {{ $rule['paused'] ? 'Paused' : 'Active' }}
                </span>
                <span class="es-chip bg-slate-800 text-xs text-slate-300 border border-slate-600/30">
                  {{ number_format($rule['ip_count']) }} IPs
                </span>
                <a href="{{ route('firewall.edit', ['domain' => $rule['domain_name'], 'ruleId' => $rule['id']]) }}"
                   class="es-btn es-btn-secondary px-3 py-1.5 text-xs">
                  Edit Rule
                </a>
              </div>
            </div>

            {{-- Body --}}
            <div class="px-4 py-3 md:px-5">
              <div class="flex flex-wrap items-center gap-3 text-xs es-muted mb-3">
                @if($rule['created_at'])
                  <span class="flex items-center gap-1">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    Created: {{ \Carbon\Carbon::parse($rule['created_at'])->format('Y-m-d H:i') }}
                  </span>
                @endif
                @if($rule['updated_at'])
                  <span class="flex items-center gap-1">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Updated: {{ \Carbon\Carbon::parse($rule['updated_at'])->diffForHumans() }}
                  </span>
                @endif
                <span class="flex items-center gap-1">
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                  Permanent • No Expiry
                </span>
              </div>

              {{-- IP List Preview --}}
              @if($rule['ip_count'] > 0)
                <div x-data="{ expanded: false }">
                  <div class="flex flex-wrap gap-1.5" :class="!expanded && '{{ $rule['ip_count'] > 12 ? 'max-h-20 overflow-hidden' : '' }}'">
                    @foreach(array_slice($rule['ips'], 0, $rule['ip_count']) as $ip)
                      <span class="inline-block rounded-md bg-slate-800/60 border border-slate-700/40 px-2 py-0.5 font-mono text-[11px] text-slate-300">{{ $ip }}</span>
                    @endforeach
                  </div>
                  @if($rule['ip_count'] > 12)
                    <button @click="expanded = !expanded"
                            class="mt-2 text-xs text-rose-400 hover:text-rose-300 transition-colors font-medium"
                            x-text="expanded ? '▲ Show Less' : '▼ Show All {{ $rule['ip_count'] }} IPs'">
                    </button>
                  @endif
                </div>
              @endif
            </div>
          </div>
        @endforeach
      </div>
    </div>
  @endif

  {{-- Alpine.js for expand/collapse --}}
  <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
@endsection
