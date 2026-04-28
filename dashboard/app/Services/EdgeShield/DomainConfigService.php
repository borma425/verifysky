<?php

namespace App\Services\EdgeShield;

use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\TenantDomain;

class DomainConfigService
{
    public function __construct(private readonly D1DatabaseClient $d1) {}

    public function ensureSecurityModeColumn(): void
    {
        //
    }

    public function ensureThresholdsColumn(): void
    {
        //
    }

    public function ensureSecurityLogsDomainColumn(): void
    {
        //
    }

    public function getDomainConfig(string $domainName, ?string $tenantId = null, bool $isAdmin = true): array
    {
        $tenantScope = $this->tenantScopeSql($isAdmin, $tenantId);
        if ($tenantScope === null) {
            return ['ok' => false, 'error' => 'Tenant context is required to load this domain.', 'config' => null];
        }

        $sql = sprintf(
            "SELECT domain_name, zone_id, status, force_captcha, turnstile_sitekey, turnstile_secret,
                    custom_hostname_id, cname_target, origin_server, hostname_status, ssl_status,
                    ownership_verification_json, thresholds_json, created_at, updated_at
             FROM domain_configs
             WHERE domain_name = '%s'%s
             LIMIT 1",
            str_replace("'", "''", strtolower(trim($domainName))),
            $tenantScope
        );
        $result = $this->d1->query($sql);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load domain config.', 'config' => null];
        }

        $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;
        if (! $row) {
            return ['ok' => false, 'error' => 'Domain not found in configuration.', 'config' => null];
        }

