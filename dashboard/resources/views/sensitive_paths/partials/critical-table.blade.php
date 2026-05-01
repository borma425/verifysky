<div class="flex h-full flex-col rounded-lg border border-white/10 bg-[#171C26]">
  <div class="flex flex-wrap items-center justify-between gap-3 rounded-t-lg border-b border-white/10 bg-[#D47B78]/5 p-4">
    <div class="flex min-w-0 items-center gap-3">
      <h3 class="text-xl font-semibold leading-7 tracking-normal text-white">Hard Block</h3>
      <span class="rounded border border-[#D47B78]/30 bg-[#D47B78]/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-[#FFB4AB]">{{ count($criticalPaths) }} Paths</span>
    </div>
    <button type="button" class="js-bulk-critical hidden rounded border border-white/10 bg-[#0E131D] px-2 py-1 text-xs font-medium text-slate-400 transition-colors hover:text-white" id="bulkCriticalBtn">Unlock Selected</button>
  </div>

  <form id="bulkCriticalForm" method="POST" action="{{ route('sensitive_paths.bulk_destroy') }}">
    @csrf
    @method('DELETE')
    <div class="min-h-[300px] overflow-x-auto">
      <table class="vs-sp-table w-full text-left text-sm text-[#D7E1F5]">
        <thead>
        <tr class="border-b border-white/10 bg-[#0E131D]/50">
          <th class="w-10 p-3"><input type="checkbox" id="selectAllCritical" class="vs-sp-checkbox text-[#D47B78] focus:ring-[#D47B78]"></th>
          <th class="vs-sp-th">Domain & Path</th>
          <th class="vs-sp-th">Type</th>
          <th class="vs-sp-th text-right">Action</th>
        </tr>
        </thead>
        <tbody>
        @forelse($criticalPaths as $path)
          <tr class="vs-sp-data-row vs-sp-data-row-critical group">
            <td class="vs-sp-td vs-sp-td-check" data-label="Select"><input type="checkbox" name="path_ids[]" value="{{ $path['id'] }}" class="rule-cb-crit vs-sp-checkbox text-[#D47B78] focus:ring-[#D47B78]"></td>
            <td class="vs-sp-td" data-label="Domain & Path">
              <div class="vs-sp-path-cell">
                <div class="min-w-0">
                  @if($path['domain_name'] === 'global')
                    <span class="vs-sp-domain-badge uppercase">Global</span>
                  @else
                    <span class="vs-sp-domain-badge">{{ $path['domain_name'] }}</span>
                  @endif
                </div>
                <div class="min-w-0 flex-1">
                  <div class="vs-sp-path-value">{{ $path['path_pattern'] }}</div>
                </div>
              </div>
            </td>
            <td class="vs-sp-td" data-label="Type"><span class="vs-sp-type-badge">{{ $path['match_type'] }}</span></td>
            <td class="vs-sp-td text-right" data-label="Action">
              <button type="button" class="js-single-unlock vs-sp-action-button vs-sp-action-button-critical" data-path-id="{{ $path['id'] }}" title="Unlock Path">
                <img src="{{ asset('duotone/lock-open.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral h-4 w-4">
              </button>
            </td>
          </tr>
        @empty
          <tr><td colspan="4" class="p-12 text-center text-slate-500">No Hard Block paths configured.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </form>
</div>
