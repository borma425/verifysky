@if(!empty($aiRules))
  <div class="es-card es-animate es-animate-delay p-0 mb-6 border-indigo-500/30 shadow-[0_0_15px_rgba(99,102,241,0.1)]">
    <div class="flex items-center justify-between border-b border-indigo-500/20 bg-indigo-900/20 px-4 py-3 md:px-6">
      <div class="flex items-center gap-3">
        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-500/20 text-indigo-400">
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        </div>
        <div>
          <h3 class="text-lg font-bold text-indigo-100">System AI Interventions</h3>
          <p class="text-xs text-indigo-300">Smart rules automatically generated and merged by the AI Defense Engine.</p>
        </div>
      </div>
      <span class="es-chip bg-indigo-900/40 text-xs text-indigo-300 border border-indigo-500/30">{{ count($aiRules) }} Smart Rules</span>
    </div>

    <div class="overflow-x-auto">
      <table class="es-table min-w-[1350px]">
        <thead>
        <tr class="bg-indigo-950/30">
          <th class="w-12 text-center"><input type="checkbox" class="selectAllRules rounded border-indigo-600 bg-slate-800 focus:ring-indigo-500"></th>
          <th>Domain</th>
          <th>AI Diagnosis & Description</th>
          <th>Action</th>
          <th>Status / Expiry</th>
          <th>Expression (Merged Targets)</th>
          <th class="text-right">Manage</th>
        </tr>
        </thead>
        <tbody>
        @foreach($aiRules as $rule)
          <tr class="align-top hover:bg-indigo-500/[0.02]">
            <td class="text-center"><input type="checkbox" name="rule_ids[]" value="{{ $rule['id'] }}" class="rule-checkbox rounded border-slate-600 bg-slate-800 focus:ring-rose-500"></td>
            <td class="font-semibold text-sky-100">{{ $rule['domain_name'] }}</td>
            <td>
              <div class="font-medium text-indigo-100">{{ $rule['description_display'] }}</div>
              <div class="mt-1 font-mono text-[10px] es-muted text-slate-500">Rule ID: {{ $rule['id'] }}</div>
            </td>
            <td><span class="es-chip border-rose-400/35 bg-rose-500/20 text-rose-100">{{ $rule['action'] }}</span></td>
            <td>
              <div class="mb-1"><span class="es-chip {{ $rule['status_class'] }}">{{ $rule['status_label'] }}</span></div>
              @if(!$rule['is_expired'])
                @if($rule['expires_human'])
                  <div class="text-[10px] text-indigo-300" title="{{ $rule['expires_utc'] }}">Expires {{ $rule['expires_human'] }}</div>
                @else
                  <div class="text-[10px] text-indigo-300/70">Forever (No Expiry)</div>
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
                <button type="button" class="es-btn {{ $rule['is_paused'] ? 'es-btn-success' : 'es-btn-warning' }} px-3 py-1.5 text-xs js-toggle-rule" data-form-id="toggle-form-{{ $rule['id'] }}">
                  {{ $rule['is_paused'] ? 'Enable' : 'Pause' }}
                </button>
              @endif
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </div>
@endif
