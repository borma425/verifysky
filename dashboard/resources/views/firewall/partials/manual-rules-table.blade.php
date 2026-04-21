<div class="es-card es-animate es-animate-delay p-0">
  <div class="flex items-center justify-between border-b border-white/5 bg-slate-800/20 px-4 py-3 md:px-6">
    <div class="flex items-center gap-3">
      <h3 class="text-lg font-bold text-sky-100">Manual Firewall Rules</h3>
      <span class="es-chip bg-slate-800 text-xs text-slate-300">{{ count($manualRules) }} Configured</span>
    </div>
    <button type="button" class="es-btn es-btn-danger px-3 py-1.5 text-xs hidden js-bulk-delete" id="bulkDeleteBtn">Delete Selected</button>
  </div>

  <div class="overflow-x-auto">
    <table class="es-table min-w-[1350px]">
      <thead>
      <tr>
        <th class="w-12 text-center"><input type="checkbox" class="selectAllRules rounded border-slate-600 bg-slate-800 focus:ring-rose-500"></th>
        <th>Domain</th>
        <th>Description</th>
        <th>Action</th>
        <th>Status / Expiry</th>
        <th>Expression</th>
        <th class="text-right">Manage</th>
      </tr>
      </thead>
      <tbody>
      @forelse($manualRules as $rule)
        <tr class="align-top hover:bg-white/[0.02]">
          <td class="text-center"><input type="checkbox" name="rule_ids[]" value="{{ $rule['id'] }}" class="rule-checkbox rounded border-slate-600 bg-slate-800 focus:ring-rose-500"></td>
          <td class="font-semibold text-sky-100">{{ $rule['domain_name'] }}</td>
          <td>
            <div class="font-medium text-slate-100">{{ $rule['description'] }}</div>
            <div class="mt-1 font-mono text-[10px] es-muted text-slate-500">ID: {{ $rule['id'] }}</div>
          </td>
          <td><span class="es-chip border-indigo-400/35 bg-indigo-500/20 text-indigo-100">{{ $rule['action'] }}</span></td>
          <td>
            <div class="mb-1"><span class="es-chip {{ $rule['status_class'] }}">{{ $rule['status_label'] }}</span></div>
            @if(!$rule['is_expired'])
              @if($rule['expires_human'])
                <div class="text-[10px] text-sky-300" title="{{ $rule['expires_utc'] }}">Expires {{ $rule['expires_human'] }}</div>
              @else
                <div class="text-[10px] text-sky-300/70">Forever (No Expiry)</div>
              @endif
            @endif
          </td>
          <td>
            <div class="flex items-center gap-2 font-mono text-xs">
              <span class="text-sky-300">{{ $rule['field'] }}</span>
              <span class="text-fuchsia-300">{{ $rule['operator'] }}</span>
              <span class="text-emerald-300 break-all px-1 bg-black/20 rounded" title="{{ $rule['value'] }}">"{{ $rule['value_display'] }}"</span>
            </div>
          </td>
          <td class="text-right">
            @if(!$rule['is_expired'])
              <div class="flex flex-wrap justify-end gap-2">
                <a href="{{ route('firewall.edit', ['domain' => $rule['domain_name'], 'ruleId' => $rule['id']]) }}" class="es-btn es-btn-secondary px-3 py-1.5 text-xs">Edit</a>
                <button type="button" class="es-btn {{ $rule['is_paused'] ? 'es-btn-success' : 'es-btn-warning' }} px-3 py-1.5 text-xs js-toggle-rule" data-form-id="toggle-form-{{ $rule['id'] }}">
                  {{ $rule['is_paused'] ? 'Enable' : 'Pause' }}
                </button>
              </div>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="py-8 text-center text-slate-400">No manual firewall rules configured.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>

  @if(($totalPages ?? 0) > 1)
    <div class="flex items-center justify-between border-t border-white/5 bg-slate-900/30 px-6 py-4">
      <div class="text-sm text-slate-400">Page {{ $currentPage ?? 1 }} of {{ $totalPages }} (Showing {{ $totalRules }} total rules across all segments)</div>
      <div class="flex gap-2">
        @if(($currentPage ?? 1) > 1)
          <a href="?page={{ $currentPage - 1 }}" class="es-btn es-btn-secondary px-3 py-1 text-xs">Previous</a>
        @endif
        @if(($currentPage ?? 1) < $totalPages)
          <a href="?page={{ $currentPage + 1 }}" class="es-btn es-btn-secondary px-3 py-1 text-xs">Next</a>
        @endif
      </div>
    </div>
  @endif
</div>
