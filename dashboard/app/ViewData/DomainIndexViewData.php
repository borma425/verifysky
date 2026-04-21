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

        return [
            'domains' => $domains,
            'domainGroups' => $domainGroups,
            'preparedDomainGroups' => $this->prepareDomainGroups($domainGroups),
            'cnameTarget' => $this->cnameTarget,
            'isAdmin' => $this->isAdmin,
            'domains_used' => (int) ($this->domainsUsage['used'] ?? 0),
            'domains_limit' => array_key_exists('limit', $this->domainsUsage) ? $this->domainsUsage['limit'] : null,
            'can_add_domain' => (bool) ($this->domainsUsage['can_add'] ?? true),
            'plan_key' => (string) ($this->domainsUsage['plan_key'] ?? config('plans.default', 'starter')),
            'domains_usage_message' => $this->domainsUsage['message'] ?? null,
            'error' => $this->successful() ? null : ($this->result['error'] ?: 'Failed to load domains'),
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

    private function prepareDomainGroup(array $group): array
    {
        $rows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
        $primaryRows = array_values(array_filter($rows, function ($row) use ($rows): bool {
            return is_array($row)
                && (str_starts_with((string) ($row['domain_name'] ?? ''), 'www.') || count($rows) === 1);
        }));
        $advancedRows = array_values(array_filter($rows, fn ($row): bool => ! in_array($row, $primaryRows, true)));

        $primaryDomain = (string) ($primaryRows[0]['domain_name'] ?? ($group['display_domain'] ?? ''));
        $hostnameStatus = strtolower((string) ($primaryRows[0]['hostname_status'] ?? 'pending'));
        $sslStatus = strtolower((string) ($primaryRows[0]['ssl_status'] ?? 'pending_validation'));
        $primaryVerified = $hostnameStatus === 'active' && $sslStatus === 'active';
        $mode = strtolower((string) ($group['security_mode'] ?? self::DEFAULT_MODE));

        return array_merge($group, [
            'mode' => $mode,
            'primary_rows' => $primaryRows,
            'advanced_rows' => $advancedRows,
            'primary_domain' => $primaryDomain,
            'primary_hostname_status' => $hostnameStatus,
            'primary_ssl_status' => $sslStatus,
            'primary_verified' => $primaryVerified,
            'overall_status' => $this->overallStatus($primaryVerified, $hostnameStatus),
            'overall_badge_class' => $this->overallBadgeClass($primaryVerified),
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
            return '@';
        }

        return explode('.', $rowName)[0];
    }

    private function overallStatus(bool $primaryVerified, string $hostnameStatus): string
    {
        if ($primaryVerified) {
            return 'active';
        }

        return $hostnameStatus === 'active' ? 'ssl pending' : 'dns pending';
    }

    private function overallBadgeClass(bool $primaryVerified): string
    {
        if ($primaryVerified) {
            return 'border-emerald-400/18 bg-emerald-400/8 text-emerald-200';
        }

        return 'border-amber-400/18 bg-amber-400/8 text-amber-200';
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
