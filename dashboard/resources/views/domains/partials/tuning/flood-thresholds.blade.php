<section>
<h4 class="mb-1 text-md font-semibold text-white/90">Flood</h4>
<p class="vs-tuning-helper mb-4">Detect sudden bursts of traffic from a single IP</p>
<div class="vs-tuning-grid vs-tuning-four-grid">
  <div>
    <label class="vs-tuning-label">Burst Challenge <span class="vs-tuning-badge" x-text="requestCount(netBurstChallenge) + ' Req'"></span></label>
    <input type="hidden" name="flood_burst_challenge" x-bind:value="requestCount(netBurstChallenge)">
    <input type="number" x-model.number="netBurstChallenge" min="1" max="50000" class="vs-tuning-input" required>
  </div>
  <div>
    <label class="vs-tuning-label">Burst Block <span class="vs-tuning-badge" x-text="requestCount(netBurstBlock) + ' Req'"></span></label>
    <input type="hidden" name="flood_burst_block" x-bind:value="requestCount(netBurstBlock)">
    <input type="number" x-model.number="netBurstBlock" min="1" max="50000" class="vs-tuning-input" required>
  </div>
  <div>
    <label class="vs-tuning-label">Sustained Challenge <span class="vs-tuning-badge" x-text="requestCount(netSustainedChallenge) + ' Req'"></span></label>
    <input type="hidden" name="flood_sustained_challenge" x-bind:value="requestCount(netSustainedChallenge)">
    <input type="number" x-model.number="netSustainedChallenge" min="1" max="50000" class="vs-tuning-input" required>
  </div>
  <div>
    <label class="vs-tuning-label">Sustained Block <span class="vs-tuning-badge" x-text="requestCount(netSustainedBlock) + ' Req'"></span></label>
    <input type="hidden" name="flood_sustained_block" x-bind:value="requestCount(netSustainedBlock)">
    <input type="number" x-model.number="netSustainedBlock" min="1" max="50000" class="vs-tuning-input" required>
  </div>
</div>
</section>
