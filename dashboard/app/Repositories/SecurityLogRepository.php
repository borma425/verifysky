<?php

namespace App\Repositories;

use App\Models\DomainAssetHistory;
use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use Illuminate\Support\Facades\Cache;

class SecurityLogRepository
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function fetchIndexPayload(array $filters, ?string $tenantId = null, bool $isAdmin = true): array
    {
        $event = trim((string) ($filters['event_type'] ?? ''));
        $domain = trim((string) ($filters['domain_name'] ?? ''));
        $ipAddress = trim((string) ($filters['ip_address'] ?? ''));
        $includeArchived = $isAdmin && filter_var($filters['include_archived'] ?? false, FILTER_VALIDATE_BOOL);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;
        $cacheVersion = (int) Cache::get('logs_cache_version', 1);
        $accessibleDomains = $this->resolveAccessibleDomains($tenantId, $isAdmin);
        $scopeCacheSuffix = $this->scopeCacheSuffix($accessibleDomains, $isAdmin);

        if (! $isAdmin && $accessibleDomains === []) {
            return $this->emptyTenantPayload($event, $domain, $ipAddress, $page, $perPage);
        }

        $allFarmIps = $this->allFarmIps($cacheVersion, $isAdmin ? null : $tenantId, $scopeCacheSuffix);
        $allAllowedIps = $this->allAllowedIps($cacheVersion, $accessibleDomains, $isAdmin, $scopeCacheSuffix);
        $where = $this->buildWhereClause($event, $domain, $ipAddress, $allFarmIps, $allAllowedIps, $accessibleDomains, $isAdmin, $includeArchived);

        $count = $this->countGroupedRows($where, $cacheVersion, $scopeCacheSuffix);
        $rowsResult = $this->edgeShield->queryD1($this->rowsSql($where, $perPage, $offset));
        $rawRows = ($rowsResult['ok'] ?? false)
            ? ($this->edgeShield->parseWranglerJson((string) ($rowsResult['output'] ?? ''))[0]['results'] ?? [])
            : [];

        return [
            'ok' => ($rowsResult['ok'] ?? false) && $count['ok'],
            'error' => ($rowsResult['error'] ?? '') ?: ($count['error'] ?? ''),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $count['total'],
            'rows' => $rawRows,
            'all_farm_ips' => $allFarmIps,
            'domain_configs' => $this->domainConfigs($cacheVersion, $accessibleDomains, $isAdmin, $scopeCacheSuffix),
            'domain_config_statuses' => $this->domainConfigStatuses($cacheVersion, $accessibleDomains, $isAdmin, $scopeCacheSuffix),
            'domain_lifecycle' => $this->domainLifecycle($cacheVersion, $isAdmin),
            'filter_options' => $this->filterOptions($accessibleDomains, $isAdmin, $includeArchived, $cacheVersion, $scopeCacheSuffix),
            'general_stats' => $this->generalStats($domain, $cacheVersion, $accessibleDomains, $isAdmin, $includeArchived, $scopeCacheSuffix),
            'filters' => [
                'event_type' => $event,
                'domain_name' => $domain,
                'ip_address' => $ipAddress,
                'include_archived' => $includeArchived,
            ],
            'tenant_scoped' => ! $isAdmin,
            'accessible_domains' => $accessibleDomains,
        ];
    }

    public function deleteLogsByIp(string $ip, ?string $domain = null): array
    {
        $clauses = ["ip_address = '".$this->escape($ip)."'"];
        if ($domain !== null && trim($domain) !== '') {
            $clauses[] = "domain_name = '".$this->escape($domain)."'";
        }

        return $this->edgeShield->queryD1('DELETE FROM security_logs WHERE '.implode(' AND ', $clauses));
    }

    public function deleteIpAccessRulesByIp(string $ip, ?string $domain = null): array
    {
        $clauses = ["ip_or_cidr = '".$this->escape($ip)."'"];
        if ($domain !== null) {
            $clauses[] = "domain_name = '".$this->escape($domain)."'";
        }

        return $this->edgeShield->queryD1('DELETE FROM ip_access_rules WHERE '.implode(' AND ', $clauses));
    }

    public function deleteCustomFirewallRulesByIp(string $ip, bool $excludeFarmRules = false, ?string $tenantId = null): array
    {
        $sql = "DELETE FROM custom_firewall_rules WHERE expression_json LIKE '%\\\"".$this->escape($ip)."\\\"%'";
        if ($excludeFarmRules) {
            $sql .= " AND description NOT LIKE '[IP-FARM]%'";
        }
        if ($tenantId !== null && trim($tenantId) !== '') {
            $sql .= " AND tenant_id = '".$this->escape($tenantId)."'";
        }

        return $this->edgeShield->queryD1($sql);
    }

    public function clearLogs(string $period, ?string $tenantId = null, bool $isAdmin = true): array
    {
        $clauses = [];
        if ($period === '30d') {
            $clauses[] = "datetime(created_at) < datetime('now', '-30 days')";
        } elseif ($period === '7d') {
            $clauses[] = "datetime(created_at) < datetime('now', '-7 days')";
        }

        if (! $isAdmin) {
            $domains = $this->resolveAccessibleDomains($tenantId, false);
            if ($domains === []) {
                return ['ok' => true, 'error' => null];
            }
            $clauses[] = 'domain_name IN ('.implode(',', array_map(fn (string $domain): string => "'".$this->escape($domain)."'", $domains)).')';
        }

        $sql = 'DELETE FROM security_logs'.($clauses !== [] ? ' WHERE '.implode(' AND ', $clauses) : '');

        return $this->edgeShield->queryD1($sql);
    }

    public function bumpCacheVersion(): void
    {
        Cache::increment('logs_cache_version');
    }

    private function allFarmIps(int $cacheVersion, ?string $tenantId, string $scopeCacheSuffix): array
    {
        return Cache::remember('logs_all_farm_ips_v7_'.$scopeCacheSuffix.'_'.$cacheVersion, 300, function () use ($tenantId): array {
            $farmResult = $this->edgeShield->listIpFarmRules($tenantId);
            $farmIps = [];
            if (! ($farmResult['ok'] ?? false)) {
                return $farmIps;
            }

            foreach (($farmResult['rules'] ?? []) as $rule) {
                $expr = json_decode((string) ($rule['expression_json'] ?? '{}'), true);
                if (($expr['field'] ?? '') !== 'ip.src' || ! isset($expr['value'])) {
                    continue;
                }
                $farmIps = array_merge($farmIps, array_map('trim', explode(',', strtolower((string) $expr['value']))));
            }

            return array_values(array_unique(array_filter($farmIps)));
        });
    }

    private function allAllowedIps(int $cacheVersion, array $accessibleDomains, bool $isAdmin, string $scopeCacheSuffix): array
    {
        return Cache::remember('logs_all_allowed_ips_v2_'.$scopeCacheSuffix.'_'.$cacheVersion, 300, function () use ($accessibleDomains, $isAdmin): array {
            $scope = $this->customFirewallRuleDomainScope($accessibleDomains, $isAdmin);
            $result = $this->edgeShield->queryD1("SELECT expression_json FROM custom_firewall_rules WHERE action IN ('allow', 'bypass') AND paused = 0{$scope}");
            if (! ($result['ok'] ?? false)) {
                return [];
            }
            $rows = $this->edgeShield->parseWranglerJson((string) ($result['output'] ?? ''))[0]['results'] ?? [];
            $ips = [];
            foreach ($rows as $row) {
                $expr = json_decode((string) ($row['expression_json'] ?? '{}'), true);
                if (($expr['field'] ?? '') !== 'ip.src' || ! isset($expr['value'])) {
                    continue;
                }
                $ips = array_merge($ips, array_map('trim', explode(',', strtolower((string) $expr['value']))));
            }

            return array_values(array_unique(array_filter($ips)));
        });
    }

    private function filterOptions(array $accessibleDomains, bool $isAdmin, bool $includeArchived, int $cacheVersion, string $scopeCacheSuffix): array
    {
        $archiveScope = $includeArchived ? 'with_archived' : 'active_only';

        return Cache::remember('logs_filter_options_v4_'.$scopeCacheSuffix.'_'.$archiveScope.'_'.$cacheVersion, 120, function () use ($accessibleDomains, $isAdmin, $includeArchived): array {
            $result = $this->edgeShield->queryD1($this->filterOptionsSql($accessibleDomains, $isAdmin, $includeArchived));
            if (! ($result['ok'] ?? false)) {
                return ['domains' => $this->filterDomainOptions($accessibleDomains), 'events' => []];
            }
            $rows = $this->edgeShield->parseWranglerJson((string) ($result['output'] ?? ''))[0]['results'] ?? [];
            $domains = $isAdmin ? [] : $this->filterDomainOptions($accessibleDomains);
            $events = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $bucket = trim((string) ($row['bucket'] ?? ''));
                $value = trim((string) ($row['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                if ($isAdmin && $bucket === 'domain') {
                    $domains[] = preg_replace('/^www\./', '', strtolower($value));
                } elseif ($bucket === 'event') {
                    $events[] = $value;
                }
            }

            $importantEvents = ['farm_block', 'temp_block', 'challenge_issued', 'challenge_solved', 'challenge_failed', 'challenge_warning', 'turnstile_failed', 'session_created', 'session_rejected'];
            $events = array_values(array_intersect(array_unique($events), $importantEvents));
            sort($domains);
            sort($events);

            return ['domains' => array_values(array_unique($domains)), 'events' => $events];
        });
    }

    private function domainConfigs(int $cacheVersion, array $accessibleDomains, bool $isAdmin, string $scopeCacheSuffix): array
    {
        return Cache::remember('logs_domain_configs_v4_'.$scopeCacheSuffix.'_'.$cacheVersion, 300, function () use ($accessibleDomains, $isAdmin): array {
            $configsResult = $this->edgeShield->queryD1(
                'SELECT domain_name, thresholds_json FROM domain_configs'.$this->domainConfigsScope($accessibleDomains, $isAdmin)
            );
            if (! ($configsResult['ok'] ?? false)) {
                return [];
            }

            $out = [];
            $configRows = $this->edgeShield->parseWranglerJson((string) ($configsResult['output'] ?? ''))[0]['results'] ?? [];
            foreach ($configRows as $row) {
                $domain = trim((string) ($row['domain_name'] ?? ''));
                $thresholds = json_decode((string) ($row['thresholds_json'] ?? '{}'), true);
                if ($domain !== '' && is_array($thresholds)) {
                    $out[$domain] = $thresholds;
                }
            }

            return $out;
        });
    }

    private function domainConfigStatuses(int $cacheVersion, array $accessibleDomains, bool $isAdmin, string $scopeCacheSuffix): array
    {
        return Cache::remember('logs_domain_config_statuses_v1_'.$scopeCacheSuffix.'_'.$cacheVersion, 300, function () use ($accessibleDomains, $isAdmin): array {
            $configsResult = $this->edgeShield->queryD1(
                'SELECT domain_name, status FROM domain_configs'.$this->domainConfigsScope($accessibleDomains, $isAdmin)
            );
            if (! ($configsResult['ok'] ?? false)) {
                return [];
            }

            $out = [];
            $configRows = $this->edgeShield->parseWranglerJson((string) ($configsResult['output'] ?? ''))[0]['results'] ?? [];
            foreach ($configRows as $row) {
                $domain = trim(strtolower((string) ($row['domain_name'] ?? '')));
                if ($domain !== '') {
                    $out[$domain] = strtolower(trim((string) ($row['status'] ?? '')));
                }
            }

            return $out;
        });
    }

    private function domainLifecycle(int $cacheVersion, bool $isAdmin): array
    {
        if (! $isAdmin) {
            return [];
        }

        return Cache::remember('logs_domain_lifecycle_v1_'.$cacheVersion, 300, function (): array {
            $rows = DomainAssetHistory::query()
                ->whereNotNull('last_removed_at')
                ->orWhereNotNull('quarantined_until')
                ->get(['asset_key', 'registrable_domain', 'hostname', 'quarantined_until', 'last_removed_at']);

            $out = [];
            foreach ($rows as $row) {
                $quarantinedUntil = $row->quarantined_until;
                $state = $quarantinedUntil !== null && $quarantinedUntil->isFuture() ? 'quarantined' : 'archived';
                $label = $state === 'quarantined'
                    ? 'Quarantined until '.$quarantinedUntil->format('Y-m-d').' UTC'
                    : 'Archived';

                foreach ([$row->hostname, $row->registrable_domain, $row->asset_key] as $domain) {
                    $domain = trim(strtolower((string) $domain));
                    if ($domain !== '') {
                        $out[$domain] = ['state' => $state, 'label' => $label];
                    }
                }
            }

            return $out;
        });
    }

    private function countGroupedRows(string $where, int $cacheVersion, string $scopeCacheSuffix): array
    {
        return Cache::remember('logs_count_v6_'.$scopeCacheSuffix.'_'.md5($where).'_'.$cacheVersion, 300, function () use ($where): array {
            $countResult = $this->edgeShield->queryD1(
                "SELECT COUNT(*) AS total_rows FROM (SELECT ip_address FROM security_logs {$where} GROUP BY ip_address, COALESCE(NULLIF(TRIM(domain_name), ''), '-')) grouped_ips"
            );
            if (! ($countResult['ok'] ?? false)) {
                return ['ok' => false, 'total' => 0, 'error' => (string) ($countResult['error'] ?? 'Failed to load logs count.')];
            }
            $rows = $this->edgeShield->parseWranglerJson((string) ($countResult['output'] ?? ''))[0]['results'] ?? [];

            return ['ok' => true, 'total' => (int) ($rows[0]['total_rows'] ?? 0), 'error' => null];
        });
    }

    private function generalStats(string $domain, int $cacheVersion, array $accessibleDomains, bool $isAdmin, bool $includeArchived, string $scopeCacheSuffix): array
    {
        $statsDomainWhere = $this->buildGeneralStatsWhereClause($domain, $accessibleDomains, $isAdmin, $includeArchived);

        return Cache::remember('logs_general_stats_v6_'.$scopeCacheSuffix.'_'.md5($statsDomainWhere).'_'.$cacheVersion, 300, function () use ($statsDomainWhere): array {
            $statsRes = $this->edgeShield->queryD1(
                "SELECT SUM(CASE WHEN event_type IN ('challenge_failed', 'hard_block', 'turnstile_failed', 'replay_detected', 'session_rejected') THEN 1 ELSE 0 END) as total_attacks, SUM(CASE WHEN event_type IN ('challenge_solved', 'session_created') THEN 1 ELSE 0 END) as total_visitors FROM security_logs {$statsDomainWhere} ".($statsDomainWhere ? ' AND ' : ' WHERE ')." datetime(created_at) >= datetime('now', 'start of month')"
            );
            $countriesRes = $this->edgeShield->queryD1(
                'SELECT country, COUNT(*) as attack_count FROM security_logs '.($statsDomainWhere ? $statsDomainWhere.' AND ' : ' WHERE ')." event_type IN ('challenge_failed', 'hard_block', 'turnstile_failed', 'replay_detected', 'session_rejected') AND country IS NOT NULL AND country != '' AND country != 'T1' AND datetime(created_at) >= datetime('now', 'start of month') GROUP BY country ORDER BY attack_count DESC LIMIT 3"
            );
            $statsRow = ($statsRes['ok'] ?? false) ? ($this->edgeShield->parseWranglerJson((string) ($statsRes['output'] ?? ''))[0]['results'][0] ?? []) : [];
            $topCountries = ($countriesRes['ok'] ?? false) ? ($this->edgeShield->parseWranglerJson((string) ($countriesRes['output'] ?? ''))[0]['results'] ?? []) : [];

            return ['total_attacks' => (int) ($statsRow['total_attacks'] ?? 0), 'total_visitors' => (int) ($statsRow['total_visitors'] ?? 0), 'top_countries' => $topCountries];
        });
    }

    private function buildWhereClause(string $event, string $domain, string $ipAddress, array $allFarmIps, array $allAllowedIps, array $accessibleDomains, bool $isAdmin, bool $includeArchived): string
    {
        $filters = [];
        $domainScope = $this->domainScopeFilters($domain, $accessibleDomains, $isAdmin);
        if ($domainScope === false) {
            $filters[] = '1 = 0';
        } elseif ($domainScope !== []) {
            $filters[] = '('.implode(' OR ', $domainScope).')';
        }

        if ($event === 'farm_block') {
            $filters[] = empty($allFarmIps) ? '1 = 0' : "(event_type = 'hard_block' AND ip_address IN (".$this->quotedList($allFarmIps).'))';
        } elseif ($event === 'temp_block') {
            $filters[] = "event_type = 'hard_block'";
            if (! empty($allFarmIps)) {
                $filters[] = 'ip_address NOT IN ('.$this->quotedList($allFarmIps).')';
            }
        } elseif ($event !== '') {
            $filters[] = "event_type = '".$this->escape($event)."'";
        }
        if ($ipAddress !== '') {
            $filters[] = "ip_address = '".$this->escape($ipAddress)."'";
        }
        if (! empty($allAllowedIps)) {
            $filters[] = 'ip_address NOT IN ('.$this->quotedList($allAllowedIps).')';
        }
        if ($isAdmin && ! $includeArchived) {
            $filters[] = "domain_name IN (SELECT domain_name FROM domain_configs WHERE status = 'active')";
        }

        return count($filters) > 0 ? 'WHERE '.implode(' AND ', $filters) : '';
    }

    private function escape(string $value): string
    {
        return str_replace("'", "''", strtolower(trim($value)));
    }

    private function quotedList(array $values): string
    {
        return "'".implode("','", array_map(fn (string $value): string => $this->escape($value), $values))."'";
    }

    private function filterOptionsSql(array $accessibleDomains, bool $isAdmin, bool $includeArchived): string
    {
        if (! $isAdmin) {
            $eventsScope = $this->accessibleDomainListSql($accessibleDomains);

            return "SELECT 'event' AS bucket, event_type AS value FROM (SELECT DISTINCT event_type FROM security_logs WHERE domain_name IN ({$eventsScope}) AND event_type IS NOT NULL AND TRIM(event_type) != '' ORDER BY event_type ASC LIMIT 200)";
        }

        if (! $includeArchived) {
            return "SELECT 'domain' AS bucket, domain_name AS value FROM (SELECT DISTINCT domain_name FROM domain_configs WHERE status = 'active' AND domain_name IS NOT NULL AND TRIM(domain_name) != '') UNION ALL SELECT 'event' AS bucket, event_type AS value FROM (SELECT DISTINCT event_type FROM security_logs WHERE domain_name IN (SELECT domain_name FROM domain_configs WHERE status = 'active') AND event_type IS NOT NULL AND TRIM(event_type) != '' ORDER BY event_type ASC LIMIT 200)";
        }

        return "SELECT 'domain' AS bucket, domain_name AS value FROM (SELECT DISTINCT domain_name FROM domain_configs WHERE domain_name IS NOT NULL AND TRIM(domain_name) != '' UNION SELECT DISTINCT domain_name FROM security_logs WHERE domain_name IS NOT NULL AND TRIM(domain_name) != '') UNION ALL SELECT 'event' AS bucket, event_type AS value FROM (SELECT DISTINCT event_type FROM security_logs WHERE event_type IS NOT NULL AND TRIM(event_type) != '' ORDER BY event_type ASC LIMIT 200)";
    }

    private function rowsSql(string $where, int $perPage, int $offset): string
    {
        return "WITH filtered AS (SELECT * FROM security_logs {$where}), grouped AS (SELECT ip_address, COALESCE(NULLIF(TRIM(domain_name), ''), '-') AS domain_group, COUNT(*) AS requests, MAX(COALESCE(risk_score, 0)) AS max_risk_score, SUM(CASE WHEN datetime(created_at) >= datetime('now', 'start of day') THEN 1 ELSE 0 END) AS requests_today, SUM(CASE WHEN datetime(created_at) >= datetime('now', 'start of day', '-1 day') AND datetime(created_at) < datetime('now', 'start of day') THEN 1 ELSE 0 END) AS requests_yesterday, SUM(CASE WHEN datetime(created_at) >= datetime('now', 'start of month') THEN 1 ELSE 0 END) AS requests_month, SUM(CASE WHEN event_type IN ('challenge_solved', 'session_created') THEN 1 ELSE 0 END) AS solved_or_passed_events, SUM(CASE WHEN event_type IN ('challenge_failed', 'hard_block', 'turnstile_failed', 'replay_detected', 'session_rejected') THEN 1 ELSE 0 END) AS flagged_events, MAX(id) AS latest_id FROM filtered GROUP BY ip_address, COALESCE(NULLIF(TRIM(domain_name), ''), '-')) SELECT g.domain_group AS domain_name, f.event_type, f.risk_score, (SELECT fw.event_type FROM filtered fw WHERE fw.ip_address = g.ip_address AND COALESCE(NULLIF(TRIM(fw.domain_name), ''), '-') = g.domain_group ORDER BY CASE fw.event_type WHEN 'hard_block' THEN 100 WHEN 'replay_detected' THEN 95 WHEN 'challenge_failed' THEN 90 WHEN 'turnstile_failed' THEN 88 WHEN 'session_rejected' THEN 85 WHEN 'challenge_issued' THEN 70 WHEN 'mode_escalated' THEN 65 WHEN 'waf_rule_created' THEN 60 WHEN 'WAF_MERGE_NEW' THEN 60 WHEN 'WAF_MERGE_UPDATED' THEN 58 WHEN 'ai_defense' THEN 55 WHEN 'WAF_MERGE_SKIPPED' THEN 50 WHEN 'challenge_warning' THEN 25 WHEN 'challenge_solved' THEN 20 WHEN 'session_created' THEN 10 ELSE 30 END DESC, COALESCE(fw.risk_score, 0) DESC, fw.id DESC LIMIT 1) AS worst_event_type, (SELECT COALESCE(fw.risk_score, 0) FROM filtered fw WHERE fw.ip_address = g.ip_address AND COALESCE(NULLIF(TRIM(fw.domain_name), ''), '-') = g.domain_group ORDER BY CASE fw.event_type WHEN 'hard_block' THEN 100 WHEN 'replay_detected' THEN 95 WHEN 'challenge_failed' THEN 90 WHEN 'turnstile_failed' THEN 88 WHEN 'session_rejected' THEN 85 WHEN 'challenge_issued' THEN 70 WHEN 'mode_escalated' THEN 65 WHEN 'waf_rule_created' THEN 60 WHEN 'WAF_MERGE_NEW' THEN 60 WHEN 'WAF_MERGE_UPDATED' THEN 58 WHEN 'ai_defense' THEN 55 WHEN 'WAF_MERGE_SKIPPED' THEN 50 WHEN 'challenge_warning' THEN 25 WHEN 'challenge_solved' THEN 20 WHEN 'session_created' THEN 10 ELSE 30 END DESC, COALESCE(fw.risk_score, 0) DESC, fw.id DESC LIMIT 1) AS worst_event_score, f.ip_address, f.asn, f.country, f.target_path, (SELECT json_group_array(path_row.target_path) FROM (SELECT COALESCE(NULLIF(TRIM(fp.target_path), ''), '-') AS target_path FROM filtered fp WHERE fp.ip_address = g.ip_address AND COALESCE(NULLIF(TRIM(fp.domain_name), ''), '-') = g.domain_group ORDER BY fp.id DESC LIMIT 50) path_row) AS recent_paths_json, f.details, f.created_at, g.requests, g.max_risk_score, g.requests_today, g.requests_yesterday, g.requests_month, g.solved_or_passed_events, g.flagged_events FROM grouped g JOIN filtered f ON f.id = g.latest_id ORDER BY g.requests_today DESC, g.requests_yesterday DESC, g.requests_month DESC, g.latest_id DESC LIMIT {$perPage} OFFSET {$offset}";
    }

    private function resolveAccessibleDomains(?string $tenantId, bool $isAdmin): array
    {
        if ($isAdmin) {
            return [];
        }

        $normalizedTenantId = trim((string) $tenantId);
        if ($normalizedTenantId === '') {
            return [];
        }

        return TenantDomain::query()
            ->where('tenant_id', $normalizedTenantId)
            ->orderBy('id')
            ->pluck('hostname')
            ->map(fn (string $hostname): string => strtolower(trim($hostname)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function scopeCacheSuffix(array $accessibleDomains, bool $isAdmin): string
    {
        if ($isAdmin) {
            return 'admin';
        }

        return 'tenant_'.md5(implode('|', $accessibleDomains));
    }

    private function emptyTenantPayload(string $event, string $domain, string $ipAddress, int $page, int $perPage): array
    {
        return [
            'ok' => true,
            'error' => null,
            'page' => $page,
            'per_page' => $perPage,
            'total' => 0,
            'rows' => [],
            'all_farm_ips' => [],
            'domain_configs' => [],
            'domain_config_statuses' => [],
            'domain_lifecycle' => [],
            'filter_options' => ['domains' => [], 'events' => []],
            'general_stats' => ['total_attacks' => 0, 'total_visitors' => 0, 'top_countries' => []],
            'filters' => [
                'event_type' => $event,
                'domain_name' => $domain,
                'ip_address' => $ipAddress,
                'include_archived' => false,
            ],
            'tenant_scoped' => true,
            'accessible_domains' => [],
        ];
    }

    private function domainConfigsScope(array $accessibleDomains, bool $isAdmin): string
    {
        if ($isAdmin) {
            return '';
        }

        return ' WHERE domain_name IN ('.$this->accessibleDomainListSql($accessibleDomains).')';
    }

    private function customFirewallRuleDomainScope(array $accessibleDomains, bool $isAdmin): string
    {
        if ($isAdmin) {
            return '';
        }

        return " AND (domain_name = 'global' OR domain_name IN (".$this->accessibleDomainListSql($accessibleDomains).'))';
    }

    private function buildGeneralStatsWhereClause(string $domain, array $accessibleDomains, bool $isAdmin, bool $includeArchived): string
    {
        $scope = $this->domainScopeFilters($domain, $accessibleDomains, $isAdmin);
        if ($scope === false) {
            return 'WHERE 1 = 0';
        }

        if ($isAdmin && ! $includeArchived) {
            $scope[] = "domain_name IN (SELECT domain_name FROM domain_configs WHERE status = 'active')";
        }

        if ($scope === []) {
            return '';
        }

        return 'WHERE ('.implode(' OR ', $scope).')';
    }

    /**
     * @return array<int, string>|false
     */
    private function domainScopeFilters(string $domain, array $accessibleDomains, bool $isAdmin): array|false
    {
        if ($isAdmin) {
            if ($domain === '') {
                return [];
            }

            return $this->domainFilterExpressions($this->matchingDomainsForFilter($domain, []));
        }

        $matchingDomains = $this->matchingDomainsForFilter($domain, $accessibleDomains);
        if ($matchingDomains === []) {
            return false;
        }

        return $this->domainFilterExpressions($matchingDomains);
    }

    /**
     * @param  array<int, string>  $domains
     * @return array<int, string>
     */
    private function domainFilterExpressions(array $domains): array
    {
        return array_map(
            fn (string $domain): string => "domain_name = '".$this->escape($domain)."'",
            $domains
        );
    }

    /**
     * @param  array<int, string>  $accessibleDomains
     * @return array<int, string>
     */
    private function matchingDomainsForFilter(string $domain, array $accessibleDomains): array
    {
        $normalizedDomain = trim(strtolower($domain));
        if ($normalizedDomain === '') {
            return $accessibleDomains;
        }

        if ($accessibleDomains === []) {
            $base = preg_replace('/^www\./', '', $normalizedDomain);

            return array_values(array_unique(array_filter([
                (string) $base,
                str_starts_with($normalizedDomain, 'www.') ? $normalizedDomain : 'www.'.(string) $base,
            ])));
        }

        return array_values(array_filter($accessibleDomains, static function (string $hostname) use ($normalizedDomain): bool {
            return $hostname === $normalizedDomain
                || preg_replace('/^www\./', '', $hostname) === preg_replace('/^www\./', '', $normalizedDomain);
        }));
    }

    /**
     * @param  array<int, string>  $accessibleDomains
     * @return array<int, string>
     */
    private function filterDomainOptions(array $accessibleDomains): array
    {
        $domains = array_map(
            static fn (string $domain): string => preg_replace('/^www\./', '', $domain) ?? $domain,
            $accessibleDomains
        );

        sort($domains);

        return array_values(array_unique(array_filter($domains)));
    }

    /**
     * @param  array<int, string>  $accessibleDomains
     */
    private function accessibleDomainListSql(array $accessibleDomains): string
    {
        return $this->quotedList($accessibleDomains);
    }
}
