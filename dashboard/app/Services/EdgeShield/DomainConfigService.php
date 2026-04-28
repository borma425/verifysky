<?php

namespace App\Services\EdgeShield;

use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\TenantDomain;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DomainConfigService
{
    public function __construct(private readonly D1DatabaseClient $d1) {}

    public function ensureSecurityModeColumn(): void
    {
        throw $this->schemaSyncRequiredException();
    }

    public function ensureThresholdsColumn(): void
    {
        throw $this->schemaSyncRequiredException();
    }

    public function ensureSecurityLogsDomainColumn(): void
    {
        throw $this->schemaSyncRequiredException();
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
                    custom_hostname_id, cname_target, origin_server, hostname_status, ssl_status,
                    thresholds_json, created_at, updated_at
             FROM domain_configs
             $where
             ORDER BY created_at DESC"
        );
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load domains.', 'domains' => []];
        }

        $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];

        return ['ok' => true, 'error' => null, 'domains' => $this->mergeTenantDomainMetadata(is_array($rows) ? $rows : [], $tenantId, $isAdmin)];
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
     * @param  array<int, mixed>  $rows
     * @return array<int, mixed>
     */
    private function mergeTenantDomainMetadata(array $rows, ?string $tenantId, bool $isAdmin): array
    {
        if (! Schema::hasTable('tenant_domains') || $rows === []) {
            return $rows;
        }

        $hostnames = array_values(array_filter(array_map(
            static fn ($row): string => is_array($row) ? strtolower(trim((string) ($row['domain_name'] ?? ''))) : '',
            $rows
        )));
        if ($hostnames === []) {
            return $rows;
        }

        $query = TenantDomain::query()->whereIn('hostname', $hostnames);
        if (! $isAdmin && trim((string) $tenantId) !== '') {
            $query->where('tenant_id', trim((string) $tenantId));
        }

        $metadata = $query->get()->keyBy(fn (TenantDomain $domain): string => strtolower((string) $domain->hostname));

        return array_map(function ($row) use ($metadata) {
            if (! is_array($row)) {
                return $row;
            }

            $domain = $metadata->get(strtolower(trim((string) ($row['domain_name'] ?? ''))));
            if (! $domain instanceof TenantDomain) {
                return $row;
            }

            return array_merge($row, [
                'requested_domain' => $domain->requested_domain,
                'canonical_hostname' => $domain->canonical_hostname,
                'apex_mode' => $domain->apex_mode,
                'dns_provider' => $domain->dns_provider,
                'apex_redirect_status' => $domain->apex_redirect_status,
                'apex_redirect_checked_at' => optional($domain->apex_redirect_checked_at)->toDateTimeString(),
                'origin_server' => $row['origin_server'] ?? $domain->origin_server,
            ]);
        }, $rows);
    }

    private function schemaSyncRequiredException(): RuntimeException
    {
        return new RuntimeException('D1 schema auto-repair was removed from request handling. Run `php artisan edgeshield:d1:schema-sync` before retrying.');
    }
}
