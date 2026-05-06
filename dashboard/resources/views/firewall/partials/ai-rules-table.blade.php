@if(!empty($aiRules))
  <div class="vs-fw-table-card es-animate es-animate-delay">
    <div class="vs-fw-table-head">
      <div>
        <div class="vs-fw-table-title-row">
          <h3>Auto Rules</h3>
          <span class="vs-fw-chip vs-fw-chip-ai">{{ count($aiRules) }} Smart Rules</span>
        </div>
        <p>Smart rules automatically generated and merged by the AI Defense Engine.</p>
      </div>
    </div>

    <div class="vs-fw-table-scroll">
      <table class="vs-fw-table">
        <thead>
        <tr>
          <th class="w-12 text-center"><input type="checkbox" class="selectAllRules vs-fw-check"></th>
          <th>Domain</th>
          <th>AI Diagnosis &amp; Description</th>
          <th>Action</th>
          <th>Status / Expiry</th>
          <th>Expression (Merged Targets)</th>
          <th class="text-right">Manage</th>
        </tr>
        </thead>
        <tbody>
        @foreach($aiRules as $rule)
          <tr>
            <td class="text-center"><input type="checkbox" name="rule_ids[]" value="{{ $rule['id'] }}" class="rule-checkbox vs-fw-check"></td>
            <td><strong>{{ $rule['domain_name'] }}</strong></td>
            <td>
              <div class="font-medium text-[#DEE2F0]">{{ $rule['description_display'] }}</div>
              <div class="mt-1 font-mono text-[10px] text-[#9FAABC]">Rule ID: {{ $rule['id'] }}</div>
            </td>
            <td><span class="vs-fw-chip vs-fw-chip-danger">{{ $rule['action'] }}</span></td>
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
                <button type="button" class="vs-fw-button vs-fw-button-secondary js-toggle-rule" data-form-id="toggle-form-{{ $rule['id'] }}">
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
