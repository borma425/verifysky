<h4 class="mb-1 text-md font-semibold text-white/80 border-t border-sky-500/20 pt-4">Auto Mode</h4>
<p class="text-xs text-sky-300/60 mb-4">Auto aggressive during an active attack</p>
<div class="grid gap-4 md:grid-cols-3">
  <div>
    <label class="mb-1 block text-sm font-medium text-sky-100">Window</label>
    <input type="number" name="auto_aggr_pressure_minutes" value="{{ $thresholds['auto_aggr_pressure_minutes'] ?? 3 }}" min="1" max="30" step="0.5" class="es-input w-full" required>
  </div>
  <div>
    <label class="mb-1 block text-sm font-medium text-sky-100">Duration</label>
    <input type="number" name="auto_aggr_active_minutes" value="{{ $thresholds['auto_aggr_active_minutes'] ?? 10 }}" min="1" max="120" step="0.5" class="es-input w-full" required>
  </div>
  <div>
    <label class="mb-1 block text-sm font-medium text-sky-100">Subnets</label>
    <input type="number" name="auto_aggr_trigger_subnets" value="{{ $thresholds['auto_aggr_trigger_subnets'] ?? 8 }}" min="2" max="50" class="es-input w-full" required>
  </div>
</div>
