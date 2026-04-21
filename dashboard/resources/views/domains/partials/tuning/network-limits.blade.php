<div class="es-card p-5 mb-6 border border-rose-500/20 bg-rose-900/10 rounded-xl relative overflow-visible">
  <h3 class="mb-4 text-lg font-semibold text-rose-100 border-b border-rose-500/20 pb-2">Network Limits</h3>
  <div class="grid gap-4 md:grid-cols-2">
    <div>
      <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">
        <span>IP Ban</span>
        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-gray-800 text-gray-400 border border-gray-700"><span x-text="requestCount(netIpBan)"></span> Req</span>
      </label>
      <p class="text-xs text-rose-300/60 mb-1">Max visits per IP per minute</p>
      <input type="hidden" name="ip_hard_ban_rate" x-bind:value="requestCount(netIpBan)">
      <input type="number" x-model.number="netIpBan" min="1" max="50000" class="es-input w-full" required>
    </div>

    <div>
      <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">
        <span>ASN Limit</span>
        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-gray-800 text-gray-400 border border-gray-700"><span x-text="requestCount(netAsnLimit)"></span> Req</span>
      </label>
      <p class="text-xs text-sky-300/60 mb-1">Max visits per ISP per hour</p>
      <input type="hidden" name="asn_hourly_visit_limit" x-bind:value="requestCount(netAsnLimit)">
      <input type="number" x-model.number="netAsnLimit" min="10" max="1000000" class="es-input w-full" required>
    </div>
  </div>
</div>
