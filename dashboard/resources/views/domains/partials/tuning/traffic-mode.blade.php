<label class="vs-tuning-traffic-row">
  <span class="vs-tuning-traffic-icon" aria-hidden="true">↗</span>
  <span class="min-w-0">
    <span class="block text-sm font-extrabold text-white">Social Media Traffic Mode</span>
  </span>
  <input type="checkbox" name="ad_traffic_strict_mode" value="1" {{ ($thresholds['ad_traffic_strict_mode'] ?? true) ? 'checked' : '' }}>
  <span class="vs-tuning-toggle" aria-hidden="true"></span>
</label>
