document.addEventListener('alpine:init', () => {
    Alpine.data('domainTuning', () => ({
        domain: '',
        savedThresholds: {},
        challengeProfiles: {
            balanced: { solve: 150, points: 3, tolerance: 24 },
            aggressive: { solve: 200, points: 4, tolerance: 24 },
        },
        activeChallengeMode: 'balanced',
        pagesCount: 1,
        apiCount: 0,
        isAnalyzing: false,
        iframeUrl: '',
        iframeLoaded: false,
        isEditingApi: false,
        timeoutId: null,
        netCaptcha: 6,
        netDailyLimit: 15,
        netIpBan: 120,
        netAsnLimit: 200,
        netBurstChallenge: 8,
        netBurstBlock: 15,
        netSustainedChallenge: 8,
        netSustainedBlock: 40,

        init() {
            this.domain = this.$root.dataset.domain || '';
            this.savedThresholds = this.readJson('domain-tuning-thresholds', {});
            this.challengeProfiles = this.normalizeProfiles(
                this.readJson('domain-tuning-challenge-profiles', this.challengeProfiles)
            );
            this.activeChallengeMode = this.$root.dataset.activeChallengeMode === 'aggressive'
                ? 'aggressive'
                : 'balanced';

            const savedApi = Number(this.savedThresholds.api_count) || 0;
            const initMultiplier = 1 + savedApi;
            this.apiCount = savedApi;
            this.netCaptcha = this.toNet('visit_captcha_threshold', 6, initMultiplier);
            this.netDailyLimit = this.toNet('daily_visit_limit', 15, initMultiplier);
            this.netIpBan = this.toNet('ip_hard_ban_rate', 120, initMultiplier);
            this.netAsnLimit = this.toNet('asn_hourly_visit_limit', 200, initMultiplier);
            this.netBurstChallenge = this.toNet('flood_burst_challenge', 8, initMultiplier);
            this.netBurstBlock = this.toNet('flood_burst_block', 15, initMultiplier);
            this.netSustainedChallenge = this.toNet('flood_sustained_challenge', 8, initMultiplier);
            this.netSustainedBlock = this.toNet('flood_sustained_block', 40, initMultiplier);
            this.applyChallengeProfileToInputs(this.challengeProfiles[this.activeChallengeMode]);
            this.syncChallengeHiddenFields();

            window.addEventListener('message', (event) => {
                if (!this.isTrustedAnalyzerOrigin(event.origin)) {
                    return;
                }

                if (event.data && event.data.type === 'ES_ANALYZE_RESULT') {
                    window.clearTimeout(this.timeoutId);
                    this.pagesCount = 1;
                    this.apiCount = Number(event.data.apiCount) || 0;
                    window.setTimeout(() => {
                        this.isAnalyzing = false;
                        this.iframeUrl = '';
                    }, 600);
                }
            });
        },

        readJson(id, fallback) {
            const element = document.getElementById(id);
            if (!element) {
                return fallback;
            }

            try {
                return JSON.parse(element.textContent || '');
            } catch {
                return fallback;
            }
        },

        toNet(key, fallback, initMultiplier) {
            const raw = this.savedThresholds[key];
            if (raw === undefined || raw === null || raw === '') {
                return fallback;
            }

            return Math.max(1, Math.round(Number(raw) / (initMultiplier || 1)));
        },

        requestCount(value) {
            return Math.max(1, Math.round(Number(value) * (this.pagesCount + this.apiCount)));
        },

        startAnalysis() {
            if (!this.domain) {
                return;
            }

            this.isAnalyzing = true;
            this.iframeLoaded = false;
            this.apiCount = 0;
            this.iframeUrl = `https://${this.domain}/?es_analyzer=1&t=${Date.now()}`;

            this.timeoutId = window.setTimeout(() => {
                if (this.isAnalyzing) {
                    this.isAnalyzing = false;
                    this.iframeUrl = '';
                    window.alert('Analysis timed out. Confirm that the protection script analyzer is available on this domain.');
                }
            }, 60000);
        },

        setChallengePreset(mode) {
            if (mode !== 'balanced' && mode !== 'aggressive') {
                return;
            }

            this.challengeProfiles[this.activeChallengeMode] = this.getCurrentChallengeProfileFromInputs();
            this.activeChallengeMode = mode;
            this.applyChallengeProfileToInputs(this.challengeProfiles[mode]);
            this.syncChallengeHiddenFields();
        },

        detectChallengeMode() {
            this.challengeProfiles[this.activeChallengeMode] = this.getCurrentChallengeProfileFromInputs();
            this.syncChallengeHiddenFields();
        },

        challengeTabClass(mode) {
            if (mode !== this.activeChallengeMode) {
                return 'vs-tuning-tab-inactive';
            }

            return mode === 'balanced'
                ? 'vs-tuning-tab-balanced'
                : 'vs-tuning-tab-aggressive';
        },

        normalizeProfiles(profiles) {
            return {
                balanced: this.normalizeChallengeProfile(profiles.balanced, { solve: 150, points: 3, tolerance: 24 }),
                aggressive: this.normalizeChallengeProfile(profiles.aggressive, { solve: 200, points: 4, tolerance: 24 }),
            };
        },

        normalizeChallengeProfile(profile, fallback) {
            const safe = profile && typeof profile === 'object' ? profile : {};

            return {
                solve: Number.isFinite(Number(safe.solve)) ? Number(safe.solve) : fallback.solve,
                points: Number.isFinite(Number(safe.points)) ? Number(safe.points) : fallback.points,
                tolerance: Number.isFinite(Number(safe.tolerance)) ? Number(safe.tolerance) : fallback.tolerance,
            };
        },

        getCurrentChallengeProfileFromInputs() {
            return {
                solve: Number(this.$refs.challengeSolve.value),
                points: Number(this.$refs.challengePoints.value),
                tolerance: Number(this.$refs.challengeTolerance.value),
            };
        },

        applyChallengeProfileToInputs(profile) {
            if (!this.$refs.challengeSolve || !this.$refs.challengePoints || !this.$refs.challengeTolerance) {
                return;
            }

            this.$refs.challengeSolve.value = profile.solve;
            this.$refs.challengePoints.value = profile.points;
            this.$refs.challengeTolerance.value = profile.tolerance;
        },

        syncChallengeHiddenFields() {
            if (!this.$refs.balancedSolve) {
                return;
            }

            this.$refs.balancedSolve.value = this.challengeProfiles.balanced.solve;
            this.$refs.balancedPoints.value = this.challengeProfiles.balanced.points;
            this.$refs.balancedTolerance.value = this.challengeProfiles.balanced.tolerance;
            this.$refs.aggressiveSolve.value = this.challengeProfiles.aggressive.solve;
            this.$refs.aggressivePoints.value = this.challengeProfiles.aggressive.points;
            this.$refs.aggressiveTolerance.value = this.challengeProfiles.aggressive.tolerance;
        },

        isTrustedAnalyzerOrigin(origin) {
            if (!this.domain) {
                return origin === window.location.origin;
            }

            try {
                const parsed = new URL(origin);
                return parsed.hostname === this.domain || origin === window.location.origin;
            } catch {
                return false;
            }
        },
    }));
});
