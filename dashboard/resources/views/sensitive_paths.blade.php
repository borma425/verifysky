@extends('layouts.app')

@section('content')
  <div class="mb-4 flex items-center justify-between">
    <h2 class="es-title text-2xl">Sensitive Paths Protection</h2>
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

  <!-- CREATE NEW PATH RULE -->
  <div class="es-card es-animate mb-6 p-5 md:p-6 shadow-red-900/10 shadow-lg border-t pl-6 border-t-rose-500/30">
    <h3 class="mb-4 text-lg font-bold text-rose-100 flex items-center gap-2">
      <svg class="h-5 w-5 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
      Protect New Sensitive Path
    </h3>
    
    <form method="POST" action="{{ route('sensitive_paths.store') }}" id="bulk-paths-form">
      @csrf
      
      <div id="paths-container" class="space-y-4">
        <!-- FIRST ROW -->
        <div class="path-row flex flex-wrap gap-3 items-end p-4 border border-rose-500/20 rounded-xl bg-slate-900/50 relative group">
          <!-- MATCH TYPE -->
          <div class="flex-1 min-w-[150px]">
            <label class="mb-1 block text-sm text-sky-100">Match Strategy</label>
            <select name="paths[0][match_type]" class="es-input text-sm" required>
              <option value="ends_with" selected>Ends With (e.g. .env, .php)</option>
              <option value="exact">Exact Path (e.g. /wp-login.php)</option>
              <option value="contains">Contains (e.g. /admin/)</option>
            </select>
          </div>

          <!-- PATH PATTERN -->
          <div class="flex-1 min-w-[150px]">
            <label class="mb-1 block text-sm text-sky-100">Path / Extension</label>
            <input type="text" name="paths[0][path_pattern]" class="es-input text-sm" placeholder="e.g. .env, /aws.env" required>
          </div>

          <!-- TARGET DOMAIN -->
          <div class="flex-1 min-w-[150px]">
            <label class="mb-1 block text-sm text-sky-100">Target Environment</label>
            <select name="paths[0][domain_name]" class="es-input text-sm" required>
              <option value="global" class="font-bold text-sky-200">🌐 Global (All)</option>
              @foreach($domains as $domain)
                @if(($domain['status'] ?? '') === 'active')
                  <option value="{{ $domain['domain_name'] }}">{{ $domain['domain_name'] }}</option>
                @endif
              @endforeach
            </select>
          </div>

          <!-- ACTION (SECTION) -->
          <div class="flex-1 min-w-[150px]">
            <label class="mb-1 block text-sm text-sky-100">Action & Risk Level</label>
            <select name="paths[0][action]" class="es-input text-sm" required>
              <option value="block" class="bg-rose-900/50" selected>🔥 Critical Risk - Block</option>
              <option value="challenge" class="bg-amber-900/50">🛡️ Medium Risk - CAPTCHA</option>
            </select>
          </div>

          <!-- REMOVE BUTTON (hidden via JS on first row) -->
          <button type="button" onclick="this.closest('.path-row').remove()" class="remove-row-btn absolute top-[-10px] right-[-10px] bg-slate-800 text-rose-500 hover:text-rose-400 hover:bg-slate-700 rounded-full p-1.5 shadow-md hidden border border-slate-600">
             <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>
      </div>
      
      <div class="mt-4 flex flex-wrap gap-3 items-center">
        <button type="submit" class="es-btn es-btn-danger px-8">Lockdown Paths</button>
        <button type="button" onclick="addPathRow()" class="es-btn bg-slate-800 border-[1px] border-slate-600 text-slate-300 hover:bg-slate-700 px-6">
          <svg class="h-4 w-4 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          Add More
        </button>
      </div>
    </form>
  </div>


  <!-- TWO COLUMN LAYOUT FOR TABLES -->
  <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">

    <!-- SECTION 1: CRITICAL RISK (HARD BLOCK) -->
    <div class="es-card es-animate p-0 border border-rose-500/20">
      <div class="flex items-center justify-between border-b border-rose-500/20 bg-rose-500/10 px-4 py-3">
        <div class="flex items-center gap-3">
          <h3 class="text-base font-bold text-rose-100 flex items-center gap-2">
            <span class="text-xl">🔥</span> Hard Block
          </h3>
          <span class="es-chip bg-slate-800 text-xs text-slate-300">{{ count($criticalPaths) }} Paths</span>
        </div>
        <div>
          <button type="button" onclick="submitBulkCritical()" class="es-btn es-btn-danger px-3 py-1.5 text-xs hidden" id="bulkCriticalBtn">Unlock Selected</button>
        </div>
      </div>
      
      <form id="bulkCriticalForm" method="POST" action="{{ route('sensitive_paths.bulk_destroy') }}">
        @csrf
        @method('DELETE')
        <div class="overflow-x-auto min-h-[300px]">
          <table class="es-table w-full text-sm">
            <thead>
              <tr>
                <th class="w-8 text-center px-2">
                  <input type="checkbox" id="selectAllCritical" class="rounded border-slate-600 bg-slate-800 focus:ring-rose-500">
                </th>
                <th>Domain & Path</th>
                <th>Type</th>
                <th class="text-right pr-4">Action</th>
              </tr>
            </thead>
            <tbody>
            @forelse($criticalPaths as $path)
              <tr class="align-top hover:bg-white/[0.02]">
                <td class="text-center px-2 py-3">
                  <input type="checkbox" name="path_ids[]" value="{{ $path['id'] }}" class="rule-cb-crit rounded border-slate-600 bg-slate-800 focus:ring-rose-500">
                </td>
                <td class="py-3">
                  <div class="mb-1">
                    @if($path['domain_name'] === 'global')
                      <span class="es-chip bg-sky-900/40 text-sky-200 border-sky-500/30 text-[10px] px-1.5 py-0">Global</span>
                    @else
                      <span class="es-chip bg-slate-800 text-slate-300 text-[10px] px-1.5 py-0">{{ $path['domain_name'] }}</span>
                    @endif
                  </div>
                  <div class="font-mono text-emerald-300 font-bold break-all">
                    {{ $path['path_pattern'] }}
                  </div>
                </td>
                <td class="text-sky-300 font-mono text-xs py-3">{{ $path['match_type'] }}</td>
                <td class="text-right pr-4 py-3">
                  <button type="button" onclick="confirmUnlock({{ $path['id'] }})" class="text-slate-400 hover:text-rose-400 transition" title="Unlock Path"><svg class="w-5 h-5 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
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


    <!-- SECTION 2: MEDIUM RISK (CHALLENGE) -->
    <div class="es-card es-animate p-0 border border-amber-500/20">
      <div class="flex items-center justify-between border-b border-amber-500/20 bg-amber-500/10 px-4 py-3">
        <div class="flex items-center gap-3">
          <h3 class="text-base font-bold text-amber-100 flex items-center gap-2">
             <span class="text-xl">🛡️</span> Forced Challenge
          </h3>
          <span class="es-chip bg-slate-800 text-xs text-slate-300">{{ count($mediumPaths) }} Paths</span>
        </div>
        <div>
          <button type="button" onclick="submitBulkMedium()" class="es-btn es-btn-warning px-3 py-1.5 text-xs hidden" id="bulkMediumBtn">Unlock Selected</button>
        </div>
      </div>
      
      <form id="bulkMediumForm" method="POST" action="{{ route('sensitive_paths.bulk_destroy') }}">
        @csrf
        @method('DELETE')
        <div class="overflow-x-auto min-h-[300px]">
          <table class="es-table w-full text-sm">
            <thead>
              <tr>
                <th class="w-8 text-center px-2">
                  <input type="checkbox" id="selectAllMedium" class="rounded border-slate-600 bg-slate-800 focus:ring-amber-500">
                </th>
                <th>Domain & Path</th>
                <th>Type</th>
                <th class="text-right pr-4">Action</th>
              </tr>
            </thead>
            <tbody>
            @forelse($mediumPaths as $path)
              <tr class="align-top hover:bg-white/[0.02]">
                <td class="text-center px-2 py-3">
                  <input type="checkbox" name="path_ids[]" value="{{ $path['id'] }}" class="rule-cb-med rounded border-slate-600 bg-slate-800 focus:ring-amber-500">
                </td>
                <td class="py-3">
                  <div class="mb-1">
                    @if($path['domain_name'] === 'global')
                      <span class="es-chip bg-sky-900/40 text-sky-200 border-sky-500/30 text-[10px] px-1.5 py-0">Global</span>
                    @else
                      <span class="es-chip bg-slate-800 text-slate-300 text-[10px] px-1.5 py-0">{{ $path['domain_name'] }}</span>
                    @endif
                  </div>
                  <div class="font-mono text-emerald-300 font-bold break-all">
                    {{ $path['path_pattern'] }}
                  </div>
                </td>
                <td class="text-sky-300 font-mono text-xs py-3">{{ $path['match_type'] }}</td>
                <td class="text-right pr-4 py-3">
                  <button type="button" onclick="confirmUnlock({{ $path['id'] }})" class="text-slate-400 hover:text-amber-400 transition" title="Unlock Path"><svg class="w-5 h-5 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg></button>
                </td>
              </tr>
            @empty
              <tr><td colspan="4" class="py-12 text-center text-slate-500 border-b-0">No Challenge paths configured.</td></tr>
            @endforelse
            </tbody>
          </table>
        </div>
      </form>
    </div>

  </div> <!-- END TWO COLUMN LAYOUT -->

  <!-- SINGLE UNLOCK FORM -->
  <form id="singleUnlockForm" method="POST" action="" class="hidden">
    @csrf
    @method('DELETE')
  </form>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // Critical Section
      const selectAllCrit = document.getElementById('selectAllCritical');
      const cbCrit = document.querySelectorAll('.rule-cb-crit');
      const btnCrit = document.getElementById('bulkCriticalBtn');
      
      const updateCritObj = () => {
        const checked = document.querySelectorAll('.rule-cb-crit:checked').length;
        if (checked > 0) {
          btnCrit.classList.remove('hidden');
          btnCrit.innerText = `Unlock Selected (${checked})`;
        } else {
          btnCrit.classList.add('hidden');
        }
      };

      if (selectAllCrit) {
        selectAllCrit.addEventListener('change', (e) => {
          cbCrit.forEach(cb => cb.checked = e.target.checked);
          updateCritObj();
        });
      }
      cbCrit.forEach(cb => cb.addEventListener('change', updateCritObj));

      // Medium Section
      const selectAllMed = document.getElementById('selectAllMedium');
      const cbMed = document.querySelectorAll('.rule-cb-med');
      const btnMed = document.getElementById('bulkMediumBtn');
      
      const updateMedObj = () => {
        const checked = document.querySelectorAll('.rule-cb-med:checked').length;
        if (checked > 0) {
          btnMed.classList.remove('hidden');
          btnMed.innerText = `Unlock Selected (${checked})`;
        } else {
          btnMed.classList.add('hidden');
        }
      };

      if (selectAllMed) {
        selectAllMed.addEventListener('change', (e) => {
          cbMed.forEach(cb => cb.checked = e.target.checked);
          updateMedObj();
        });
      }
      cbMed.forEach(cb => cb.addEventListener('change', updateMedObj));
    });
    
    function submitBulkCritical() {
      if(confirm('Are you sure you want to completely unlock these Critical Paths for everyone?')) {
        document.getElementById('bulkCriticalForm').submit();
      }
    }

    function submitBulkMedium() {
      if(confirm('Are you sure you want to remove the Challenge protection from these paths?')) {
        document.getElementById('bulkMediumForm').submit();
      }
    }

    function confirmUnlock(id) {
      if(confirm('Unlock this specific path?')) {
        const form = document.getElementById('singleUnlockForm');
        form.action = `/sensitive-paths/${id}`;
        form.submit();
      }
    }

    let pathRowIndex = 0;
    function addPathRow() {
      pathRowIndex++;
      const container = document.getElementById('paths-container');
      const firstRow = container.querySelector('.path-row');
      const clone = firstRow.cloneNode(true);
      
      // Update name attributes for the array indexes properly
      clone.querySelectorAll('select, input').forEach(el => {
        if (el.name) {
          el.name = el.name.replace(/\[\d+\]/, '[' + pathRowIndex + ']');
        }
      });
      
      // Clear the text input value
      const textInput = clone.querySelector('input[type="text"]');
      if (textInput) textInput.value = '';
      
      // Reveal the remove button in the cloned row
      const removeBtn = clone.querySelector('.remove-row-btn');
      if (removeBtn) removeBtn.classList.remove('hidden');
      
      container.appendChild(clone);
    }
  </script>
@endsection
