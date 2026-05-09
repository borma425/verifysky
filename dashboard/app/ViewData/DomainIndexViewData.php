<?php

namespace App\ViewData;

class DomainIndexViewData
{
    private const DEFAULT_MODE = 'balanced';

    public function __construct(
        private readonly array $result,
        private readonly string $cnameTarget,
        private readonly bool $isAdmin = false,
        private readonly array $domainsUsage = []
    ) {}

    public function toArray(): array
    {
        $domains = $this->successful() ? ($this->result['domains'] ?? []) : [];
        $domainGroups = $this->groupDomainsForDisplay($domains);
        $preparedDomainGroups = $this->prepareDomainGroups($domainGroups);

        return [
            'domains' => $domains,
            'domainGroups' => $domainGroups,
            'preparedDomainGroups' => $preparedDomainGroups,
            'domains_needs_polling' => $this->domainsNeedPolling($preparedDomainGroups),
            'cnameTarget' => $this->cnameTarget,
            'isAdmin' => $this->isAdmin,
            'domains_used' => (int) ($this->domainsUsage['used'] ?? 0),
            'domains_limit' => array_key_exists('limit', $this->domainsUsage) ? $this->domainsUsage['limit'] : null,
            'can_add_domain' => (bool) ($this->domainsUsage['can_add'] ?? true),
            'plan_key' => (string) ($this->domainsUsage['plan_key'] ?? config('plans.default', 'starter')),
            'domains_usage_message' => $this->domainsUsage['message'] ?? null,
            'error' => $this->successful() ? null : ($this->result['error'] ?: 'We could not load domains.'),
        ];
    }

    private function successful(): bool
    {
        return ($this->result['ok'] ?? false) === true;
    }

    private function groupDomainsForDisplay(array $domains): array
    {
        $groups = [];
        foreach ($domains as $domain) {
            if (! is_array($domain)) {
                continue;
            }

            $hostname = strtolower(trim((string) ($domain['domain_name'] ?? '')));
            if ($hostname === '') {
                continue;
            }

            $key = $this->displayGroupKey($hostname);
            $variant = $hostname === $key
                ? 'root'
                : (str_starts_with($hostname, 'www.') && substr($hostname, 4) === $key ? 'www' : 'single');

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'display_domain' => $key,
                    'cname_target' => $domain['cname_target'] ?? $this->cnameTarget,
                    'status' => $domain['status'] ?? 'active',
                    'provisioning_status' => $this->normalizeLifecycle((string) ($domain['provisioning_status'] ?? 'active')),
                    'provisioning_error' => (string) ($domain['provisioning_error'] ?? ''),
                    'security_mode' => $domain['security_mode'] ?? self::DEFAULT_MODE,
                    'force_captcha' => (int) ($domain['force_captcha'] ?? 0),
                    'created_at' => $domain['created_at'] ?? '',
                    'rows' => [],
                    'root' => null,
                    'www' => null,
                ];
            }

