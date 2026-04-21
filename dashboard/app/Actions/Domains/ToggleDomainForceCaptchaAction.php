<?php

namespace App\Actions\Domains;

use App\Services\EdgeShieldService;

class ToggleDomainForceCaptchaAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(string $domain, int $forceCaptcha, bool $isAdmin, ?string $tenantId): array
    {
        $tenantScope = $this->tenantScopeSql($isAdmin, $tenantId);
        if ($tenantScope === null) {
            return ['ok' => false, 'error' => 'Tenant context is required to update this domain.'];
        }

        $sql = sprintf(
            "UPDATE domain_configs SET force_captcha = %d, updated_at = CURRENT_TIMESTAMP WHERE domain_name = '%s'%s",
            $forceCaptcha,
            str_replace("'", "''", strtolower(trim($domain))),
            $tenantScope
        );

        $result = $this->edgeShield->queryD1($sql);
        if ($result['ok']) {
            $this->edgeShield->purgeDomainConfigCache($domain);
        }

        return $result;
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
