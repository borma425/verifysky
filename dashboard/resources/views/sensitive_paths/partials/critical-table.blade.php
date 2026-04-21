<div class="es-card es-animate p-0 border border-rose-500/20">
  <div class="flex items-center justify-between border-b border-rose-500/20 bg-rose-500/10 px-4 py-3">
    <div class="flex items-center gap-3">
      <h3 class="text-base font-bold text-rose-100 flex items-center gap-2"><span class="text-xl">Hard Block</span></h3>
      <span class="es-chip bg-slate-800 text-xs text-slate-300">{{ count($criticalPaths) }} Paths</span>
    </div>
    <button type="button" class="es-btn es-btn-danger px-3 py-1.5 text-xs hidden js-bulk-critical" id="bulkCriticalBtn">Unlock Selected</button>
  </div>

  <form id="bulkCriticalForm" method="POST" action="{{ route('sensitive_paths.bulk_destroy') }}">
    @csrf
    @method('DELETE')
    <div class="overflow-x-auto min-h-[300px]">
      <table class="es-table w-full text-sm">
        <thead>
        <tr>
          <th class="w-8 text-center px-2"><input type="checkbox" id="selectAllCritical" class="rounded border-slate-600 bg-slate-800 focus:ring-rose-500"></th>
          <th>Domain & Path</th>
          <th>Type</th>
          <th class="text-right pr-4">Action</th>
        </tr>
        </thead>
        <tbody>
        @forelse($criticalPaths as $path)
          <tr class="align-top hover:bg-white/[0.02]">
            <td class="text-center px-2 py-3"><input type="checkbox" name="path_ids[]" value="{{ $path['id'] }}" class="rule-cb-crit rounded border-slate-600 bg-slate-800 focus:ring-rose-500"></td>
            <td class="py-3">
              <div class="mb-1">
                @if($path['domain_name'] === 'global')
                  <span class="es-chip bg-sky-900/40 text-sky-200 border-sky-500/30 text-[10px] px-1.5 py-0">Global</span>
                @else
                  <span class="es-chip bg-slate-800 text-slate-300 text-[10px] px-1.5 py-0">{{ $path['domain_name'] }}</span>
                @endif
              </div>
              <div class="font-mono text-emerald-300 font-bold break-all">{{ $path['path_pattern'] }}</div>
            </td>
            <td class="text-sky-300 font-mono text-xs py-3">{{ $path['match_type'] }}</td>
            <td class="text-right pr-4 py-3">
              <button type="button" class="text-slate-400 hover:text-rose-400 transition js-single-unlock" data-path-id="{{ $path['id'] }}" title="Unlock Path">
                <svg class="w-5 h-5 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
              </button>
            </td>
          </tr>
        @empty
          <tr><td colspan="4" class="py-12 text-center text-slate-500 border-b-0">No Hard Block paths configured.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </form>
</div>
