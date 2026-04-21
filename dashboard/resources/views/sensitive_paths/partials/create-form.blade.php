<div class="es-card es-animate mb-6 p-5 md:p-6 shadow-red-900/10 shadow-lg border-t pl-6 border-t-rose-500/30">
  <h3 class="mb-4 text-lg font-bold text-rose-100 flex items-center gap-2">
    <svg class="h-5 w-5 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
    Protect New Sensitive Path
  </h3>

  <form method="POST" action="{{ route('sensitive_paths.store') }}" id="bulk-paths-form">
    @csrf
    <div id="paths-container" class="space-y-4">
      <div class="path-row flex flex-wrap gap-3 items-end p-4 border border-rose-500/20 rounded-xl bg-slate-900/50 relative group">
        <div class="flex-1 min-w-[150px]">
          <label class="mb-1 block text-sm text-sky-100">Match Strategy</label>
          <select name="paths[0][match_type]" class="es-input text-sm" required>
            <option value="ends_with" selected>Ends With (e.g. .env, .php)</option>
            <option value="exact">Exact Path (e.g. /wp-login.php)</option>
            <option value="contains">Contains (e.g. /admin/)</option>
          </select>
        </div>
        <div class="flex-1 min-w-[150px]">
          <label class="mb-1 block text-sm text-sky-100">Path / Extension</label>
          <input type="text" name="paths[0][path_pattern]" class="es-input text-sm" placeholder="e.g. .env, /aws.env" required>
        </div>
        <div class="flex-1 min-w-[150px]">
          <label class="mb-1 block text-sm text-sky-100">Target Environment</label>
          <select name="paths[0][domain_name]" class="es-input text-sm" required>
            <option value="global" class="font-bold text-sky-200">Global (All)</option>
            @foreach($domains as $domain)
              @if(($domain['status'] ?? '') === 'active')
                <option value="{{ $domain['domain_name'] }}">{{ $domain['domain_name'] }}</option>
              @endif
            @endforeach
          </select>
        </div>
        <div class="flex-1 min-w-[150px]">
          <label class="mb-1 block text-sm text-sky-100">Action & Risk Level</label>
          <select name="paths[0][action]" class="es-input text-sm" required>
            <option value="block" selected>Critical Risk - Block</option>
            <option value="challenge">Medium Risk - CAPTCHA</option>
          </select>
        </div>
        <button type="button" class="remove-row-btn js-remove-row absolute top-[-10px] right-[-10px] bg-slate-800 text-rose-500 hover:text-rose-400 hover:bg-slate-700 rounded-full p-1.5 shadow-md hidden border border-slate-600">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-3 items-center">
      <button type="submit" class="es-btn es-btn-danger px-8">Lockdown Paths</button>
      <button type="button" class="es-btn bg-slate-800 border-[1px] border-slate-600 text-slate-300 hover:bg-slate-700 px-6 js-add-path-row">
        <svg class="h-4 w-4 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Add More
      </button>
    </div>
  </form>
</div>
