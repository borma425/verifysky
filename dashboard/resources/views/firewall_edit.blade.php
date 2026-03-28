@extends('layouts.app')

@section('content')
  @php
    $domainOption = $rule['domain_name'] ?? 'unknown';
    $paused = (bool)($rule['paused'] ?? false); 
    $expr = json_decode($rule['expression_json'] ?? '{}', true);
    $field = $expr['field'] ?? '';
    $op = $expr['operator'] ?? '';
    $val = $expr['value'] ?? '';
    $action = $rule['action'] ?? '';
    if ($action === 'block' && str_starts_with($rule['description'] ?? '', '[IP-FARM]')) {
        $action = 'block_ip_farm';
    }
  @endphp

  <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div class="flex items-center gap-3">
      <h2 class="es-title text-2xl">Edit Firewall Rule</h2>
      <span class="es-chip bg-slate-800 text-slate-300 border-slate-700">#{{ $rule['id'] ?? '' }}</span>
    </div>
    <a href="{{ route('firewall.index') }}" class="es-btn es-btn-secondary">Back to Rules</a>
  </div>

  <div class="es-card max-w-4xl p-5 md:p-6 mx-auto">
    <form method="POST" action="{{ route('firewall.update', ['domain' => $domainOption, 'ruleId' => $rule['id'] ?? 0]) }}">
      @csrf
      @method('PUT')
      
      <div class="mb-5">
        <label class="mb-1 block text-sm text-sky-100">Target Domain</label>
        <input class="es-input bg-slate-800/50 cursor-not-allowed text-sm opacity-70" value="{{ $domainOption }}" disabled>
      </div>

      <div class="grid gap-3 md:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm text-sky-100">Action</label>
          <select name="action" class="es-input text-sm" required>
            <option value="managed_challenge" {{ $action === 'managed_challenge' ? 'selected' : '' }}>managed_challenge (Smart CAPTCHA)</option>
            <option value="challenge" {{ $action === 'challenge' ? 'selected' : '' }}>challenge (Interactive CAPTCHA)</option>
            <option value="js_challenge" {{ $action === 'js_challenge' ? 'selected' : '' }}>js_challenge (Invisible JS Challenge)</option>
            <option value="block" {{ $action === 'block' ? 'selected' : '' }}>block (Drop Connection)</option>
            <option value="block_ip_farm" {{ $action === 'block_ip_farm' ? 'selected' : '' }}>block to ip farm (Permanent Graveyard Ban)</option>
            <option value="allow" {{ $action === 'allow' ? 'selected' : '' }}>allow (Fast-Pass, Bypass All)</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-sm text-sky-100">Duration (TTL Updates)</label>
          <select name="duration" class="es-input text-sm" required>
            <option value="" selected>Don't Change Expiry</option>
            <option value="forever">Forever (Remove expiry)</option>
            <option value="1h">1 Hour from now</option>
            <option value="6h">6 Hours from now</option>
            <option value="24h">24 Hours from now</option>
            <option value="7d">7 Days from now</option>
            <option value="30d">30 Days from now</option>
          </select>
          <input type="hidden" name="preserve_expiry" value="1">
        </div>
        <div>
          <label class="mb-1 block text-sm text-sky-100">Description (optional)</label>
          <input type="text" name="description" value="{{ $rule['description'] ?? '' }}" class="es-input text-sm" placeholder="Example: Block abusive ASN">
        </div>
      </div>
      <div class="mt-4 grid gap-3 md:grid-cols-3">
        <div>
          <label class="mb-1 block text-sm text-sky-100">Field</label>
          <select name="field" class="es-input text-sm" required>
            <option value="ip.src" {{ $field === 'ip.src' ? 'selected' : '' }}>IP Address / CIDR</option>
            <option value="ip.src.country" {{ $field === 'ip.src.country' ? 'selected' : '' }}>Country (e.g., EG, US)</option>
            <option value="ip.src.asnum" {{ $field === 'ip.src.asnum' ? 'selected' : '' }}>ASN (e.g., 12345)</option>
            <option value="http.request.uri.path" {{ $field === 'http.request.uri.path' ? 'selected' : '' }}>URI Path (e.g., /wp-login.php)</option>
            <option value="http.request.method" {{ $field === 'http.request.method' ? 'selected' : '' }}>HTTP Method (e.g., POST)</option>
            <option value="http.user_agent" {{ $field === 'http.user_agent' ? 'selected' : '' }}>User Agent (e.g., python-requests)</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-sm text-sky-100">Operator</label>
          <select name="operator" class="es-input text-sm" required>
            <option value="eq" {{ $op === 'eq' ? 'selected' : '' }}>Equals</option>
            <option value="ne" {{ $op === 'ne' ? 'selected' : '' }}>does not equal</option>
            <option value="contains" {{ $op === 'contains' ? 'selected' : '' }}>contains</option>
            <option value="starts_with" {{ $op === 'starts_with' ? 'selected' : '' }}>starts with</option>
            <option value="not_contains" {{ $op === 'not_contains' ? 'selected' : '' }}>does not contain</option>
            <option value="in" {{ $op === 'in' ? 'selected' : '' }}>is in (comma-separated or CIDR)</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-sm text-sky-100">Value</label>
          <input type="text" name="value" value="{{ $val }}" class="es-input text-sm" placeholder="Value to match against" required>
        </div>
      </div>
      <label class="mt-4 mb-5 inline-flex items-center gap-2 text-sm text-amber-200">
        <input type="checkbox" name="paused" value="1" class="rounded border-amber-400/20 bg-amber-500/20" {{ $paused ? 'checked' : '' }}>
        Rule is currently paused
      </label>
      <div class="mt-1">
        <button type="submit" class="es-btn es-btn-primary w-full py-2">Update Firewall Rule</button>
      </form>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      // Dynamic Operator Text based on Field
      const fieldSelect = document.querySelector('select[name="field"]');
      const operatorSelect = document.querySelector('select[name="operator"]');
      
      if (fieldSelect && operatorSelect) {
        const inOption = operatorSelect.querySelector('option[value="in"]');
        function updateOperatorText() {
          if (!inOption) return;
          if (fieldSelect.value === 'ip.src') {
            inOption.textContent = 'is in (comma-separated or CIDR)';
          } else {
            inOption.textContent = 'is in (comma-separated list)';
          }
        }
        fieldSelect.addEventListener('change', updateOperatorText);
        updateOperatorText(); // Initial run
      }
    });
  </script>
@endsection
