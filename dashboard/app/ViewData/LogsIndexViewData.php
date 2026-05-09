<?php

namespace App\ViewData;

use App\Services\EdgeShield\EdgeShieldConfig;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

class LogsIndexViewData
{
    public function __construct(
        private readonly array $payload,
        private readonly array $query,
        private readonly string $indexPath,
        private readonly bool $isAdmin
    ) {}

    public function toArray(): array
    {
        $edgeTarget = app(EdgeShieldConfig::class);
        $rows = $this->formatRows(
            $this->payload['rows'] ?? [],
            $this->payload['all_farm_ips'] ?? [],
            $this->payload['domain_configs'] ?? [],
            $this->payload['domain_config_statuses'] ?? [],
            $this->payload['domain_lifecycle'] ?? [],
            ! $this->isAdmin
        );

        return [
            'logs' => new LengthAwarePaginator(
                $rows,
                (int) ($this->payload['total'] ?? 0),
                (int) ($this->payload['per_page'] ?? 50),
                (int) ($this->payload['page'] ?? 1),
                ['path' => $this->indexPath, 'query' => $this->query]
            ),
            'generalStats' => $this->payload['general_stats'] ?? [],
            'error' => ($this->payload['ok'] ?? false) ? null : (($this->payload['error'] ?? '') ?: 'Failed to load logs'),
            'eventType' => (string) (($this->payload['filters']['event_type'] ?? '')),
            'domainName' => (string) (($this->payload['filters']['domain_name'] ?? '')),
            'ipAddress' => (string) (($this->payload['filters']['ip_address'] ?? '')),
            'includeArchivedLogs' => (bool) (($this->payload['filters']['include_archived'] ?? false)),
            'domainOptions' => $this->payload['filter_options']['domains'] ?? [],
            'eventTypeOptions' => $this->payload['filter_options']['events'] ?? [],
            'eventLabels' => $this->eventLabels(),
            'isAdmin' => $this->isAdmin,
            'canManageLogActions' => $this->isAdmin && $edgeTarget->allowsCloudflareMutations(),
            'edgeShieldTargetLabel' => $edgeTarget->targetEnvironmentLabel(),
            'edgeShieldTargetEnv' => $edgeTarget->targetEnvironment(),
            'edgeShieldMutationsAllowed' => $edgeTarget->allowsCloudflareMutations(),
            'edgeShieldMutationBlockedMessage' => $edgeTarget->mutationBlockedError(),
            'isTenantScoped' => (bool) ($this->payload['tenant_scoped'] ?? false),
            'accessibleDomainsCount' => count($this->payload['accessible_domains'] ?? []),
            'scopeLabel' => $this->scopeLabel(),
            'emptyStateMessage' => $this->emptyStateMessage(),
        ];
    }

    private function scopeLabel(): string
    {
        $selectedDomain = trim((string) ($this->payload['filters']['domain_name'] ?? ''));
        if ($selectedDomain !== '') {
            return $selectedDomain;
        }

        return $this->isAdmin ? 'ALL DOMAINS' : 'YOUR DOMAINS';
    }

    private function emptyStateMessage(): string
    {
        if ($this->isAdmin) {
            return 'No logs.';
        }

        if (count($this->payload['accessible_domains'] ?? []) === 0) {
            return 'No protected domains are assigned to this account yet.';
        }

        return 'No security events matched your domains and filters yet.';
    }

