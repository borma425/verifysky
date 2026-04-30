<form method="POST" action="{{ route('domains.update_tuning', ['domain' => $domain]) }}">
  @csrf
  <input type="hidden" name="api_count" x-bind:value="apiCount">

  <section class="vs-tuning-config-section">
    <h2 class="vs-tuning-divider-title">
      <span>Threshold Configuration</span>
      <span aria-hidden="true"></span>
    </h2>
    <div class="vs-tuning-grid vs-tuning-settings-grid">
      @include('domains.partials.tuning.general-thresholds')
      @include('domains.partials.tuning.network-limits')
      @include('domains.partials.tuning.penalties')
    </div>
    @include('domains.partials.tuning.traffic-mode')
  </section>

  @include('domains.partials.tuning.advanced-thresholds')

  <div class="vs-tuning-actions">
    <button class="vs-tuning-button vs-tuning-button-primary" type="submit">Save Threshold Settings</button>
  </div>
</form>
