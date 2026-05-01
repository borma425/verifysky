<div class="vs-tuning-card vs-tuning-card-pad">
  <form method="POST" action="{{ route('domains.update_origin', ['domain' => $domain]) }}">
    @csrf
    <div class="vs-tuning-section-head">
      <h3 class="vs-tuning-kicker">
        <img src="{{ asset('duotone/router.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-4 w-4">
        Origin Server & Routing
      </h3>
      <button type="submit" class="vs-tuning-button vs-tuning-button-primary vs-tuning-button-compact">Save Origin</button>
    </div>
    <div>
      <label class="vs-tuning-mini-label">Origin Server (Backend IP / Hostname)</label>
      <input type="text" name="origin_server" value="{{ $originServer }}" placeholder="e.g. 198.51.100.23" class="vs-tuning-input" required>
      <p class="vs-tuning-ssl-note"><strong>SSL Note:</strong> VerifySky connects via HTTPS. Ensure port 443 is open and a certificate is installed.</p>
    </div>
  </form>
</div>
