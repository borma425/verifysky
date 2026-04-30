<div class="vs-fw-table-card es-animate es-animate-delay">
  <div class="vs-fw-table-head">
    <div class="vs-fw-table-title-row">
      <h3>Manual Firewall Rules</h3>
      <span class="vs-fw-chip">{{ count($manualRules) }} Configured</span>
    </div>
    <button type="button" class="vs-fw-button vs-fw-button-danger hidden js-bulk-delete" id="bulkDeleteBtn">Delete Selected</button>
  </div>

  <div class="vs-fw-table-scroll">
    <table class="vs-fw-table">
      <thead>
      <tr>
        <th class="w-12 text-center"><input type="checkbox" class="selectAllRules vs-fw-check"></th>
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
        <tr>
          <td class="text-center"><input type="checkbox" name="rule_ids[]" value="{{ $rule['id'] }}" class="rule-checkbox vs-fw-check"></td>
          <td><strong>{{ $rule['domain_name'] }}</strong></td>
          <td>
            <div class="font-medium text-[#DEE2F0]">{{ $rule['description'] }}</div>
            <div class="mt-1 font-mono text-[10px] text-[#9FAABC]">ID: {{ $rule['id'] }}</div>
          </td>
          <td><span class="vs-fw-chip {{ $rule['action'] === 'block' || str_contains($rule['action'], 'block') ? 'vs-fw-chip-danger' : 'vs-fw-chip-gold' }}">{{ $rule['action'] }}</span></td>
          <td>
            <div class="mb-1"><span class="vs-fw-chip {{ $rule['is_expired'] ? 'vs-fw-chip-danger' : ($rule['is_paused'] ? 'vs-fw-chip-paused' : 'vs-fw-chip-success') }}">{{ $rule['status_label'] }}</span></div>
            @if(!$rule['is_expired'])
              @if($rule['expires_human'])
                <div class="text-[10px] text-[#D4C4AB]" title="{{ $rule['expires_utc'] }}">Expires {{ $rule['expires_human'] }}</div>
              @else
                <div class="text-[10px] text-[#9FAABC]">Forever (No Expiry)</div>
              @endif
            @endif
          </td>
          <td>
            <div class="vs-fw-expression">
              <span>{{ $rule['field'] }}</span>
              <span>{{ $rule['operator'] }}</span>
              <span title="{{ $rule['value'] }}">"{{ $rule['value_display'] }}"</span>
            </div>
          </td>
          <td class="text-right">
            @if(!$rule['is_expired'])
              <div class="vs-fw-action-row">
                <a href="{{ route('firewall.edit', ['domain' => $rule['domain_name'], 'ruleId' => $rule['id']]) }}" class="vs-fw-button vs-fw-button-secondary">Edit</a>
                <button type="button" class="vs-fw-button vs-fw-button-secondary js-toggle-rule" data-form-id="toggle-form-{{ $rule['id'] }}">
                  {{ $rule['is_paused'] ? 'Enable' : 'Pause' }}
                </button>
              </div>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="vs-fw-empty">No manual firewall rules configured.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>

  @if(($totalPages ?? 0) > 1)
    <div class="vs-fw-pagination">
      <div>Page {{ $currentPage ?? 1 }} of {{ $totalPages }} (Showing {{ $totalRules }} total rules across all segments)</div>
      <div class="vs-fw-action-row">
        @if(($currentPage ?? 1) > 1)
          <a href="?page={{ $currentPage - 1 }}" class="vs-fw-button vs-fw-button-secondary">Previous</a>
        @else
          <span class="vs-fw-button vs-fw-button-disabled">Previous</span>
        @endif
        @if(($currentPage ?? 1) < $totalPages)
          <a href="?page={{ $currentPage + 1 }}" class="vs-fw-button vs-fw-button-secondary">Next</a>
        @else
          <span class="vs-fw-button vs-fw-button-disabled">Next</span>
        @endif
      </div>
    </div>
  @endif
</div>
