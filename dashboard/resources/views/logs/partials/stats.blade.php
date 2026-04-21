@if(isset($generalStats))
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4 es-animate">
    <div class="es-card p-5 border border-sky-500/20 bg-sky-900/10 rounded-xl relative overflow-hidden group">
      <div class="absolute -right-4 -top-4 w-24 h-24 bg-rose-500/20 rounded-full blur-2xl group-hover:bg-rose-500/30 transition-all"></div>
      <h3 class="text-sm font-medium text-sky-100 flex items-center flex-wrap gap-2 mb-2">
        <span class="flex items-center gap-1.5">
          <svg class="w-4 h-4 text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
          Attacks Blocked This Month
        </span>
        <span class="text-[10px] text-sky-200/50 uppercase tracking-widest pl-1 border-l border-sky-500/20">{{ $scopeLabel ?? (($domainName ?? '') ?: 'ALL DOMAINS') }}</span>
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
        <span class="text-[10px] text-sky-200/50 uppercase tracking-widest pl-1 border-l border-sky-500/20">{{ $scopeLabel ?? (($domainName ?? '') ?: 'ALL DOMAINS') }}</span>
      </h3>
      <div class="text-3xl font-bold text-white tracking-tight">{{ number_format($generalStats['total_visitors'] ?? 0) }}</div>
    </div>
    <div class="es-card p-5 border border-sky-500/20 bg-sky-900/10 rounded-xl relative overflow-hidden group">
      <h3 class="text-sm font-medium text-sky-100 flex items-center flex-wrap gap-2 mb-3">
        <span class="flex items-center gap-1.5">
          <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
          Top Attacking Countries (This Month)
        </span>
        <span class="text-[10px] text-sky-200/50 uppercase tracking-widest pl-1 border-l border-sky-500/20">{{ $scopeLabel ?? (($domainName ?? '') ?: 'ALL DOMAINS') }}</span>
      </h3>
      <div class="flex flex-col gap-2">
        @forelse($generalStats['top_countries'] ?? [] as $country)
          @if(($country['country'] ?? '') !== '')
            <div class="flex items-center justify-between text-sm">
              <div class="flex items-center gap-2">
                <img src="https://flagcdn.com/w20/{{ strtolower($country['country']) }}.png" srcset="https://flagcdn.com/w40/{{ strtolower($country['country']) }}.png 2x" alt="{{ $country['country'] }}" class="w-5 h-auto rounded-sm border border-gray-700/50 object-cover opacity-90 hover:opacity-100 transition-opacity">
                <span class="text-slate-200 font-medium">{{ strtoupper($country['country']) }}</span>
              </div>
              <span class="text-xs font-bold text-rose-300 bg-rose-500/20 px-1.5 py-0.5 rounded-md border border-rose-500/30">{{ number_format($country['attack_count'] ?? 0) }}</span>
            </div>
          @endif
        @empty
          <div class="text-xs text-sky-300/50">No data available</div>
        @endforelse
      </div>
    </div>
  </div>
@endif
