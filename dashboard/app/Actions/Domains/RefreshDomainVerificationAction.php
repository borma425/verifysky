<?php

namespace App\Actions\Domains;

use App\Models\TenantDomain;
use App\Services\Domains\RedirectVerificationService;
use App\Services\EdgeShieldService;
use Illuminate\Support\Facades\Schema;

class RefreshDomainVerificationAction
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly RedirectVerificationService $redirectVerification
    ) {}

    public function execute(string $domain, ?string $tenantId = null, bool $isAdmin = true): array
    {
        if (! $this->canManageDomain($domain, $tenantId, $isAdmin)) {
            return ['ok' => false, 'error' => 'You do not have access to refresh this domain.'];
        }

        $result = $this->edgeShield->refreshSaasCustomHostname($domain);
        if ($result['ok']) {
            $this->syncTenantDomain($domain, $result['custom_hostname'] ?? [], $tenantId, $isAdmin);
            $this->refreshRootRedirectStatus($domain, $tenantId, $isAdmin);
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

        $query = TenantDomain::query()->where('hostname', strtolower(trim($domain)));
        if (! $isAdmin) {
            $query->where('tenant_id', trim((string) $tenantId));
        }

        $tenantDomain = $query->first();
        if (! $tenantDomain instanceof TenantDomain) {
            return;
        }

        $hostnameStatus = strtolower(trim((string) ($customHostname['status'] ?? 'pending')));
        $sslStatus = strtolower(trim((string) ($customHostname['ssl']['status'] ?? 'pending_validation')));

        $tenantDomain->forceFill([
            'cloudflare_custom_hostname_id' => (string) ($customHostname['id'] ?? $tenantDomain->cloudflare_custom_hostname_id),
            'hostname_status' => $hostnameStatus,
            'ssl_status' => $sslStatus,
            'ownership_verification' => $customHostname['ownership_verification'] ?? $tenantDomain->ownership_verification,
            'verified_at' => $hostnameStatus === 'active' && $sslStatus === 'active' ? now() : null,
        ])->save();
    }

    private function refreshRootRedirectStatus(string $domain, ?string $tenantId, bool $isAdmin): void
    {
        if (! Schema::hasTable('tenant_domains')) {
            return;
        }

        $tenantDomain = TenantDomain::query()
            ->where('hostname', strtolower(trim($domain)))
            ->when(! $isAdmin, fn ($query) => $query->where('tenant_id', trim((string) $tenantId)))
            ->first();

        if (! $tenantDomain instanceof TenantDomain
            || (string) $tenantDomain->apex_mode !== TenantDomain::APEX_MODE_WWW_REDIRECT
            || trim((string) $tenantDomain->requested_domain) === ''
            || trim((string) $tenantDomain->canonical_hostname) === '') {
            return;
        }

        $result = $this->redirectVerification->verifyRootRedirect((string) $tenantDomain->requested_domain, (string) $tenantDomain->canonical_hostname);
        TenantDomain::query()
            ->where('tenant_id', $tenantDomain->tenant_id)
            ->where('requested_domain', $tenantDomain->requested_domain)
            ->where('canonical_hostname', $tenantDomain->canonical_hostname)
            ->update([
                'apex_redirect_status' => $result['status'],
                'apex_redirect_checked_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function canManageDomain(string $domain, ?string $tenantId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }

        if (! Schema::hasTable('tenant_domains')) {
            return true;
        }

        if (trim((string) $tenantId) === '') {
            return false;
        }

        return TenantDomain::query()
            ->where('tenant_id', trim((string) $tenantId))
            ->where('hostname', strtolower(trim($domain)))
            ->exists();
    }
}