    private function formatRows(array $rows, array $allFarmIps, array $domainConfigs, array $domainConfigStatuses, array $domainLifecycle, bool $assumeActiveWhenUnknown): array
    {
        $farmIpMap = array_flip($allFarmIps);
        $formatted = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $domain = $this->resolveDomain($row);
            $eventType = trim((string) ($row['worst_event_type'] ?? $row['event_type'] ?? ''));
            $eventScore = $this->eventScore($eventType, $row);
            $severity = $this->severity($eventType, $eventScore);
            $recentPaths = $this->recentPaths($row);
            $flaggedEvents = (int) ($row['flagged_events'] ?? 0);
            $solvedEvents = (int) ($row['solved_or_passed_events'] ?? 0);
            $ip = trim((string) ($row['ip_address'] ?? ''));
            $isInIpFarm = isset($farmIpMap[$ip]);
            $ttlHours = $this->ttlHours($domainConfigs, $domain);
            $domainState = $this->domainState($domain, $domainConfigStatuses, $domainLifecycle, $assumeActiveWhenUnknown);

            $formatted[] = [
                'domain' => $domain,
                'domain_state' => $domainState['state'],
                'domain_state_label' => $domainState['label'],
                'event_display' => $this->eventDisplay($eventType, $isInIpFarm, $ttlHours),
                'event_score' => $eventScore,
                'event_score_class' => $this->eventScoreClass($eventScore),
                'severity_label' => $severity['label'],
                'severity_tone' => $severity['tone'],
                'is_repeat_offender' => $this->hasHighVolume($row) && $flaggedEvents > $solvedEvents,
                'ip_address' => $ip,
                'asn' => (string) ($row['asn'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
                'requests_today' => (int) ($row['requests_today'] ?? 0),
                'requests_yesterday' => (int) ($row['requests_yesterday'] ?? 0),
                'requests_month' => (int) ($row['requests_month'] ?? 0),
                'recent_paths' => $recentPaths,
                'top_paths' => array_slice($recentPaths, 0, 2),
                'details_items' => $this->detailsItems((string) ($row['details'] ?? '')),
                'details_fallback' => $this->formatDetails((string) ($row['details'] ?? '')),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'created_at_human' => $this->createdAtHuman((string) ($row['created_at'] ?? '')),
                'prefer_block_action' => $solvedEvents > 0 && $flaggedEvents === 0,
                'can_allow' => $ip !== '' && $ip !== 'N/A' && $domain !== '-' && $domainState['state'] === 'active',
                'is_in_ip_farm' => $isInIpFarm,
                'temp_ban_ttl_hours' => $ttlHours,
            ];
        }

        return $formatted;
    }

    private function detailsItems(string $details): array
    {
        $decoded = json_decode($details, true);
        if (! is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $key => $value) {
            if ((! is_scalar($value) && ! is_array($value)) || $value === '') {
                continue;
            }
            $items[] = [
                'label' => ucwords(str_replace(['_', '-'], ' ', (string) $key)),
                'value' => $this->formatDetails(is_array($value) ? implode(', ', $value) : (string) $value),
            ];
        }

        return $items;
    }

    private function eventDisplay(string $eventType, bool $isInIpFarm, float|int $ttlHours): string
    {
        if ($eventType !== 'hard_block') {
            return $eventType;
        }
        if ($isInIpFarm) {
            return 'hard block';
        }

        $hours = rtrim(rtrim(number_format((float) $ttlHours, 2), '0'), '.');

        return 'Blocked for '.$hours.' '.($hours === '1' ? 'hour' : 'hours');
    }

    private function ttlHours(array $domainConfigs, string $domain): float|int
    {
        $thresholds = $domainConfigs[$domain] ?? [];
        if (isset($thresholds['temp_ban_ttl_seconds']) && is_numeric($thresholds['temp_ban_ttl_seconds'])) {
            return round(((float) $thresholds['temp_ban_ttl_seconds']) / 3600, 2);
        }

        return 24;
    }

    private function domainState(string $domain, array $domainConfigStatuses, array $domainLifecycle, bool $assumeActiveWhenUnknown): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || $domain === '-') {
            return ['state' => 'unknown', 'label' => 'Unknown'];
        }

        $status = strtolower(trim((string) ($domainConfigStatuses[$domain] ?? '')));
        if ($status === 'active') {
            return ['state' => 'active', 'label' => 'Active'];
        }
        if ($status !== '') {
            return ['state' => 'inactive', 'label' => ucfirst($status)];
        }

        foreach ($this->domainLookupKeys($domain) as $key) {
            if (isset($domainLifecycle[$key]) && is_array($domainLifecycle[$key])) {
                return [
                    'state' => (string) ($domainLifecycle[$key]['state'] ?? 'archived'),
                    'label' => (string) ($domainLifecycle[$key]['label'] ?? 'Archived'),
                ];
            }
        }

