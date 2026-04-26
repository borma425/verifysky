<?php

namespace App\Actions\Domains;

use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use Illuminate\Support\Facades\Schema;

class RefreshDomainVerificationAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(string $domain, ?string $tenantId = null, bool $isAdmin = true): array
    {
        if (! $this->canManageDomain($domain, $tenantId, $isAdmin)) {
            return ['ok' => false, 'error' => 'You do not have access to refresh this domain.'];
        }

        $result = $this->edgeShield->refreshSaasCustomHostname($domain);
        if ($result['ok']) {
            $this->edgeShield->purgeDomainConfigCache($domain);
        }

        return $result;
    }

    private function canManageDomain(string $domain, ?string $tenantId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }

        if (! Schema::hasTable('tenant_domains') || trim((string) $tenantId) === '') {
            return false;
        }

        return TenantDomain::query()
            ->where('tenant_id', trim((string) $tenantId))
            ->where('hostname', strtolower(trim($domain)))
            ->exists();
    }
}