            $groups[$key]['rows'][] = $domain;
            if ($variant === 'root' || $variant === 'www') {
                $groups[$key][$variant] = $domain;
            }
            if (
                (string) $groups[$key]['created_at'] === ''
                || (string) ($domain['created_at'] ?? '') < (string) $groups[$key]['created_at']
            ) {
                $groups[$key]['created_at'] = (string) ($domain['created_at'] ?? '');
            }
        }

        foreach ($groups as &$group) {
            usort($group['rows'], function (array $a, array $b) use ($group): int {
                $aName = (string) ($a['domain_name'] ?? '');
                $bName = (string) ($b['domain_name'] ?? '');
                $score = fn (string $name): int => $name === $group['display_domain'] ? 0 : (str_starts_with($name, 'www.') ? 1 : 2);

                return $score($aName) <=> $score($bName) ?: strcmp($aName, $bName);
            });
        }
        unset($group);

        return array_values($groups);
    }

    private function prepareDomainGroups(array $domainGroups): array
    {
        return array_map(fn (array $group): array => $this->prepareDomainGroup($group), $domainGroups);
    }

    private function domainsNeedPolling(array $domainGroups): bool
    {
        foreach ($domainGroups as $group) {
            if (($group['live_status']['polling'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function prepareDomainGroup(array $group): array
    {
        $rows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
        $primaryRows = array_values(array_filter($rows, function ($row) use ($rows): bool {
            return is_array($row)
                && (str_starts_with((string) ($row['domain_name'] ?? ''), 'www.') || count($rows) === 1);
        }));
        $advancedRows = array_values(array_filter($rows, fn ($row): bool => ! in_array($row, $primaryRows, true)));

        $primaryRow = is_array($primaryRows[0] ?? null) ? $primaryRows[0] : [];
        $primaryDomain = (string) ($primaryRows[0]['domain_name'] ?? ($group['display_domain'] ?? ''));
        $hostnameStatus = strtolower((string) ($primaryRows[0]['hostname_status'] ?? 'pending'));
        $sslStatus = strtolower((string) ($primaryRows[0]['ssl_status'] ?? 'pending_validation'));
        $lifecycleStatus = $this->lifecycleStatus($primaryRows);
        $provisioningError = (string) ($primaryRows[0]['provisioning_error'] ?? ($group['provisioning_error'] ?? ''));
        $primaryVerified = $hostnameStatus === 'active' && $sslStatus === 'active';
        $status = $this->runtimeStatus((string) ($primaryRow['status'] ?? ($group['status'] ?? 'active')));
        $mode = strtolower((string) ($primaryRow['security_mode'] ?? ($group['security_mode'] ?? self::DEFAULT_MODE)));
        $forceCaptcha = (int) ($primaryRow['force_captcha'] ?? ($group['force_captcha'] ?? 0));
        $healthCounts = $this->healthCounts($primaryRows);
        $overallStatus = $this->overallStatus($primaryVerified, $hostnameStatus, $lifecycleStatus);
        $liveStatus = $this->liveStatus(
            $overallStatus,
            $primaryVerified,
            $hostnameStatus,
            $sslStatus,
            $lifecycleStatus,
            $provisioningError
        );

        return array_merge($group, [
            'status' => $status,
            'force_captcha' => $forceCaptcha,
            'mode' => $mode,
            'primary_rows' => $primaryRows,
            'advanced_rows' => $advancedRows,
            'primary_domain' => $primaryDomain,
            'primary_hostname_status' => $hostnameStatus,
            'primary_ssl_status' => $sslStatus,
            'provisioning_status' => $lifecycleStatus,
            'provisioning_error' => $provisioningError,
            'primary_verified' => $primaryVerified,
            'overall_status' => $overallStatus,
            'overall_badge_class' => $liveStatus['badge_class'],
            'live_status' => $liveStatus,
            'health_score' => $healthCounts['health_score'],
            'dns_active_count' => $healthCounts['dns_active'],
            'ssl_active_count' => $healthCounts['ssl_active'],
            'total_checks' => $healthCounts['total_checks'],
            'mode_badge_class' => $this->modeBadgeClass($mode),
            'dns_rows' => $this->dnsRows($group, $primaryRows),
            'health_rows' => $this->healthRows($primaryRows),
        ]);
    }

    private function dnsRows(array $group, array $primaryRows): array
    {
        return array_map(function (array $row) use ($group): array {
            $rowName = (string) ($row['domain_name'] ?? '');
            $displayDomain = (string) ($group['display_domain'] ?? '');
            $target = (string) ($group['cname_target'] ?? $this->cnameTarget);

            return array_merge($row, [
                'record_type' => 'CNAME',
                'record_name' => $this->recordName($rowName, $displayDomain),
                'target' => $target,
            ]);
        }, $primaryRows);
    }

    private function healthRows(array $primaryRows): array
    {
        return array_map(function (array $row): array {
            $hostnameStatus = strtolower((string) ($row['hostname_status'] ?? 'pending'));
            $sslStatus = strtolower((string) ($row['ssl_status'] ?? 'pending_validation'));

            return array_merge($row, [
                'provisioning_status_normalized' => $this->normalizeLifecycle((string) ($row['provisioning_status'] ?? 'active')),
                'hostname_status_normalized' => $hostnameStatus,
                'ssl_status_normalized' => $sslStatus,
                'hostname_status_class' => $this->healthClass($hostnameStatus),
                'ssl_status_class' => $this->healthClass($sslStatus),
                'ssl_status_label' => $sslStatus === 'pending_validation' ? 'pending' : $sslStatus,
            ]);
        }, $primaryRows);
    }

    private function recordName(string $rowName, string $displayDomain): string
    {
        if (str_starts_with($rowName, 'www.')) {
            return 'www';
        }

        if ($rowName === $displayDomain) {
            return $this->isApexLike($rowName) ? '@' : $this->leftmostLabel($rowName);
        }

        return $this->leftmostLabel($rowName);
    }

    private function leftmostLabel(string $hostname): string
    {
        return (string) (explode('.', $hostname)[0] ?? $hostname);
    }

    private function overallStatus(bool $primaryVerified, string $hostnameStatus, string $lifecycleStatus): string
    {
        if ($lifecycleStatus === 'failed') {
            return 'failed';
        }

        if (in_array($lifecycleStatus, ['pending', 'provisioning'], true)) {
            return $lifecycleStatus;
        }

        if ($primaryVerified) {
            return 'active';
        }

        return $hostnameStatus === 'active' ? 'ssl pending' : 'dns pending';
    }

    private function liveStatus(
        string $overallStatus,
        bool $primaryVerified,
        string $hostnameStatus,
        string $sslStatus,
        string $lifecycleStatus,
        string $provisioningError
    ): array {
        $base = [
            'state' => $overallStatus,
            'label' => strtoupper($overallStatus),
            'description' => 'Checking domain setup.',
            'tone' => 'warning',
            'badge_class' => 'border-[#FCB900]/22 bg-[#FCB900]/10 text-[#FFDC9C]',
            'value_class' => 'text-[#D7E1F5]',
            'dot_class' => 'es-pulse-dot-warn',
            'polling' => true,
            'locked' => false,
            'action_label' => null,
        ];

        if ($lifecycleStatus === 'failed') {
            return array_merge($base, [
                'label' => 'ACTION REQUIRED',
                'description' => $provisioningError !== ''
                    ? $provisioningError
                    : 'Domain setup did not finish. Check the setup details or refresh.',
                'tone' => 'danger',
                'badge_class' => 'border-[#D47B78]/32 bg-[#D47B78]/12 text-[#FFE6E3]',
                'value_class' => 'text-[#F3B5AE]',
                'dot_class' => 'es-pulse-dot-danger',
                'polling' => false,
                'locked' => false,
                'action_label' => 'Review setup',
            ]);
        }

        if ($lifecycleStatus === 'pending') {
            return array_merge($base, [
                'label' => 'STARTING',
                'description' => 'Domain setup will start soon.',
                'tone' => 'progress',
                'badge_class' => 'border-[#FCB900]/28 bg-[#FCB900]/12 text-[#FFDC9C]',
                'value_class' => 'text-[#FFDC9C]',
                'dot_class' => 'es-pulse-dot-warn',
                'polling' => true,
                'locked' => true,
            ]);
        }

        if ($lifecycleStatus === 'provisioning') {
            return array_merge($base, [
                'label' => 'SETTING UP',
                'description' => 'Setting up protection in the background.',
                'tone' => 'progress',
                'badge_class' => 'border-[#FCB900]/28 bg-[#FCB900]/12 text-[#FFDC9C]',
                'value_class' => 'text-[#FFDC9C]',
                'dot_class' => 'es-pulse-dot-warn',
                'polling' => true,
                'locked' => true,
            ]);
        }

        if ($primaryVerified) {
            return array_merge($base, [
                'label' => 'PROTECTED',
                'description' => 'DNS, SSL, and protection are active.',
                'tone' => 'success',
                'badge_class' => 'border-[#10B981]/24 bg-[#10B981]/10 text-[#A7F3D0]',
                'value_class' => 'text-[#10B981]',
                'dot_class' => 'es-pulse-dot-active',
                'polling' => false,
                'locked' => false,
            ]);
        }

        if ($hostnameStatus === 'active' && $sslStatus !== 'active') {
            return array_merge($base, [
                'label' => 'SSL PENDING',
                'description' => 'DNS is connected. SSL is still checking.',
                'action_label' => 'Wait for SSL',
            ]);
        }

        return array_merge($base, [
            'label' => 'CHECK DNS',
            'description' => 'Add or fix the DNS record shown on this page.',
            'action_label' => 'Check DNS record',
        ]);
    }

    private function healthCounts(array $primaryRows): array
    {
        $totalChecks = max(count($primaryRows), 1);
        $dnsActive = 0;
        $sslActive = 0;

        foreach ($primaryRows as $row) {
            if (strtolower((string) ($row['hostname_status'] ?? 'pending')) === 'active') {
                $dnsActive++;
            }

            if (strtolower((string) ($row['ssl_status'] ?? 'pending_validation')) === 'active') {
                $sslActive++;
            }
        }

        $dnsProgress = (int) round(($dnsActive / $totalChecks) * 100);
        $sslProgress = (int) round(($sslActive / $totalChecks) * 100);

        return [
            'dns_active' => $dnsActive,
            'ssl_active' => $sslActive,
            'total_checks' => $totalChecks,
            'health_score' => (int) round(($dnsProgress + $sslProgress) / 2),
        ];
    }

    private function modeBadgeClass(string $mode): string
    {
        return match ($mode) {
            'aggressive' => 'border-rose-400/18 bg-rose-400/8 text-rose-200',
            'monitor' => 'border-amber-400/18 bg-amber-400/8 text-amber-200',
            default => 'border-sky-400/18 bg-sky-400/8 text-sky-200',
        };
    }

    private function healthClass(string $status): string
    {
        return $status === 'active'
            ? 'text-emerald-400 border-emerald-500/20 bg-emerald-500/5'
            : 'text-amber-400 border-amber-500/20 bg-amber-500/5';
    }

    private function lifecycleStatus(array $primaryRows): string
    {
        $statuses = array_map(
            fn (array $row): string => $this->normalizeLifecycle((string) ($row['provisioning_status'] ?? 'active')),
            $primaryRows
        );

        if (in_array('failed', $statuses, true)) {
            return 'failed';
        }

        if (in_array('pending', $statuses, true)) {
            return 'pending';
        }

        if (in_array('provisioning', $statuses, true)) {
            return 'provisioning';
        }

        return 'active';
    }

    private function normalizeLifecycle(string $status): string
    {
        $status = strtolower(trim($status));

        return in_array($status, ['pending', 'provisioning', 'active', 'failed'], true) ? $status : 'active';
    }

    private function runtimeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return in_array($status, ['active', 'paused', 'revoked'], true) ? $status : 'active';
    }

    private function displayGroupKey(string $hostname): string
    {
        if (! str_starts_with($hostname, 'www.')) {
            return $hostname;
        }

        $apex = substr($hostname, 4);

        return $this->isApexLike($apex) ? $apex : $hostname;
    }

    private function isApexLike(string $hostname): bool
    {
        $labels = array_values(array_filter(explode('.', $hostname), fn (string $label): bool => $label !== ''));
        if (count($labels) === 2) {
            return true;
        }

        $suffix = implode('.', array_slice($labels, -2));
        $commonSecondLevelSuffixes = [
            'ac.uk',
            'co.il',
            'co.jp',
            'co.nz',
            'co.uk',
            'com.au',
            'com.br',
            'com.eg',
            'com.mx',
            'com.sa',
            'com.tr',
            'com.ua',
            'net.au',
            'net.eg',
            'net.sa',
            'org.au',
            'org.uk',
        ];

        return count($labels) === 3 && in_array($suffix, $commonSecondLevelSuffixes, true);
    }
}
