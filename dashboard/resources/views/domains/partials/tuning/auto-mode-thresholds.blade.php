<section>
<h4 class="mb-1 text-md font-semibold text-white/90">Auto Mode</h4>
<p class="vs-tuning-helper mb-4">Auto aggressive during an active attack</p>
<div class="vs-tuning-grid vs-tuning-three-grid">
  <div>
    <label class="vs-tuning-label">Window</label>
    <input type="number" name="auto_aggr_pressure_minutes" value="{{ $thresholds['auto_aggr_pressure_minutes'] ?? 3 }}" min="1" max="30" step="0.5" class="vs-tuning-input" required>
  </div>
  <div>
    <label class="vs-tuning-label">Duration</label>
    <input type="number" name="auto_aggr_active_minutes" value="{{ $thresholds['auto_aggr_active_minutes'] ?? 10 }}" min="1" max="120" step="0.5" class="vs-tuning-input" required>
  </div>
  <div>
    <label class="vs-tuning-label">Subnets</label>
    <input type="number" name="auto_aggr_trigger_subnets" value="{{ $thresholds['auto_aggr_trigger_subnets'] ?? 8 }}" min="2" max="50" class="vs-tuning-input" required>
  </div>
</div>
</section>
