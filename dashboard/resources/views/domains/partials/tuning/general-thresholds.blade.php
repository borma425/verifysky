<div class="vs-tuning-panel">
  <h3 class="vs-tuning-card-title">General Limits</h3>
  <div class="vs-tuning-field-stack">
    <div>
      <label class="vs-tuning-label">
        <span>CAPTCHA</span>
        <span class="vs-tuning-badge"><span x-text="requestCount(netCaptcha)"></span> Req</span>
      </label>
      <p class="vs-tuning-helper">After X visits in 3 minutes</p>
      <input type="hidden" name="visit_captcha_threshold" x-bind:value="requestCount(netCaptcha)">
      <input type="number" x-model.number="netCaptcha" min="1" max="5000" class="vs-tuning-input" required>
    </div>

    <div>
      <label class="vs-tuning-label">
        <span>Daily Limit</span>
        <span class="vs-tuning-badge"><span x-text="requestCount(netDailyLimit)"></span> Req</span>
      </label>
      <p class="vs-tuning-helper">Max visits per user per day</p>
      <input type="hidden" name="daily_visit_limit" x-bind:value="requestCount(netDailyLimit)">
      <input type="number" x-model.number="netDailyLimit" min="1" max="1000000" class="vs-tuning-input" required>
    </div>

    <div>
      <label class="vs-tuning-label">Session</label>
      <p class="vs-tuning-helper">How long user stays trusted (hours)</p>
      <input type="number" name="session_ttl_hours" value="{{ $thresholds['session_ttl_hours'] ?? 1 }}" min="1" max="720" class="vs-tuning-input" required>
    </div>
  </div>
</div>
