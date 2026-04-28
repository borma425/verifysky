<div class="vs-tuning-card vs-tuning-card-pad vs-tuning-accent" style="--vs-tuning-accent: #818CF8">
  <h3 class="vs-tuning-section-title vs-tone-origin">Origin Server & Routing</h3>
  <form method="POST" action="{{ route('domains.update_origin', ['domain' => $domain]) }}">
    @csrf
    <div class="mt-5">
      <label class="vs-tuning-label">Origin Server (Backend IP / Hostname)</label>
      <p class="vs-tuning-helper mt-1">Configure where VerifySky proxies the cleaned traffic.</p>
      <div class="vs-tuning-origin-row">
        <input type="text" name="origin_server" value="{{ $originServer }}" placeholder="e.g. 198.51.100.23" class="vs-tuning-input mt-0" required>
        <button type="submit" class="vs-tuning-button vs-tuning-button-indigo">Save Origin</button>
      </div>
      <p class="vs-tuning-ssl-note"><strong>SSL Note:</strong> VerifySky connects via HTTPS. Ensure port 443 is open and a certificate is installed.</p>
    </div>
  </form>
</div>
