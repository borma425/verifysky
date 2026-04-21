<div class="es-card es-animate mb-5 p-5 md:p-6">
  <h3 class="mb-4 text-lg font-bold text-sky-100">Create New Firewall Rule</h3>

  @if(!$canManageFirewallRules)
    <div class="rounded-xl border border-amber-400/30 bg-amber-500/15 px-4 py-3 text-sm text-amber-200">
      Firewall rule management is not available for this account context.
    </div>
  @elseif(empty($domains) && !$showGlobalFirewallOption)
    <div class="rounded-xl border border-amber-400/30 bg-amber-500/15 px-4 py-3 text-sm text-amber-200">
      You need to add at least one domain before creating rules.
    </div>
  @else
    @if(!$canAddFirewallRule)
      <div class="mb-4 rounded-xl border border-violet-400/35 bg-violet-500/15 px-4 py-3 text-sm text-violet-100">
        {{ $firewallUsage['message'] ?? 'Custom firewall rule limit reached for this plan.' }}
      </div>
    @endif
    <form method="POST" action="{{ route('firewall.store') }}">
      @csrf
      <div class="mb-4">
        <label class="mb-1 block text-sm text-sky-100">Target Domain</label>
        <select class="es-input text-sm" name="domain_name" required @disabled(!$canAddFirewallRule)>
          @if($showGlobalFirewallOption)
            <option value="global" selected>{{ $globalFirewallLabel ?? 'All Domains' }}</option>
          @endif
          @foreach($domains as $domain)
            <option value="{{ $domain['domain_name'] }}" @selected(!$showGlobalFirewallOption && $loop->first)>{{ $domain['domain_name'] }}</option>
          @endforeach
        </select>
      </div>

      <div class="grid gap-3 md:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm text-sky-100">Action</label>
          <select name="action" class="es-input text-sm" required @disabled(!$canAddFirewallRule)>
            <option value="managed_challenge">managed_challenge (Smart CAPTCHA)</option>
            <option value="challenge">challenge (Interactive CAPTCHA)</option>
            <option value="js_challenge">js_challenge (Invisible JS Challenge)</option>
            <option value="block">block (Drop Connection)</option>
            <option value="block_ip_farm">block to ip farm (Permanent Graveyard Ban)</option>
            <option value="allow">allow (Fast-Pass, Bypass All)</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-sm text-sky-100">Duration (TTL)</label>
          <select name="duration" class="es-input text-sm" required @disabled(!$canAddFirewallRule)>
            <option value="forever" selected>Forever (No Expiry)</option>
            <option value="1h">1 Hour</option>
            <option value="6h">6 Hours</option>
            <option value="24h">24 Hours</option>
            <option value="7d">7 Days</option>
            <option value="30d">30 Days</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-sm text-sky-100">Description (optional)</label>
          <input type="text" name="description" class="es-input text-sm" placeholder="Example: Block abusive ASN" @disabled(!$canAddFirewallRule)>
        </div>
      </div>

      <div class="mt-3 grid gap-3 md:grid-cols-3">
        <div>
          <label class="mb-1 block text-sm text-sky-100">Field</label>
          <select name="field" class="es-input text-sm js-firewall-field" required @disabled(!$canAddFirewallRule)>
            <option value="ip.src" selected>IP Address / CIDR</option>
            <option value="ip.src.country">Country (e.g., EG, US)</option>
            <option value="ip.src.asnum">ASN (e.g., 12345)</option>
            <option value="http.request.uri.path">URI Path (e.g., /wp-login.php)</option>
            <option value="http.request.method">HTTP Method (e.g., POST)</option>
            <option value="http.user_agent">User Agent (e.g., python-requests)</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-sm text-sky-100">Operator</label>
          <select name="operator" class="es-input text-sm js-firewall-operator" required @disabled(!$canAddFirewallRule)>
            <option value="eq">Equals</option>
            <option value="ne">does not equal</option>
            <option value="contains">contains</option>
            <option value="starts_with">starts with</option>
            <option value="not_contains">does not contain</option>
            <option value="in">is in (comma-separated or CIDR)</option>
            <option value="not_in">is not in (comma-separated or CIDR)</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-sm text-sky-100">Value</label>
          <input type="text" name="value" class="es-input text-sm" placeholder="Value to match against" required @disabled(!$canAddFirewallRule)>
        </div>
      </div>

      <label class="mt-4 inline-flex items-center gap-2 text-sm es-muted">
        <input type="checkbox" name="paused" value="1" class="rounded border-white/20 bg-slate-900/70" @disabled(!$canAddFirewallRule)>
        Create as paused
      </label>

      <div class="mt-4">
        <button type="submit" class="es-btn w-full md:w-auto px-8 disabled:cursor-not-allowed disabled:opacity-60" @disabled(!$canAddFirewallRule)>
          Add Firewall Rule
        </button>
      </div>
    </form>
  @endif
</div>
