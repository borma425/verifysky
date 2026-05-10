<div class="vs-fw-builder">
  <div class="vs-fw-builder-head">
    <div>
      <p class="vs-fw-eyebrow">New rule</p>
      <h2>Create firewall rule</h2>
    </div>
    <span class="vs-fw-chip vs-fw-chip-gold">Preview</span>
  </div>

  @if(!$canManageFirewallRules)
    <div class="vs-fw-warning-stack">
      <div class="vs-fw-warning">Firewall rule management is not available for this account context.</div>
    </div>
  @elseif(empty($domains) && !$showGlobalFirewallOption)
    <div class="vs-fw-warning-stack">
      <div class="vs-fw-warning">You need to add at least one domain before creating rules.</div>
    </div>
  @else
    @if(!$canAddFirewallRule)
      <div class="vs-fw-warning-stack">
        <div class="vs-fw-warning vs-fw-warning-danger">{{ $firewallUsage['message'] ?? 'Custom firewall rule limit reached for this plan.' }}</div>
      </div>
    @endif

    <div class="vs-fw-builder-grid">
      <form method="POST" action="{{ route('firewall.store') }}" class="vs-fw-rule-form">
        @csrf

        <section class="vs-fw-form-section">
          <div class="vs-fw-section-head">
            <h3>Scope & action</h3>
            <span>Choose where the rule applies and what happens.</span>
          </div>
          <div class="vs-fw-form-grid">
            <div class="vs-fw-field">
              <label>Target Domain</label>
              <select class="vs-fw-input js-firewall-preview-source" name="domain_name" data-preview-target="scope" required @disabled(!$canAddFirewallRule)>
                @if($showGlobalFirewallOption)
                  <option value="global" selected>{{ $globalFirewallLabel ?? 'All Domains' }}</option>
                @endif
                @foreach($domains as $domain)
                  <option value="{{ $domain['domain_name'] }}" @selected(!$showGlobalFirewallOption && $loop->first)>{{ $domain['domain_name'] }}</option>
                @endforeach
              </select>
              @if($showGlobalFirewallOption)
                <p class="mt-2 text-xs leading-5 text-[#AEB9CC]">All domains covers registered hostnames in this account only, including explicitly added subdomains. It does not create wildcard coverage.</p>
              @endif
            </div>
            <div class="vs-fw-field">
              <label>Action</label>
              <select name="action" class="vs-fw-input js-firewall-preview-source" data-preview-target="action" required @disabled(!$canAddFirewallRule)>
                <option value="managed_challenge">Smart CAPTCHA</option>
                <option value="challenge">Interactive CAPTCHA</option>
                <option value="js_challenge">Invisible browser check</option>
                <option value="block">Block</option>
                <option value="block_ip_farm">Block and remember IP</option>
                <option value="allow">Allow</option>
              </select>
            </div>
            <div class="vs-fw-field">
              <label>Duration</label>
              <select name="duration" class="vs-fw-input js-firewall-preview-source" data-preview-target="ttl" required @disabled(!$canAddFirewallRule)>
                <option value="forever" selected>Forever (No Expiry)</option>
                <option value="1h">1 Hour</option>
                <option value="6h">6 Hours</option>
                <option value="24h">24 Hours</option>
                <option value="7d">7 Days</option>
                <option value="30d">30 Days</option>
              </select>
            </div>
          </div>
        </section>

        <section class="vs-fw-form-section">
          <div class="vs-fw-section-head">
            <h3>Match</h3>
            <span>Choose the traffic this rule should match.</span>
          </div>
          <div class="vs-fw-form-grid vs-fw-form-grid-expression">
            <div class="vs-fw-field">
              <label>Field</label>
              <select name="field" class="vs-fw-input js-firewall-field js-firewall-preview-source" data-preview-target="field" required @disabled(!$canAddFirewallRule)>
                <option value="ip.src" selected>IP Address / CIDR</option>
                <option value="ip.src.country">Country (e.g., EG, US)</option>
                <option value="ip.src.asnum">ASN (e.g., 12345)</option>
                <option value="http.request.uri.path">URI Path (e.g., /wp-login.php)</option>
                <option value="http.request.method">HTTP Method (e.g., POST)</option>
                <option value="http.user_agent">User Agent (e.g., python-requests)</option>
              </select>
            </div>
            <div class="vs-fw-field">
              <label>Operator</label>
              <select name="operator" class="vs-fw-input js-firewall-operator js-firewall-preview-source" data-preview-target="operator" required @disabled(!$canAddFirewallRule)>
                <option value="eq">Equals</option>
                <option value="ne">does not equal</option>
                <option value="contains">contains</option>
                <option value="starts_with">starts with</option>
                <option value="not_contains">does not contain</option>
                <option value="in">is in (comma-separated or CIDR)</option>
                <option value="not_in">is not in (comma-separated or CIDR)</option>
              </select>
            </div>
            <div class="vs-fw-field">
              <label>Value</label>
              <input type="text" name="value" class="vs-fw-input js-firewall-preview-source" data-preview-target="value" placeholder="Value to match against" required @disabled(!$canAddFirewallRule)>
            </div>
          </div>
        </section>

        <section class="vs-fw-form-section">
          <div class="vs-fw-section-head">
            <h3>Notes & status</h3>
            <span>Add a note before turning the rule on.</span>
          </div>
          <div class="vs-fw-form-grid vs-fw-form-grid-notes">
            <div class="vs-fw-field">
              <label>Description (optional)</label>
              <input type="text" name="description" class="vs-fw-input" placeholder="Example: Block abusive ASN" @disabled(!$canAddFirewallRule)>
            </div>
            <div class="vs-fw-action-strip">
              <label class="vs-fw-checkbox">
                <input type="checkbox" name="paused" value="1" class="js-firewall-preview-source" data-preview-target="state" @disabled(!$canAddFirewallRule)>
                <span>Create as paused</span>
              </label>
              <button type="submit" class="vs-fw-button vs-fw-button-primary" @disabled(!$canAddFirewallRule)>
                Add Firewall Rule
              </button>
            </div>
          </div>
        </section>
      </form>

      <aside class="vs-fw-preview" aria-label="Rule Preview">
        <p class="vs-fw-eyebrow">Preview</p>
        <h3>Rule Preview</h3>
        <div class="vs-fw-preview-stack">
          <div class="vs-fw-preview-item">
            <span>Scope</span>
            <strong data-firewall-preview="scope">{{ $globalFirewallLabel ?? 'All Domains' }}</strong>
          </div>
          <div class="vs-fw-preview-item">
            <span>Action</span>
            <strong data-firewall-preview="action">Smart CAPTCHA</strong>
          </div>
          <div class="vs-fw-preview-item">
            <span>Duration</span>
            <strong data-firewall-preview="ttl">Forever (No Expiry)</strong>
          </div>
          <div class="vs-fw-preview-item">
            <span>Expression</span>
            <code data-firewall-preview="expression">IP Address / CIDR Equals "Value to match against"</code>
          </div>
          <div class="vs-fw-preview-item">
            <span>State</span>
            <strong data-firewall-preview="state">Enabled on create</strong>
          </div>
          <div class="vs-fw-impact">
            <strong>Impact</strong>
            <p>Matching visitors may be challenged or blocked. Account-wide rules apply only to registered hostnames.</p>
          </div>
        </div>
      </aside>
    </div>
  @endif
</div>
