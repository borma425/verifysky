<div class="vs-tuning-panel">
  <h3 class="vs-tone-risk">Network Restrictions</h3>
  <div class="vs-tuning-field-stack">
    <div>
      <label class="vs-tuning-label">
        <span>IP Ban</span>
        <span class="vs-tuning-badge"><span x-text="requestCount(netIpBan)"></span> Req</span>
      </label>
      <p class="vs-tuning-helper">Max visits per IP per minute</p>
      <input type="hidden" name="ip_hard_ban_rate" x-bind:value="requestCount(netIpBan)">
      <input type="number" x-model.number="netIpBan" min="1" max="50000" class="vs-tuning-input" required>
    </div>

    <div>
      <label class="vs-tuning-label">
        <span>ASN Limit</span>
        <span class="vs-tuning-badge"><span x-text="requestCount(netAsnLimit)"></span> Req</span>
      </label>
      <p class="vs-tuning-helper">Max visits per ISP per hour</p>
      <input type="hidden" name="asn_hourly_visit_limit" x-bind:value="requestCount(netAsnLimit)">
      <input type="number" x-model.number="netAsnLimit" min="10" max="1000000" class="vs-tuning-input" required>
    </div>
  </div>
</div>
