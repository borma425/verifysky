<details class="es-card group mb-6 rounded-xl border border-sky-500/30 bg-gray-800/40 p-5 cursor-pointer relative overflow-visible">
  <summary class="flex list-none items-center justify-between font-medium text-white/90 outline-none">
    <span class="text-amber-200">Advanced Settings (Flood & Escalation)</span>
    <span class="transition-transform group-open:rotate-180 text-amber-200">⌄</span>
  </summary>

  <div class="mt-6 border-t border-sky-500/20 pt-5 cursor-default">
    @include('domains.partials.tuning.flood-thresholds')
    @include('domains.partials.tuning.auto-mode-thresholds')
    @include('domains.partials.tuning.challenge-sensitivity')
  </div>
</details>
