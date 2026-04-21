<form method="POST" action="{{ route('domains.update_tuning', ['domain' => $domain]) }}">
  @csrf
  <input type="hidden" name="api_count" x-bind:value="apiCount">

  @include('domains.partials.tuning.general-thresholds')
  @include('domains.partials.tuning.network-limits')
  @include('domains.partials.tuning.penalties')
  @include('domains.partials.tuning.advanced-thresholds')

  <div class="mt-8 flex items-center justify-end">
    <button class="es-btn" type="submit">Save Threshold Settings</button>
  </div>
</form>
