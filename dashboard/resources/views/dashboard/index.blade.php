@extends('layouts.app')

@extends('layouts.app')

@section('content')
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4 es-animate">
    <div class="es-card p-5 border border-sky-500/20 bg-sky-900/10 rounded-xl relative overflow-hidden group">
      <div class="absolute -right-4 -top-4 w-24 h-24 bg-rose-500/20 rounded-full blur-2xl group-hover:bg-rose-500/30 transition-all"></div>
      <h3 class="text-sm font-medium text-sky-100 flex items-center gap-2 mb-2">
        <svg class="w-4 h-4 text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
        Attacks Blocked Today
      </h3>
      <div class="text-3xl font-bold text-white tracking-tight">{{ number_format($stats['total_attacks_today'] ?? 0) }}</div>
    </div>
    
    <div class="es-card p-5 border border-sky-500/20 bg-sky-900/10 rounded-xl relative overflow-hidden group">
      <div class="absolute -right-4 -top-4 w-24 h-24 bg-emerald-500/20 rounded-full blur-2xl group-hover:bg-emerald-500/30 transition-all"></div>
      <h3 class="text-sm font-medium text-sky-100 flex items-center gap-2 mb-2">
        <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        Verified Users Today
      </h3>
      <div class="text-3xl font-bold text-white tracking-tight">{{ number_format($stats['total_visitors_today'] ?? 0) }}</div>
    </div>
    
    <div class="es-card p-5 border border-sky-500/20 bg-sky-900/10 rounded-xl relative overflow-hidden group">
      <div class="absolute -right-4 -top-4 w-24 h-24 bg-blue-500/20 rounded-full blur-2xl group-hover:bg-blue-500/30 transition-all"></div>
      <h3 class="text-sm font-medium text-sky-100 flex items-center gap-2 mb-2">
        <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>
        Active Domains
      </h3>
      <div class="text-3xl font-bold text-white tracking-tight">{{ number_format($stats['active_domains'] ?? 0) }}</div>
    </div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4 es-animate es-animate-delay">
    <div class="es-card p-5 border border-sky-500/20 bg-sky-900/10 rounded-xl">
      <h3 class="text-sm font-medium text-sky-100 flex items-center gap-2 mb-4">
        <svg class="w-4 h-4 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        Top Attacking Countries (Today)
      </h3>
      <div class="flex flex-col gap-3">
        @forelse($stats['top_countries'] ?? [] as $tc)
          <div class="flex items-center justify-between text-sm">
            <div class="flex items-center gap-2">
              <img src="https://flagcdn.com/w20/{{ strtolower($tc['country'] ?? '') }}.png" srcset="https://flagcdn.com/w40/{{ strtolower($tc['country'] ?? '') }}.png 2x" alt="{{ $tc['country'] ?? '' }}" class="w-5 h-auto rounded-sm border border-gray-700/50 object-cover opacity-90 hover:opacity-100 transition-opacity">
              <span class="text-slate-200 font-medium">{{ strtoupper($tc['country'] ?? '') }}</span>
            </div>
            <span class="text-xs font-bold text-rose-300 bg-rose-500/20 px-1.5 py-0.5 rounded-md border border-rose-500/30">{{ number_format($tc['attack_count'] ?? 0) }}</span>
          </div>
        @empty
          <div class="text-xs text-sky-300/50">No attacks recorded today.</div>
        @endforelse
      </div>
    </div>

    <div class="es-card p-5 border border-sky-500/20 bg-sky-900/10 rounded-xl">
      <h3 class="text-sm font-medium text-sky-100 flex items-center gap-2 mb-4">
        <svg class="w-4 h-4 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
        Top Targeted Domains (Today)
      </h3>
      <div class="flex flex-col gap-3">
        @forelse($stats['top_domains'] ?? [] as $td)
          <div class="flex items-center justify-between text-sm">
            <span class="text-slate-200 font-medium">{{ preg_replace('/^www\./i', '', $td['domain_name'] ?? '-') }}</span>
            <span class="text-xs font-bold text-rose-300 bg-rose-500/20 px-1.5 py-0.5 rounded-md border border-rose-500/30">{{ number_format($td['attack_count'] ?? 0) }}</span>
          </div>
        @empty
          <div class="text-xs text-sky-300/50">No attacks recorded today.</div>
        @endforelse
      </div>
    </div>
  </div>

  <div class="es-card es-animate es-animate-delay-2 p-5 md:p-6 rounded-xl border border-sky-500/20">
    <div class="flex items-center justify-between mb-4 pb-3 border-b border-sky-500/20">
      <h3 class="text-lg font-bold text-sky-100 flex items-center gap-2">
        <svg class="w-5 h-5 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
        Recent Critical Blocks
      </h3>
      <a href="{{ route('logs.index') }}" class="text-xs font-semibold text-sky-400 hover:text-sky-300 transition-colors bg-sky-500/10 px-2 py-1 rounded-md border border-sky-500/20">View All Logs &rarr;</a>
    </div>
    <div class="overflow-x-auto">
      <table class="es-table min-w-full">
        <thead>
          <tr>
            <th class="text-left text-xs uppercase tracking-wider text-sky-200/60 pb-3 font-semibold px-2">IP Address</th>
            <th class="text-left text-xs uppercase tracking-wider text-sky-200/60 pb-3 font-semibold px-2">Country</th>
            <th class="text-left text-xs uppercase tracking-wider text-sky-200/60 pb-3 font-semibold px-2">Target Domain</th>
            <th class="text-left text-xs uppercase tracking-wider text-sky-200/60 pb-3 font-semibold px-2">Details</th>
            <th class="text-left text-xs uppercase tracking-wider text-sky-200/60 pb-3 font-semibold px-2">Time</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-sky-500/10">
        @forelse($stats['recent_critical'] ?? [] as $row)
          <tr class="hover:bg-slate-800/30 transition-colors">
            <td class="py-3 px-2 whitespace-nowrap">
              <span class="font-mono text-rose-300 bg-rose-500/10 px-1.5 py-0.5 rounded text-sm border border-rose-500/20">{{ $row['ip_address'] ?? '' }}</span>
            </td>
            <td class="py-3 px-2 whitespace-nowrap text-slate-300 text-sm">
              @if(!empty($row['country']) && $row['country'] !== 'T1')
                <div class="flex items-center gap-1.5">
                  <img src="https://flagcdn.com/w20/{{ strtolower($row['country']) }}.png" srcset="https://flagcdn.com/w40/{{ strtolower($row['country']) }}.png 2x" alt="{{ $row['country'] }}" class="w-4 h-auto rounded-[2px] opacity-90">
                  <span>{{ strtoupper($row['country']) }}</span>
                </div>
              @else
                -
              @endif
            </td>
            <td class="py-3 px-2 whitespace-nowrap text-sky-200 text-sm font-medium">{{ preg_replace('/^www\./i', '', $row['domain_name'] ?? '-') }}</td>
            <td class="py-3 px-2 text-slate-400 text-xs max-w-xs truncate" title="{{ $row['details'] ?? '' }}">{{ $row['details'] ?: 'Malicious Activity / Hard Block' }}</td>
            <td class="py-3 px-2 whitespace-nowrap text-sky-200/60 text-xs">
              {{ $row['created_at'] ? \Carbon\Carbon::parse($row['created_at'])->diffForHumans() : 'Unknown' }}
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="py-6 text-center text-sm text-sky-300/50">No critical events recorded. Smooth sailing!</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
@endsection
