document.addEventListener('alpine:init', () => {
    Alpine.data('domainIndex', (config = {}) => ({
        copied: '',
        showWizard: Boolean(config.openWizard),
        selectedDomain: Number.isFinite(Number(config.selectedDomain)) ? Number(config.selectedDomain) : 0,
        statusUrl: String(config.statusUrl || ''),
        shouldPollStatuses: Boolean(config.polling),
        pollingTimer: null,
        pollingBusy: false,
        pollingAttempts: 0,
        maxPollingAttempts: 80,

        init() {
            if (!this.statusUrl || (!this.shouldPollStatuses && !this.hasPollableDomains())) {
                return;
            }

            window.setTimeout(() => this.refreshLiveStatuses(), 1200);
            this.pollingTimer = window.setInterval(() => this.refreshLiveStatuses(), 7000);
        },

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

        hasPollableDomains() {
            return document.querySelector('[data-domain-live][data-domain-polling="1"]') !== null;
        },

        stopStatusPolling() {
            if (this.pollingTimer) {
                window.clearInterval(this.pollingTimer);
                this.pollingTimer = null;
            }
        },

        refreshLiveStatuses() {
            if (!this.statusUrl || this.pollingBusy) {
                return;
            }

            if (this.pollingAttempts >= this.maxPollingAttempts) {
                this.stopStatusPolling();
                return;
            }

            this.pollingBusy = true;
            this.pollingAttempts += 1;

            fetch(this.statusUrl, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
                .then((response) => response.ok ? response.json() : null)
                .then((payload) => {
                    if (!payload || payload.ok !== true || !Array.isArray(payload.groups)) {
                        return;
                    }

                    let keepPolling = false;
                    payload.groups.forEach((group) => {
                        this.applyDomainStatus(group);
                        keepPolling = keepPolling || Boolean(group.live_status?.polling);
                    });

                    if (!keepPolling) {
                        this.stopStatusPolling();
                    }
                })
                .catch(() => {})
                .finally(() => {
                    this.pollingBusy = false;
                });
        },

        applyDomainStatus(group) {
            const displayDomain = String(group.display_domain || '');
            if (!displayDomain) {
                return;
            }

            const live = group.live_status || {};
            document.querySelectorAll('[data-domain-live]').forEach((card) => {
                if (card.getAttribute('data-domain-live') !== displayDomain) {
                    return;
                }

                card.setAttribute('data-domain-polling', live.polling ? '1' : '0');
                card.setAttribute('data-domain-locked', live.locked ? '1' : '0');
                this.setText(card, '[data-domain-status-label]', live.label || group.overall_status || '');
                this.setText(card, '[data-domain-status-description]', live.description || '');
                this.setText(card, '[data-domain-health-score]', String(group.health_score ?? 0));
                this.setText(card, '[data-domain-dns-label]', this.statusWord(group.primary_hostname_status));
                this.setText(card, '[data-domain-ssl-label]', this.sslStatusWord(group.primary_ssl_status));
                this.setText(card, '[data-domain-runtime-label]', group.is_active ? 'Enabled' : 'Disabled');
                this.setText(card, '[data-domain-edge-label]', group.primary_verified ? 'Success' : 'Pending');

                this.setClass(card, '[data-domain-status-badge]', `rounded border px-2 py-1 text-[10px] font-bold uppercase tracking-wider ${live.badge_class || 'border-white/10 bg-white/5 text-[#D7E1F5]'}`);
                this.setClass(card, '[data-domain-dns-class]', `es-status-value font-mono leading-none ${group.primary_hostname_status === 'active' ? 'text-[#10B981]' : 'text-[#D7E1F5]'}`);
                this.setClass(card, '[data-domain-ssl-class]', `es-status-value font-mono leading-none ${group.primary_ssl_status === 'active' ? 'text-[#10B981]' : 'text-[#D7E1F5]'}`);
                this.setClass(card, '[data-domain-runtime-class]', `es-status-value font-mono leading-none ${group.is_active ? 'text-[#10B981]' : 'text-[#D7E1F5]'}`);
                this.setClass(card, '[data-domain-edge-class]', `rounded-md bg-[#0E131D] px-3 py-1.5 font-mono text-sm ${group.primary_verified ? 'text-[#10B981]' : 'text-[#D7E1F5]'}`);

                const spinner = card.querySelector('[data-domain-status-spinner]');
                if (spinner) {
                    spinner.classList.toggle('hidden', !live.locked);
                }

                card.querySelectorAll('[data-domain-action-guard]').forEach((element) => {
                    this.setGuardedActionState(element, Boolean(live.locked));
                });
            });

            document.querySelectorAll('[data-domain-row]').forEach((row) => {
                if (row.getAttribute('data-domain-row') !== displayDomain) {
                    return;
                }

                const progress = row.querySelector('[data-domain-progress]');
                if (progress) {
                    progress.style.width = `${Number(group.health_score || 0)}%`;
                }

                this.setText(row, '[data-domain-row-count]', `${Math.max(Number(group.dns_active_count || 0), Number(group.ssl_active_count || 0))}/${Number(group.total_checks || 1)}`);
                this.setText(row, '[data-domain-row-status]', live.label || group.overall_status || '');

                const dot = row.querySelector('[data-domain-row-dot]');
                if (dot) {
                    dot.className = `es-pulse-dot ${live.dot_class || 'es-pulse-dot-muted'}`;
                }
            });
        },

        setText(root, selector, value) {
            const element = root.querySelector(selector);
            if (element) {
                element.textContent = String(value || '');
            }
        },

        setClass(root, selector, className) {
            const element = root.querySelector(selector);
            if (element) {
                element.className = className;
            }
        },

        setGuardedActionState(element, locked) {
            const tag = element.tagName.toLowerCase();
            if (tag === 'a') {
                element.classList.toggle('pointer-events-none', locked);
                element.classList.toggle('opacity-50', locked);
                element.setAttribute('aria-disabled', locked ? 'true' : 'false');
                return;
            }

            if ('disabled' in element) {
                element.disabled = locked;
            }
        },

        statusWord(status) {
            return String(status || '').toLowerCase() === 'active' ? 'Active' : 'Pending';
        },

        sslStatusWord(status) {
            return String(status || '').toLowerCase() === 'active' ? 'Active' : 'Pending';
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
