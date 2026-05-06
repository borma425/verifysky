<?php

namespace App\Actions\Domains;

use App\Services\EdgeShieldService;

class UpdateDomainSecurityModeAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(string $domain, string $securityMode, bool $isAdmin, ?string $tenantId): array
    {
        $tenantScope = $this->tenantScopeSql($isAdmin, $tenantId);
        if ($tenantScope === null) {
            return ['ok' => false, 'error' => 'Please sign in again before changing this domain.'];
        }

        if (! $isAdmin) {
            $readSql = sprintf(
                "SELECT hostname_status, ssl_status FROM domain_configs WHERE domain_name = '%s'%s LIMIT 1",
                str_replace("'", "''", strtolower(trim($domain))),
                $tenantScope
            );
            $read = $this->edgeShield->queryD1($readSql);
            if (! $read['ok']) {
                return ['ok' => false, 'error' => 'We could not verify this domain status. Please try again.'];
            }

            $rows = $this->edgeShield->parseWranglerJson($read['output'])[0]['results'] ?? [];
            $row = is_array($rows[0] ?? null) ? $rows[0] : null;
            $isVerified = $row
                && strtolower((string) ($row['hostname_status'] ?? '')) === 'active'
                && strtolower((string) ($row['ssl_status'] ?? '')) === 'active';
            if (! $isVerified) {
                return ['ok' => false, 'error' => 'Security mode can be changed after the domain is active.'];
            }
        }

        $sql = sprintf(
            "UPDATE domain_configs SET security_mode = '%s', updated_at = CURRENT_TIMESTAMP WHERE domain_name = '%s'%s",
            str_replace("'", "''", $securityMode),
            str_replace("'", "''", strtolower(trim($domain))),
            $tenantScope
        );
        $result = $this->edgeShield->queryD1($sql);
        if ($result['ok']) {
            $this->edgeShield->purgeDomainConfigCache($domain);
        }

        return [
            'ok' => $result['ok'],
            'error' => $result['error'] ?: 'Failed to update security mode',
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
}
