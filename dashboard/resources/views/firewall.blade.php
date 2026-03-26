@extends('layouts.app')

@section('content')
  <div class="mb-4 flex items-center justify-between">
    <h2 class="es-title text-2xl">Global Firewall Rules</h2>
  </div>

  @if(session('status'))<div class="mb-3 rounded-xl border border-emerald-400/30 bg-emerald-500/15 px-4 py-3 text-sm text-emerald-200">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="mb-3 rounded-xl border border-rose-400/30 bg-rose-500/15 px-4 py-3 text-sm text-rose-200">{{ session('error') }}</div>@endif

  @if(!empty($loadErrors))
    <div class="mb-4 rounded-xl border border-amber-400/35 bg-amber-500/15 px-4 py-3 text-sm text-amber-100">
      @foreach($loadErrors as $msg)
        <div>{{ $msg }}</div>
      @endforeach
    </div>
  @endif

  <div class="es-card es-animate mb-5 p-5 md:p-6">
    <h3 class="mb-4 text-lg font-bold text-sky-100">Create New Firewall Rule</h3>
    
    @if(empty($domains))
      <div class="rounded-xl border border-amber-400/30 bg-amber-500/15 px-4 py-3 text-sm text-amber-200">
        You need to add at least one domain before creating rules.
      </div>
    @else
      <form method="POST" action="{{ route('firewall.store') }}">
        @csrf
        
        <div class="mb-4">
          <label class="mb-1 block text-sm text-sky-100">Target Domain</label>
          <select class="es-input text-sm" name="domain_name" required>
            <option value="global" selected>All Domains (Global)</option>
            @foreach($domains as $d)
              <option value="{{ $d['domain_name'] }}">{{ $d['domain_name'] }}</option>
            @endforeach
          </select>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
          <div>
            <label class="mb-1 block text-sm text-sky-100">Action</label>
            <select name="action" class="es-input text-sm" required>
              <option value="managed_challenge">managed_challenge (Smart CAPTCHA)</option>
              <option value="challenge">challenge (Interactive CAPTCHA)</option>
              <option value="js_challenge">js_challenge (Invisible JS Challenge)</option>
              <option value="block">block (Drop Connection)</option>
              <option value="allow">allow (Fast-Pass, Bypass All)</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-sm text-sky-100">Duration (TTL)</label>
            <select name="duration" class="es-input text-sm" required>
              <option value="forever" selected>Forever (No Expiry)</option>
              <option value="1h">1 Hour</option>
              <option value="6h">6 Hours</option>
              <option value="24h">24 Hours</option>
              <option value="7d">7 Days</option>
              <option value="30d">30 Days</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-sm text-sky-100">Description (optional)</label>
            <input type="text" name="description" class="es-input text-sm" placeholder="Example: Block abusive ASN">
          </div>
        </div>
        
        <div class="mt-3 grid gap-3 md:grid-cols-3">
          <div>
            <label class="mb-1 block text-sm text-sky-100">Field</label>
            <select name="field" class="es-input text-sm" required>
              <option value="ip.src" selected>IP Address / CIDR</option>
              <option value="ip.src.country">Country (e.g., EG, US)</option>
              <option value="ip.src.asnum">ASN (e.g., 12345)</option>
              <option value="http.request.uri.path">URI Path (e.g., /wp-login.php)</option>
              <option value="http.request.method">HTTP Method (e.g., POST)</option>
              <option value="http.user_agent">User Agent (e.g., python-requests)</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-sm text-sky-100">Operator</label>
            <select name="operator" class="es-input text-sm" required>
              <option value="eq">Equals</option>
              <option value="ne">does not equal</option>
              <option value="contains">contains</option>
              <option value="starts_with">starts with</option>
              <option value="not_contains">does not contain</option>
              <option value="in">is in (comma-separated or CIDR)</option>
            </select>
          </div>
          <div>
            <label class="mb-1 block text-sm text-sky-100">Value</label>
            <input type="text" name="value" class="es-input text-sm" placeholder="Value to match against" required>
          </div>
        </div>
        
        <label class="mt-4 inline-flex items-center gap-2 text-sm es-muted">
          <input type="checkbox" name="paused" value="1" class="rounded border-white/20 bg-slate-900/70">
          Create as paused
        </label>
        
        <div class="mt-4">
          <button type="submit" class="es-btn w-full md:w-auto px-8">Add Firewall Rule</button>
        </div>
      </form>
    @endif
  </div>

  @php
    $aiRules = collect($firewallRules)->filter(fn($r) => str_starts_with($r['description'] ?? '', '[AI-DEFENSE]'));
    $manualRules = collect($firewallRules)->reject(fn($r) => str_starts_with($r['description'] ?? '', '[AI-DEFENSE]'));
  @endphp

  <form id="bulkDeleteForm" method="POST" action="{{ route('firewall.bulk_destroy') }}">
    @csrf
    @method('DELETE')

    <!-- SYSTEM AI INTERVENTIONS SECTION -->
    @if($aiRules->isNotEmpty())
    <div class="es-card es-animate es-animate-delay p-0 mb-6 border-indigo-500/30 shadow-[0_0_15px_rgba(99,102,241,0.1)]">
      <div class="flex items-center justify-between border-b border-indigo-500/20 bg-indigo-900/20 px-4 py-3 md:px-6">
        <div class="flex items-center gap-3">
          <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-500/20 text-indigo-400">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
          </div>
          <div>
            <h3 class="text-lg font-bold text-indigo-100">System AI Interventions</h3>
            <p class="text-xs text-indigo-300">Smart rules automatically generated & merged by the AI Defense Engine.</p>
          </div>
        </div>
        <div>
          <span class="es-chip bg-indigo-900/40 text-xs text-indigo-300 border border-indigo-500/30">{{ $aiRules->count() }} Smart Rules</span>
        </div>
      </div>
      
      <div class="overflow-x-auto">
        <table class="es-table min-w-[1350px]">
          <thead>
            <tr class="bg-indigo-950/30">
              <th class="w-12 text-center">
                <input type="checkbox" class="selectAllRules rounded border-indigo-600 bg-slate-800 focus:ring-indigo-500">
              </th>
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
            @php 
              $domainName = $rule['domain_name'] ?? 'unknown';
              $paused = (bool)($rule['paused'] ?? false); 
              $expr = json_decode($rule['expression_json'] ?? '{}', true);
              $field = $expr['field'] ?? 'unknown';
              $op = $expr['operator'] ?? 'unknown';
              $val = $expr['value'] ?? 'unknown';
              $expiresAt = $rule['expires_at'] ?? null;
              $isExpired = $expiresAt && $expiresAt < time();
            @endphp
            <tr class="align-top hover:bg-indigo-500/[0.02]">
              <td class="text-center">
                <input type="checkbox" name="rule_ids[]" value="{{ $rule['id'] ?? '' }}" class="rule-checkbox rounded border-slate-600 bg-slate-800 focus:ring-rose-500">
              </td>
              <td class="font-semibold text-sky-100">
              <div class="flex items-center gap-2">
                <svg class="h-4 w-4 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                {{ $domainName }}
              </div>
            </td>
            <td>
              <div class="font-medium text-indigo-100">{{ str_replace('[AI-DEFENSE] ', '', $rule['description'] ?? 'No description') }}</div>
              <div class="mt-1 font-mono text-[10px] es-muted text-slate-500">Rule ID: {{ $rule['id'] ?? '' }}</div>
            </td>
            <td>
              <span class="es-chip border-rose-400/35 bg-rose-500/20 text-rose-100">{{ $rule['action'] ?? '' }}</span>
            </td>
              <td>
                <div class="mb-1">
                  @if($isExpired)
                    <span class="es-chip border-rose-400/35 bg-rose-500/20 text-rose-100">Expired</span>
                  @else
                    <span class="es-chip {{ $paused ? 'border-amber-400/35 bg-amber-500/20 text-amber-100' : 'border-indigo-400/35 bg-indigo-500/20 text-indigo-100' }}">
                      {{ $paused ? 'Paused' : 'Active Defense' }}
                    </span>
                  @endif
                </div>
                @if(!$isExpired)
                  @if($expiresAt)
                    <div class="text-[10px] text-indigo-300" title="{{ gmdate('Y-m-d H:i', $expiresAt) }} UTC">
                      Expires {{ \Carbon\Carbon::createFromTimestamp($expiresAt)->diffForHumans() }}
                    </div>
                  @else
                    <div class="text-[10px] text-indigo-300/70">Forever (No Expiry)</div>
                  @endif
                @endif
              </td>
            <td>
              @php
                $valDisplay = $val;
                if (is_string($val) && substr_count($val, ',') > 2) {
                    $parts = explode(',', $val);
                    $valDisplay = implode(', ', array_slice($parts, 0, 3)) . ' ... (+' . (count($parts) - 3) . ' more targets)';
                }
              @endphp
              <div class="flex items-center gap-2 font-mono text-xs">
                <span class="text-sky-300">{{ $field }}</span>
                <span class="text-fuchsia-300">{{ $op }}</span>
                <span class="text-emerald-300 break-all px-1 bg-black/20 rounded" title="{{ is_string($val) ? htmlspecialchars($val) : '' }}">"{!! htmlspecialchars($valDisplay) !!}"</span>
              </div>
            </td>
              <td>
                <div class="flex flex-wrap justify-end gap-2">
                  @if(!$isExpired)
                    <button type="button" onclick="document.getElementById('toggle-form-{{ $rule['id'] ?? '' }}').submit()" class="es-btn {{ $paused ? 'es-btn-success' : 'es-btn-warning' }} px-3 py-1.5 text-xs">
                      {{ $paused ? 'Enable' : 'Pause' }}
                    </button>
                  @endif
                </div>
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    </div>
    @endif

    <!-- MANUAL FIREWALL RULES SECTION -->
    <div class="es-card es-animate es-animate-delay p-0">
      <div class="flex items-center justify-between border-b border-white/5 bg-slate-800/20 px-4 py-3 md:px-6">
        <div class="flex items-center gap-3">
          <h3 class="text-lg font-bold text-sky-100">Manual Firewall Rules</h3>
          <span class="es-chip bg-slate-800 text-xs text-slate-300">{{ $manualRules->count() }} Configured</span>
        </div>
        <div>
          <button type="button" onclick="submitBulkDelete()" class="es-btn es-btn-danger px-3 py-1.5 text-xs hidden" id="bulkDeleteBtn">Delete Selected</button>
        </div>
      </div>
      
      <div class="overflow-x-auto">
        <table class="es-table min-w-[1350px]">
          <thead>
            <tr>
              <th class="w-12 text-center">
                <input type="checkbox" class="selectAllRules rounded border-slate-600 bg-slate-800 focus:ring-rose-500">
              </th>
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
            @php 
              $domainName = $rule['domain_name'] ?? 'unknown';
              $paused = (bool)($rule['paused'] ?? false); 
              $expr = json_decode($rule['expression_json'] ?? '{}', true);
              $field = $expr['field'] ?? 'unknown';
              $op = $expr['operator'] ?? 'unknown';
              $val = $expr['value'] ?? 'unknown';
              $expiresAt = $rule['expires_at'] ?? null;
              $isExpired = $expiresAt && $expiresAt < time();
              
              $valDisplay = $val;
              if (is_string($val) && substr_count($val, ',') > 2) {
                  $parts = explode(',', $val);
                  $valDisplay = implode(', ', array_slice($parts, 0, 3)) . ' ... (+' . (count($parts) - 3) . ' more targets)';
              }
            @endphp
            <tr class="align-top hover:bg-white/[0.02]">
              <td class="text-center">
                <input type="checkbox" name="rule_ids[]" value="{{ $rule['id'] ?? '' }}" class="rule-checkbox rounded border-slate-600 bg-slate-800 focus:ring-rose-500">
              </td>
              <td class="font-semibold text-sky-100">
              <div class="flex items-center gap-2">
                <svg class="h-4 w-4 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                {{ $domainName }}
              </div>
            </td>
            <td>
              <div class="font-medium text-slate-100">{{ $rule['description'] ?? 'No description' }}</div>
              <div class="mt-1 font-mono text-[10px] es-muted text-slate-500">ID: {{ $rule['id'] ?? '' }}</div>
            </td>
            <td>
              <span class="es-chip border-indigo-400/35 bg-indigo-500/20 text-indigo-100">{{ $rule['action'] ?? '' }}</span>
            </td>
              <td>
                <div class="mb-1">
                  @if($isExpired)
                    <span class="es-chip border-rose-400/35 bg-rose-500/20 text-rose-100">Expired</span>
                  @else
                    <span class="es-chip {{ $paused ? 'border-amber-400/35 bg-amber-500/20 text-amber-100' : 'border-emerald-400/35 bg-emerald-500/20 text-emerald-100' }}">
                      {{ $paused ? 'Paused' : 'Enabled' }}
                    </span>
                  @endif
                </div>
                @if(!$isExpired)
                  @if($expiresAt)
                    <div class="text-[10px] text-sky-300" title="{{ gmdate('Y-m-d H:i', $expiresAt) }} UTC">
                      Expires {{ \Carbon\Carbon::createFromTimestamp($expiresAt)->diffForHumans() }}
                    </div>
                  @else
                    <div class="text-[10px] text-sky-300/70">Forever (No Expiry)</div>
                  @endif
                @endif
              </td>
            <td>
              <div class="flex items-center gap-2 font-mono text-xs">
                <span class="text-sky-300">{{ $field }}</span>
                <span class="text-fuchsia-300">{{ $op }}</span>
                <span class="text-emerald-300 break-all px-1 bg-black/20 rounded" title="{{ is_string($val) ? htmlspecialchars($val) : '' }}">"{!! htmlspecialchars($valDisplay) !!}"</span>
              </div>
            </td>
              <td>
                <div class="flex flex-wrap justify-end gap-2">
                  @if(!$isExpired)
                    <a href="{{ route('firewall.edit', ['domain' => $domainName, 'ruleId' => $rule['id'] ?? '']) }}" class="es-btn es-btn-secondary px-3 py-1.5 text-xs">Edit</a>
                    <button type="button" onclick="document.getElementById('toggle-form-{{ $rule['id'] ?? '' }}').submit()" class="es-btn {{ $paused ? 'es-btn-success' : 'es-btn-warning' }} px-3 py-1.5 text-xs">
                      {{ $paused ? 'Enable' : 'Pause' }}
                    </button>
                  @endif
                </div>
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
          <div class="text-sm text-slate-400">
            Page {{ $currentPage ?? 1 }} of {{ $totalPages }} (Showing {{ $totalRules }} total rules across all segments)
          </div>
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
  </form>

  <!-- Hidden Remote Forms for HTML5 Toggle actions -->
  @foreach($firewallRules as $rule)
    @php 
      $dName = $rule['domain_name'] ?? 'unknown';
      $pState = (bool)($rule['paused'] ?? false);
    @endphp
    <form id="toggle-form-{{ $rule['id'] ?? '' }}" method="POST" action="{{ route('firewall.toggle', ['domain' => $dName, 'ruleId' => $rule['id'] ?? '']) }}" class="hidden">
      @csrf
      <input type="hidden" name="paused" value="{{ $pState ? 0 : 1 }}">
    </form>
  @endforeach

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const selectAllToggles = document.querySelectorAll('.selectAllRules');
      const checkboxes = document.querySelectorAll('.rule-checkbox');
      const bulkBtn = document.getElementById('bulkDeleteBtn');
      
      function toggleBulkBtn() {
        const checked = document.querySelectorAll('.rule-checkbox:checked').length;
        if (checked > 0) {
          bulkBtn.classList.remove('hidden');
          bulkBtn.innerText = `Delete Selected (${checked})`;
        } else {
          bulkBtn.classList.add('hidden');
        }
      }
      
      selectAllToggles.forEach(toggle => {
        toggle.addEventListener('change', (e) => {
          const table = e.target.closest('table');
          const tableCheckboxes = table.querySelectorAll('.rule-checkbox');
          tableCheckboxes.forEach(cb => cb.checked = e.target.checked);
          toggleBulkBtn();
        });
      });
      
      checkboxes.forEach(cb => {
        cb.addEventListener('change', toggleBulkBtn);
      });

      // Dynamic Operator Text based on Field
      const fieldSelect = document.querySelector('select[name="field"]');
      const operatorSelect = document.querySelector('select[name="operator"]');
      if (fieldSelect && operatorSelect) {
        const inOption = operatorSelect.querySelector('option[value="in"]');
        function updateOperatorText() {
          if (!inOption) return;
          if (fieldSelect.value === 'ip.src') {
            inOption.textContent = 'is in (comma-separated or CIDR)';
          } else {
            inOption.textContent = 'is in (comma-separated list)';
          }
        }
        fieldSelect.addEventListener('change', updateOperatorText);
        updateOperatorText(); // Initial run
      }
    });
    
    function submitBulkDelete() {
      if(confirm('Are you sure you want to delete all selected rules?')) {
        document.getElementById('bulkDeleteForm').submit();
      }
    }
  </script>
@endsection