        return ['ok' => true, 'error' => null, 'config' => $row];
    }

    public function listDomains(?string $tenantId = null, bool $isAdmin = true): array
    {
        $tenantScope = $this->tenantScopeSql($isAdmin, $tenantId);
        if ($tenantScope === null) {
            return ['ok' => false, 'error' => 'Tenant context is required to load domains.', 'domains' => []];
        }
        $where = $tenantScope !== '' ? 'WHERE '.ltrim($tenantScope, ' AND') : '';
        $result = $this->d1->query(
            "SELECT domain_name, zone_id, status, force_captcha, security_mode,
                    custom_hostname_id, cname_target, hostname_status, ssl_status,
                    thresholds_json, created_at, updated_at
             FROM domain_configs
             $where
             ORDER BY created_at DESC"
        );
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load domains.', 'domains' => []];
        }

        $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];

        if (! $isAdmin && trim((string) $tenantId) !== '') {
            $rows = $this->mergeTenantVisibilityRows($rows, trim((string) $tenantId));
        }

        return ['ok' => true, 'error' => null, 'domains' => $rows];
    }

    public function listActiveDomainsForRouteSync(): array
    {
        $query = $this->d1->query("SELECT domain_name, zone_id FROM domain_configs WHERE status = 'active' ORDER BY domain_name");
        if (! $query['ok']) {
            return ['ok' => false, 'error' => $query['error'] ?: 'Failed to load active domains', 'rows' => []];
        }

        $rows = $this->d1->parseWranglerJson($query['output'])[0]['results'] ?? [];
        if (! is_array($rows)) {
            return ['ok' => false, 'error' => 'Unexpected D1 response while syncing routes', 'rows' => []];
        }

        return ['ok' => true, 'error' => null, 'rows' => $rows];
    }

    public function updateDomainThresholds(string $domainName, string $json, ?string $tenantId = null, bool $isAdmin = true): array
    {
        $tenantScope = $this->tenantScopeSql($isAdmin, $tenantId);
        if ($tenantScope === null) {
            return ['ok' => false, 'error' => 'Tenant context is required to update this domain.'];
        }
        $sql = sprintf(
            "UPDATE domain_configs SET thresholds_json = '%s', updated_at = CURRENT_TIMESTAMP WHERE domain_name = '%s'%s",
            str_replace("'", "''", trim($json)),
            str_replace("'", "''", strtolower(trim($domainName))),
            $tenantScope
        );
        $result = $this->d1->query($sql);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to update domain thresholds.'];
        }

        $this->purgeDomainConfigCache($domainName);

        return ['ok' => true, 'error' => null];
    }

    public function purgeDomainConfigCache(string $domainName): array
    {
        foreach ($this->domainCacheVariants($domainName) as $domain) {
            PurgeRuntimeBundleCache::dispatch($domain);
        }

        return [
            'ok' => true,
            'error' => null,
        ];
    }

    private function tenantScopeSql(bool $isAdmin, ?string $tenantId): ?string
    {
        if ($isAdmin) {
            return '';
        }

        $tenant = trim((string) $tenantId);
        if ($tenant === '') {
            return null;
        }

        return " AND tenant_id = '".str_replace("'", "''", $tenant)."'";
    }

    private function domainCacheVariants(string $domainName): array
    {
        $domain = strtolower(trim($domainName));
        if ($domain === '') {
            return [];
        }

        $variants = [$domain];
        if (str_starts_with($domain, 'www.')) {
            $variants[] = substr($domain, 4);
        } elseif (count(array_filter(explode('.', $domain))) === 2) {
            $variants[] = 'www.'.$domain;
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function mergeTenantVisibilityRows(array $rows, string $tenantId): array
    {
        $merged = [];

        foreach ($this->localFallbackDomains($tenantId) as $row) {
            $domain = strtolower(trim((string) ($row['domain_name'] ?? '')));
            if ($domain !== '') {
                $merged[$domain] = $row;
            }
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $domain = strtolower(trim((string) ($row['domain_name'] ?? '')));
            if ($domain === '') {
                continue;
            }

            $merged[$domain] = $this->preferPrimaryRow($row, $merged[$domain] ?? []);
        }

        $values = array_values($merged);
        usort($values, function (array $a, array $b): int {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return $values;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function localFallbackDomains(string $tenantId): array
    {
        return TenantDomain::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (TenantDomain $domain): array {
                $hostnameStatus = strtolower(trim((string) ($domain->hostname_status ?? 'pending')));
                $sslStatus = strtolower(trim((string) ($domain->ssl_status ?? 'pending_validation')));

                return [
                    'domain_name' => (string) $domain->hostname,
                    'zone_id' => '',
                    'status' => $hostnameStatus === 'active' && $sslStatus === 'active' ? 'active' : 'pending',
                    'provisioning_status' => (string) ($domain->provisioning_status ?? TenantDomain::PROVISIONING_ACTIVE),
                    'provisioning_error' => (string) ($domain->provisioning_error ?? ''),
                    'provisioning_started_at' => optional($domain->provisioning_started_at)?->toDateTimeString() ?? '',
                    'provisioning_finished_at' => optional($domain->provisioning_finished_at)?->toDateTimeString() ?? '',
                    'force_captcha' => $domain->force_captcha ? 1 : 0,
                    'security_mode' => (string) ($domain->security_mode ?? 'balanced'),
                    'custom_hostname_id' => (string) ($domain->cloudflare_custom_hostname_id ?? ''),
                    'cname_target' => (string) ($domain->cname_target ?? config('edgeshield.saas_cname_target', 'customers.verifysky.com')),
                    'origin_server' => (string) ($domain->origin_server ?? ''),
                    'hostname_status' => $hostnameStatus,
                    'ssl_status' => $sslStatus,
                    'ownership_verification_json' => json_encode($domain->ownership_verification),
                    'thresholds_json' => json_encode($domain->thresholds),
                    'created_at' => optional($domain->created_at)?->toDateTimeString() ?? '',
                    'updated_at' => optional($domain->updated_at)?->toDateTimeString() ?? '',
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function preferPrimaryRow(array $primary, array $fallback): array
    {
        $merged = $fallback;

        foreach ($primary as $key => $value) {
            if (! array_key_exists($key, $merged) || $this->hasMeaningfulValue($value)) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }

}
