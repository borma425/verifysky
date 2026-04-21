<h4 class="mb-1 text-md font-semibold text-white/80">Flood</h4>
<p class="text-xs text-sky-300/60 mb-4">Detect sudden bursts of traffic from a single IP</p>
<div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4 mb-6">
  <div>
    <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">Burst Challenge <span x-text="requestCount(netBurstChallenge) + ' Req'"></span></label>
    <input type="hidden" name="flood_burst_challenge" x-bind:value="requestCount(netBurstChallenge)">
    <input type="number" x-model.number="netBurstChallenge" min="1" max="50000" class="es-input w-full" required>
  </div>
  <div>
    <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">Burst Block <span x-text="requestCount(netBurstBlock) + ' Req'"></span></label>
    <input type="hidden" name="flood_burst_block" x-bind:value="requestCount(netBurstBlock)">
    <input type="number" x-model.number="netBurstBlock" min="1" max="50000" class="es-input w-full" required>
  </div>
  <div>
    <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">Sustained Challenge <span x-text="requestCount(netSustainedChallenge) + ' Req'"></span></label>
    <input type="hidden" name="flood_sustained_challenge" x-bind:value="requestCount(netSustainedChallenge)">
    <input type="number" x-model.number="netSustainedChallenge" min="1" max="50000" class="es-input w-full" required>
  </div>
  <div>
    <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">Sustained Block <span x-text="requestCount(netSustainedBlock) + ' Req'"></span></label>
    <input type="hidden" name="flood_sustained_block" x-bind:value="requestCount(netSustainedBlock)">
    <input type="number" x-model.number="netSustainedBlock" min="1" max="50000" class="es-input w-full" required>
  </div>
</div>
