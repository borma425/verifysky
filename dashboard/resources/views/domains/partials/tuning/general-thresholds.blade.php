<div class="es-card p-5 mb-6 border border-sky-500/20 bg-sky-900/10 rounded-xl relative overflow-visible">
  <h3 class="mb-4 text-lg font-semibold text-white/90 border-b border-sky-500/20 pb-2">General</h3>
  <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    <div>
      <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">
        <span>CAPTCHA</span>
        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-gray-800 text-gray-400 border border-gray-700"><span x-text="requestCount(netCaptcha)"></span> Req</span>
      </label>
      <p class="text-xs text-sky-300/60 mb-1">After X visits in 3 minutes</p>
      <input type="hidden" name="visit_captcha_threshold" x-bind:value="requestCount(netCaptcha)">
      <input type="number" x-model.number="netCaptcha" min="1" max="5000" class="es-input w-full" required>
    </div>

    <div>
      <label class="mb-1 flex items-center justify-between text-sm font-medium text-sky-100">
        <span>Daily Limit</span>
        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-gray-800 text-gray-400 border border-gray-700"><span x-text="requestCount(netDailyLimit)"></span> Req</span>
      </label>
      <p class="text-xs text-sky-300/60 mb-1">Max visits per user per day</p>
      <input type="hidden" name="daily_visit_limit" x-bind:value="requestCount(netDailyLimit)">
      <input type="number" x-model.number="netDailyLimit" min="1" max="1000000" class="es-input w-full" required>
    </div>

    <div>
      <label class="mb-1 block text-sm font-medium text-sky-100">Session</label>
      <p class="text-xs text-sky-300/60 mb-1">How long user stays trusted (hours)</p>
      <input type="number" name="session_ttl_hours" value="{{ $thresholds['session_ttl_hours'] ?? 1 }}" min="1" max="720" class="es-input w-full" required>
    </div>
  </div>
</div>
