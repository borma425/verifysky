<div class="vs-tuning-panel">
  <h3 class="vs-tuning-card-title">Risk Penalties</h3>
  <div class="vs-tuning-field-stack">
    <div>
      <label class="vs-tuning-label">Failures</label>
      <input type="number" name="max_challenge_failures" value="{{ $thresholds['max_challenge_failures'] ?? 8 }}" min="1" max="50" class="vs-tuning-input" required>
    </div>
    <div>
      <label class="vs-tuning-label">Ban Time</label>
      <input type="number" name="temp_ban_ttl_hours" value="{{ $thresholds['temp_ban_ttl_hours'] ?? 24 }}" min="0.01" max="720" step="0.01" class="vs-tuning-input" required>
    </div>
    <div>
      <label class="vs-tuning-label">AI Rules</label>
      <input type="number" name="ai_rule_ttl_days" value="{{ $thresholds['ai_rule_ttl_days'] ?? 7 }}" min="0.1" max="365" step="0.1" class="vs-tuning-input" required>
    </div>
  </div>
</div>
