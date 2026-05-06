<div class="rounded-lg border border-white/10 bg-[#171C26] p-4">
  <div class="mb-4 flex items-center gap-2 border-b border-white/10 pb-4">
    <img src="{{ asset('duotone/triangle-exclamation.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-5 w-5">
    <h3 class="text-xl font-semibold leading-7 tracking-normal text-white">Protect a new path</h3>
  </div>
  <form method="POST" action="{{ route('sensitive_paths.store') }}" id="bulk-paths-form">
    @csrf
    <div id="paths-container" class="space-y-4">
      <div class="path-row group relative grid grid-cols-1 items-end gap-4 rounded border border-white/10 bg-[#0E131D] p-3 sm:grid-cols-2 lg:grid-cols-12">
        <div class="space-y-1 lg:col-span-3">
          <label class="vs-sp-label">Match type</label>
          <select name="paths[0][match_type]" class="vs-sp-input" required>
            <option value="ends_with" selected>Ends With (e.g. .env, .php)</option>
            <option value="exact">Exact Path (e.g. /wp-login.php)</option>
            <option value="contains">Contains (e.g. /admin/)</option>
          </select>
        </div>
        <div class="space-y-1 lg:col-span-4">
          <label class="vs-sp-label">Path or extension</label>
          <input type="text" name="paths[0][path_pattern]" class="vs-sp-input font-mono placeholder:font-sans" placeholder="e.g. .env, /aws.env" required>
        </div>
        <div class="space-y-1 lg:col-span-2">
          <label class="vs-sp-label">Apply to</label>
          <select name="paths[0][domain_name]" class="vs-sp-input" required>
            <option value="global">All domains</option>
            @foreach($domains as $domain)
              @if(($domain['status'] ?? '') === 'active')
                <option value="{{ $domain['domain_name'] }}">{{ $domain['domain_name'] }}</option>
              @endif
            @endforeach
          </select>
        </div>
        <div class="space-y-1 lg:col-span-2">
          <label class="vs-sp-label">Action</label>
          <select name="paths[0][action]" class="vs-sp-input border-[#D47B78]/30 bg-[#D47B78]/10 text-[#FFB4AB] focus:border-[#D47B78] focus:ring-[#D47B78]/25" required>
            <option value="block" selected>Block</option>
            <option value="challenge">CAPTCHA</option>
          </select>
        </div>
        <button type="button" class="remove-row-btn js-remove-row hidden h-8 w-8 items-center justify-center rounded text-slate-500 transition-colors hover:bg-[#D47B78]/10 hover:text-[#D47B78] lg:col-span-1" title="Remove">
          <img src="{{ asset('duotone/xmark.svg') }}" alt="" class="es-duotone-icon es-icon-tone-coral h-4 w-4">
        </button>
      </div>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 pt-2">
      <button type="button" class="js-add-path-row inline-flex w-full items-center justify-center gap-2 rounded border border-white/10 bg-transparent px-3 py-2 text-sm font-medium text-[#FCB900] transition-colors hover:bg-[#1B202A] hover:text-[#FFDC9C] sm:w-auto">
        <img src="{{ asset('duotone/plus.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-4 w-4">
        Add More
      </button>
      <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded bg-[#D47B78] px-4 py-2 text-sm font-semibold text-black transition-colors hover:bg-[#D47B78]/90 sm:w-auto">
        <img src="{{ asset('duotone/lock.svg') }}" alt="" class="es-duotone-icon h-4 w-4" style="filter: brightness(0);">
        Protect paths
      </button>
    </div>
  </form>
</div>
