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
        useAutomaticOrigin: !Boolean(config.forceManualOrigin) && String(config.manualOrigin ?? '').trim() === '',

        protectedHostname() {
            const normalized = this.normalizeDomain(this.domainName);
            if (!normalized) {
                return '';
            }
            return normalized.startsWith('www.') || !this.looksLikeApex(normalized)
                ? normalized
                : `www.${normalized}`;
        },

        dnsRecordName() {
            const normalized = this.normalizeDomain(this.domainName);
            if (!normalized) {
                return '';
            }
            if (normalized.startsWith('www.')) {
                return 'www';
            }
            if (this.looksLikeApex(normalized)) {
                return 'www';
            }
            return normalized.split('.')[0] || normalized;
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
