<?php

namespace App\Actions\Domains;

use App\Models\TenantDomain;
use App\Services\Domains\DomainProvisioningService;
use App\Services\EdgeShieldService;
use Illuminate\Support\Facades\Schema;

class RefreshDomainVerificationAction
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly DomainProvisioningService $domainProvisioning
    ) {}

    public function execute(string $domain, ?string $tenantId = null, bool $isAdmin = true): array
    {
        if (! $this->canManageDomain($domain, $tenantId, $isAdmin)) {
            return ['ok' => false, 'error' => 'You do not have access to refresh this domain.'];
        }

        $result = $this->edgeShield->refreshSaasCustomHostname($domain);
        if ($result['ok']) {
            $this->syncTenantDomain($domain, $result['custom_hostname'] ?? [], $tenantId, $isAdmin);
            $this->edgeShield->purgeDomainConfigCache($domain);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $customHostname
     */
    private function syncTenantDomain(string $domain, array $customHostname, ?string $tenantId, bool $isAdmin): void
    {
        if (! Schema::hasTable('tenant_domains')) {
            return;
        }

        $hostnameStatus = strtolower(trim((string) ($customHostname['status'] ?? 'pending')));
        $sslStatus = strtolower(trim((string) ($customHostname['ssl']['status'] ?? 'pending_validation')));

        $query = TenantDomain::query()
            ->where('hostname', strtolower(trim($domain)));

        if (! $isAdmin) {
            $query->where('tenant_id', trim((string) $tenantId));
        }

        $domain = $query->first();

        if (! $domain instanceof TenantDomain) {
            return;
        }

        $domain->forceFill([
                'cloudflare_custom_hostname_id' => (string) ($customHostname['id'] ?? ''),
                'hostname_status' => $hostnameStatus,
                'ssl_status' => $sslStatus,
                'ownership_verification' => $customHostname['ownership_verification'] ?? null,
                'verified_at' => $hostnameStatus === 'active' && $sslStatus === 'active' ? now() : null,
        ])->save();

        $this->domainProvisioning->markActiveIfVerified($domain->refresh());
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
