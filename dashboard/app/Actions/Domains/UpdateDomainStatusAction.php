<?php

namespace App\Actions\Domains;

use App\Services\EdgeShieldService;

class UpdateDomainStatusAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(string $domain, string $status, bool $isAdmin, ?string $tenantId): array
    {
        $status = strtolower(trim($status));
        if (! in_array($status, ['active', 'paused', 'revoked'], true)) {
            return ['ok' => false, 'error' => 'Invalid domain status.'];
        }

        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return ['ok' => false, 'error' => 'Domain is required.'];
        }

        $tenantScope = $this->tenantScopeSql($isAdmin, $tenantId);
        if ($tenantScope === null) {
            return ['ok' => false, 'error' => 'Please sign in again before changing this domain.'];
        }

        $escapedDomain = str_replace("'", "''", $domain);
        $sql = sprintf(
            "UPDATE domain_configs SET status = '%s', updated_at = CURRENT_TIMESTAMP WHERE domain_name = '%s'%s",
            $status,
            $escapedDomain,
            $tenantScope
        );

        $result = $this->edgeShield->queryD1($sql);
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => $result['error'] ?: 'We could not update the domain status.'];
        }

        $verify = $this->edgeShield->queryD1(sprintf(
            "SELECT status FROM domain_configs WHERE domain_name = '%s'%s LIMIT 1",
            $escapedDomain,
            $tenantScope
        ));
        if (! ($verify['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'We could not verify the domain status after updating it.'];
        }

        $rows = $this->edgeShield->parseWranglerJson($verify['output'])[0]['results'] ?? [];
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;
        if (! $row) {
            return ['ok' => false, 'error' => 'Domain was not found in the runtime configuration.'];
        }

        if (strtolower((string) ($row['status'] ?? '')) !== $status) {
            return ['ok' => false, 'error' => 'Domain status did not change. Please try again.'];
        }

        $this->edgeShield->purgeDomainConfigCache($domain);

        return ['ok' => true, 'error' => null];
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
