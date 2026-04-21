<div class="es-card p-5 mb-6 border border-indigo-500/30 bg-indigo-900/10 rounded-xl relative overflow-hidden">
  <h3 class="text-lg font-semibold text-indigo-100 mb-4 border-b border-indigo-500/20 pb-4">Origin Server & Routing</h3>
  <form method="POST" action="{{ route('domains.update_origin', ['domain' => $domain]) }}">
    @csrf
    <div class="flex flex-col md:flex-row gap-4 items-start">
      <div class="w-full">
        <label class="mb-1 block text-sm font-medium text-sky-100">Origin Server (Backend IP / Hostname)</label>
        <p class="text-xs text-indigo-300/60 mb-2">Configure where VerifySky proxies the cleaned traffic.</p>
        <input type="text" name="origin_server" value="{{ $originServer }}" placeholder="e.g. 198.51.100.23" class="es-input w-full md:w-2/3" required>
        <p class="mt-2 text-[11px] text-amber-300/80 leading-snug"><strong>SSL Note:</strong> VerifySky connects via HTTPS. Ensure port 443 is open and a certificate is installed.</p>
      </div>
      <button type="submit" class="es-btn bg-indigo-600 hover:bg-indigo-500 border-indigo-500 mt-4 md:mt-0 whitespace-nowrap">Save Origin</button>
    </div>
  </form>
</div>
