<div class="es-card p-5 mb-6 border border-emerald-500/20 bg-emerald-900/10 rounded-xl relative overflow-visible">
  <h3 class="mb-4 text-lg font-semibold text-emerald-100 border-b border-emerald-500/20 pb-2">Penalties</h3>
  <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3 mb-4">
    <div>
      <label class="mb-1 block text-sm font-medium text-sky-100">Failures</label>
      <input type="number" name="max_challenge_failures" value="{{ $thresholds['max_challenge_failures'] ?? 8 }}" min="1" max="50" class="es-input w-full" required>
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-sky-100">Ban Time</label>
      <input type="number" name="temp_ban_ttl_hours" value="{{ $thresholds['temp_ban_ttl_hours'] ?? 24 }}" min="0.01" max="720" step="0.01" class="es-input w-full" required>
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-sky-100">AI Rules</label>
      <input type="number" name="ai_rule_ttl_days" value="{{ $thresholds['ai_rule_ttl_days'] ?? 7 }}" min="0.1" max="365" step="0.1" class="es-input w-full" required>
    </div>
  </div>

  <label class="flex items-center space-x-3 cursor-pointer group w-max">
    <input type="checkbox" name="ad_traffic_strict_mode" class="es-checkbox" value="1" {{ ($thresholds['ad_traffic_strict_mode'] ?? true) ? 'checked' : '' }}>
    <span class="text-sm font-medium text-sky-100">Social Media Traffic Mode</span>
  </label>
</div>
