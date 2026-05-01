<details class="vs-tuning-card vs-tuning-details" open>
  <summary class="vs-tuning-summary">
    <span class="flex min-w-0 items-center gap-4">
      <img src="{{ asset('duotone/sliders.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-4 w-4">
      <span class="min-w-0">
        <span class="vs-tuning-section-title block">Advanced Calibration Engine</span>
        <span class="vs-tuning-helper mt-1 block uppercase font-bold tracking-widest">Advanced Settings (Flood & Escalation)</span>
      </span>
    </span>
    <img src="{{ asset('duotone/chevron-down.svg') }}" alt="" class="es-duotone-icon es-icon-tone-muted h-4 w-4">
  </summary>

  <div class="vs-tuning-advanced-body cursor-default">
    @include('domains.partials.tuning.flood-thresholds')
    @include('domains.partials.tuning.auto-mode-thresholds')
    @include('domains.partials.tuning.challenge-sensitivity')
  </div>
</details>