        return $assumeActiveWhenUnknown
            ? ['state' => 'active', 'label' => 'Active']
            : ['state' => 'archived', 'label' => 'Archived'];
    }

    private function domainLookupKeys(string $domain): array
    {
        $base = preg_replace('/^www\./', '', $domain) ?: $domain;

        return array_values(array_unique(array_filter([
            $domain,
            $base,
            'www.'.$base,
        ])));
    }

    private function eventScore(string $eventType, array $row): int
    {
        $defaults = [
            'challenge_issued' => 35, 'challenge_solved' => 10, 'challenge_failed' => 65, 'challenge_warning' => 15, 'hard_block' => 70,
            'session_created' => 5, 'session_rejected' => 60, 'turnstile_failed' => 55, 'replay_detected' => 75, 'waf_rule_created' => 80,
            'WAF_MERGE_NEW' => 80, 'WAF_MERGE_UPDATED' => 75, 'mode_escalated' => 65, 'ai_defense' => 55, 'WAF_MERGE_SKIPPED' => 45,
        ];
        $raw = $row['worst_event_score'] ?? ($row['max_risk_score'] ?? $row['risk_score'] ?? null);
        $score = is_numeric($raw) ? (int) $raw : ($defaults[$eventType] ?? 50);

        return max(0, min(100, $score));
    }

    private function eventScoreClass(int $score): string
    {
        if ($score >= 70) {
            return 'border-rose-400/40 bg-rose-500/20 text-rose-100';
        }
        if ($score >= 40) {
            return 'border-amber-400/40 bg-amber-500/20 text-amber-100';
        }

        return 'border-emerald-400/40 bg-emerald-500/20 text-emerald-100';
    }

    private function severity(string $eventType, int $score): array
    {
        $normalized = strtolower(trim($eventType));
        $criticalEvents = ['replay_detected'];
        $dangerEvents = ['hard_block', 'challenge_failed', 'turnstile_failed', 'session_rejected', 'mode_escalated', 'waf_rule_created', 'waf_merge_new'];
        $warningEvents = ['challenge_issued', 'challenge_warning', 'ai_defense', 'waf_merge_updated', 'waf_merge_skipped'];
        $successEvents = ['challenge_solved', 'session_created'];

        if (in_array($normalized, $criticalEvents, true) || $score >= 90) {
            return ['tone' => 'critical', 'label' => 'Critical'];
        }

        if (in_array($normalized, $dangerEvents, true) || $score >= 70) {
            return ['tone' => 'danger', 'label' => 'Failed'];
        }

        if (in_array($normalized, $warningEvents, true) || $score >= 40) {
            return ['tone' => 'warning', 'label' => 'Warning'];
        }

        if (in_array($normalized, $successEvents, true) || $score <= 15) {
            return ['tone' => 'success', 'label' => 'Success'];
        }

        return ['tone' => 'info', 'label' => 'Info'];
    }

    private function createdAtHuman(string $createdAt): string
    {
        if (trim($createdAt) === '') {
            return 'Unknown time';
        }

        try {
            return Carbon::parse($createdAt)->diffForHumans();
        } catch (\Throwable) {
            return $createdAt;
        }
    }

    private function hasHighVolume(array $row): bool
    {
        return (int) ($row['requests_today'] ?? 0) >= 20
            || (int) ($row['requests_yesterday'] ?? 0) >= 40
            || (int) ($row['requests_month'] ?? 0) >= 120;
    }

    private function recentPaths(array $row): array
    {
        $decoded = json_decode((string) ($row['recent_paths_json'] ?? '[]'), true);
        $paths = [];
        if (is_array($decoded)) {
            foreach ($decoded as $path) {
                $value = trim((string) $path);
                if ($value === '' || $this->isIgnorablePath($value)) {
                    continue;
                }
                $paths[] = $value;
            }
        }

        if (count($paths) === 0) {
            $fallback = trim((string) ($row['target_path'] ?? ''));
            if ($fallback !== '' && ! $this->isIgnorablePath($fallback)) {
                $paths[] = $fallback;
            }
        }

        return array_slice($paths, 0, 50);
    }

    private function resolveDomain(array $row): string
    {
        $stored = trim((string) ($row['domain_name'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $decoded = json_decode((string) ($row['details'] ?? ''), true);
        if (is_array($decoded)) {
            foreach (['domain', 'domain_name', 'host', 'hostname'] as $key) {
                $value = trim((string) ($decoded[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $targetPath = trim((string) ($row['target_path'] ?? ''));
        if ($targetPath !== '' && preg_match('#^https?://#i', $targetPath) === 1) {
            $host = parse_url($targetPath, PHP_URL_HOST);
            if (is_string($host) && trim($host) !== '') {
                return trim($host);
            }
        }

        return '-';
    }

    private function isIgnorablePath(string $path): bool
    {
        $cleanPath = $path;
        if (preg_match('#^https?://#i', $cleanPath) === 1) {
            $parsedPath = parse_url($cleanPath, PHP_URL_PATH);
            if (is_string($parsedPath) && $parsedPath !== '') {
                $cleanPath = $parsedPath;
            }
        } elseif (($queryPos = strpos($cleanPath, '?')) !== false) {
            $cleanPath = substr($cleanPath, 0, $queryPos);
        }

        return (bool) preg_match('/\.(?:png|jpe?g|gif|webp|svg|ico|avif|css|js|mjs|map|woff2?|ttf|eot|otf|mp4|webm|mp3|wav|pdf|xml|txt|json)$/i', $cleanPath);
    }

    private function formatDetails(string $value): string
    {
        $map = [
            'Temporarily banned IP' => 'Blocked IP Automatically',
            'Auto-banned by IP rate policy' => 'Blocked for exceeding requests limit (DDoS)',
            'hard_block' => 'Hard Blocked',
            'Auto-banned by malicious signature' => 'Blocked due to malicious payload',
            'challenge_issued' => 'Challenged User Verification',
        ];

        return preg_replace('/\((\d+)s window\)/', '(for $1 seconds)', str_replace(array_keys($map), array_values($map), $value)) ?: $value;
    }

    private function eventLabels(): array
    {
        return [
            'farm_block' => 'Blocked IPs (Permanent)',
            'temp_block' => 'Temporary Blocks (Settings)',
            'challenge_issued' => 'Challenge Issued',
            'challenge_solved' => 'Challenge Solved',
            'challenge_failed' => 'Challenge Failed',
            'challenge_warning' => 'Challenge Warning (Soft-Pass)',
            'turnstile_failed' => 'Browser Challenge Failed',
            'session_created' => 'Session Created (Passed)',
            'session_rejected' => 'Session Rejected',
        ];
    }
}
