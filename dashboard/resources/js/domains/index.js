document.addEventListener('alpine:init', () => {
    Alpine.data('domainIndex', (config = {}) => ({
        copied: '',
        showWizard: Boolean(config.openWizard),
        selectedDomain: Number.isFinite(Number(config.selectedDomain)) ? Number(config.selectedDomain) : 0,

        openWizard() {
            this.showWizard = true;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        selectDomain(index) {
            this.selectedDomain = Number(index) || 0;
        },

        copy(value, key) {
            const done = () => {
                this.copied = key;
                window.setTimeout(() => {
                    this.copied = '';
                }, 1200);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(value).then(done);
                return;
            }

            const field = document.createElement('textarea');
            field.value = value;
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            document.execCommand('copy');
            document.body.removeChild(field);
            done();
        },

        confirmRemoval(event) {
            if (!window.confirm('Are you sure you want to completely remove this domain?')) {
                event.preventDefault();
            }
        },
    }));

    Alpine.data('domainCreateForm', (config = {}) => ({
        validating: false,
        step: 1,
        copied: '',
        domainName: String(config.domainName ?? ''),
        dnsTarget: String(config.dnsTarget ?? ''),
        manualOrigin: String(config.manualOrigin ?? ''),
        apexMode: String(config.apexMode ?? 'www_redirect'),
        dnsProvider: String(config.dnsProvider ?? 'other'),
        useAutomaticOrigin: !Boolean(config.forceManualOrigin) && String(config.manualOrigin ?? '').trim() === '',

        protectedHostname() {
            return this.protectedHostnames()[0] || '';
        },

        protectedHostnames() {
            const normalized = this.normalizeDomain(this.domainName);
            if (!normalized) {
                return [];
            }
            if (normalized.startsWith('www.') || !this.looksLikeApex(normalized)) {
                return [normalized];
            }
            if (this.apexMode === 'direct_apex') {
                return [normalized, `www.${normalized}`];
            }
            if (this.apexMode === 'subdomain_only') {
                return [normalized];
            }
            return [`www.${normalized}`];
        },

        canonicalHostname() {
            const normalized = this.normalizeDomain(this.domainName);
            if (!normalized) {
                return '';
            }
            return this.looksLikeApex(normalized) && this.apexMode === 'www_redirect'
                ? `www.${normalized}`
                : normalized;
        },

        dnsRecordName() {
            return this.dnsRows()[0]?.name || '';
        },

        dnsRows() {
            const normalized = this.normalizeDomain(this.domainName);
            return this.protectedHostnames().map((hostname) => {
                const isRoot = hostname === normalized && this.looksLikeApex(hostname);

                return {
                    type: isRoot && this.apexMode === 'direct_apex' ? 'ALIAS / ANAME / Flattened CNAME' : 'CNAME',
                    name: isRoot ? '@' : (hostname.startsWith('www.') && hostname.slice(4) === normalized ? 'www' : hostname.split('.')[0] || hostname),
                    value: this.dnsTarget,
                    hostname,
                };
            });
        },

        rootInstruction() {
            const normalized = this.normalizeDomain(this.domainName);
            if (!this.looksLikeApex(normalized) || this.apexMode !== 'www_redirect') {
                return '';
            }

            return `${normalized} -> https://${this.canonicalHostname()} using 301 or 308`;
        },

        providerNote() {
            if (this.apexMode === 'direct_apex') {
                if (this.dnsProvider === 'cloudflare') {
                    return 'Use a root CNAME at @. Cloudflare will flatten it automatically.';
                }
                if (this.dnsProvider === 'namecheap') {
                    return 'Use an ALIAS record at @ if a regular root CNAME is not available.';
                }
                if (this.dnsProvider === 'godaddy') {
                    return 'GoDaddy root ALIAS support is limited. Recommended mode is www + root forwarding.';
                }
                return 'Use ALIAS, ANAME, or flattened CNAME if your DNS provider supports it.';
            }
            if (this.apexMode === 'www_redirect') {
                if (this.dnsProvider === 'godaddy') {
                    return 'Use Domain Forwarding from the root domain to the www hostname and choose permanent forwarding when available.';
                }
                if (this.dnsProvider === 'cloudflare') {
                    return 'Create the www CNAME, then add a Redirect Rule from the root domain to the www hostname.';
                }
                return 'Create the www CNAME, then configure a permanent 301 or 308 redirect from the root domain to the www hostname.';
            }

            return 'This setup protects only the exact hostname entered.';
        },

        nextFromDetails() {
            if (!this.domainName.trim()) {
                return;
            }
            if (!this.useAutomaticOrigin && !this.manualOrigin.trim()) {
                return;
            }
            this.step = 2;
        },

        confirmDnsAdded() {
            this.step = 3;
        },

        back(step = 1) {
            this.step = step;
        },

        copy(value, key) {
            const done = () => {
                this.copied = key;
                window.setTimeout(() => {
                    this.copied = '';
                }, 1200);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(String(value || '')).then(done);
                return;
            }

            const field = document.createElement('textarea');
            field.value = String(value || '');
            field.setAttribute('readonly', '');
            field.style.position = 'fixed';
            field.style.opacity = '0';
            document.body.appendChild(field);
            field.select();
            document.execCommand('copy');
            document.body.removeChild(field);
            done();
        },

        normalizeDomain(value) {
            return String(value || '')
                .trim()
                .toLowerCase()
                .replace(/^https?:\/\//, '')
                .split('/')[0];
        },

        looksLikeApex(domain) {
            if (!domain || domain.includes('*')) {
                return false;
            }

            const labels = domain.split('.').filter(Boolean);
            if (labels.length === 2) {
                return true;
            }

            const suffix = labels.slice(-2).join('.');
            const commonSecondLevelSuffixes = [
                'ac.uk', 'co.il', 'co.jp', 'co.nz', 'co.uk',
                'com.au', 'com.br', 'com.eg', 'com.mx', 'com.sa', 'com.tr', 'com.ua',
                'net.au', 'net.eg', 'net.sa', 'org.au', 'org.uk',
            ];

            return labels.length === 3 && commonSecondLevelSuffixes.includes(suffix);
        },

        submit() {
            this.validating = true;
        },
    }));
});
