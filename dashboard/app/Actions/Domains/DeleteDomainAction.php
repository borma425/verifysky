<?php

namespace App\Actions\Domains;

use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use Illuminate\Support\Facades\Schema;

class DeleteDomainAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(string $domain, bool $isAdmin, ?string $tenantId): array
    {
        $escapedDomain = str_replace("'", "''", strtolower(trim($domain)));
        $tenantScope = $this->tenantScopeSql($isAdmin, $tenantId);
        if ($tenantScope === null) {
            return ['ok' => false, 'error' => 'You do not have permission to remove this domain.'];
        }

        $readSql = sprintf(
            "SELECT domain_name, zone_id, turnstile_sitekey, custom_hostname_id, hostname_status, ssl_status
             FROM domain_configs
             WHERE domain_name = '%s'%s
             LIMIT 1",
            $escapedDomain,
            $tenantScope
        );
        $read = $this->edgeShield->queryD1($readSql);
        if (! $read['ok']) {
            return ['ok' => false, 'error' => $read['error'] ?: 'Failed to read domain config before delete.'];
        }

        $rows = $this->edgeShield->parseWranglerJson($read['output'])[0]['results'] ?? [];
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;
        if (! $row) {
            return ['ok' => false, 'error' => 'Domain not found in configuration.'];
        }

        $artifactCleanup = $this->edgeShield->removeDomainSecurityArtifacts(
            (string) ($row['zone_id'] ?? ''),
            (string) ($row['domain_name'] ?? $domain),
            (string) ($row['turnstile_sitekey'] ?? '')
        );
        $hostnameCleanup = $this->edgeShield->deleteSaasCustomHostname((string) ($row['custom_hostname_id'] ?? ''));
        $deleteSql = sprintf("DELETE FROM domain_configs WHERE domain_name = '%s'%s", $escapedDomain, $tenantScope);
        $result = $this->edgeShield->queryD1($deleteSql);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to remove domain'];
        }

        if ($tenantId && Schema::hasTable('tenant_domains')) {
            TenantDomain::query()
                ->where('hostname', strtolower(trim($domain)))
                ->where('tenant_id', $tenantId)
                ->delete();
        }

        $this->edgeShield->purgeDomainConfigCache($domain);

        if (! $artifactCleanup['ok'] || ! $hostnameCleanup['ok']) {
            return ['ok' => true, 'warning' => 'Domain removed from VerifySky. A background cleanup warning was recorded.'];
        }

        return ['ok' => true];
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
}
