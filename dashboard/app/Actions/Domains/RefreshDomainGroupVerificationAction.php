<?php

namespace App\Actions\Domains;

use App\Models\TenantDomain;
use App\Services\Domains\DomainProvisioningService;
use App\Services\EdgeShieldService;
use Illuminate\Support\Facades\Schema;

class RefreshDomainGroupVerificationAction
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly DomainProvisioningService $domainProvisioning
    ) {}

    public function execute(string $domain, ?string $tenantId = null, bool $isAdmin = true): array
    {
        $hostnames = $this->edgeShield->saasHostnamesForInput($domain);
        if (count($hostnames) === 0) {
            $hostnames = [$domain];
        }

        $hostnames = $this->manageableHostnames($hostnames, $tenantId, $isAdmin);
        if ($hostnames === []) {
            return ['ok' => false, 'error' => 'You do not have access to refresh this domain.'];
        }

        $refreshed = [];
        foreach ($hostnames as $hostname) {
            $domainRecord = $this->domainRecord($hostname, $tenantId, $isAdmin);
            if ($domainRecord instanceof TenantDomain) {
                $blocked = $this->notReadyForCloudflareRefresh($domainRecord);
                if ($blocked !== null) {
                    return $blocked;
                }
            }

            $sync = $this->edgeShield->refreshSaasCustomHostname($hostname);
            if (! $sync['ok']) {
                return ['ok' => false, 'error' => 'We could not refresh this domain yet. Please try again in a few minutes.'];
            }
            $domainRecord = $this->syncTenantDomain($hostname, $sync['custom_hostname'] ?? [], $tenantId, $isAdmin);
            if ($domainRecord instanceof TenantDomain) {
                $d1Sync = $this->domainProvisioning->syncVerificationStatusToD1($domainRecord);
                if (! ($d1Sync['ok'] ?? false)) {
                    return [
                        'ok' => false,
                        'error' => $d1Sync['error'] ?: 'Domain status refreshed locally, but runtime sync failed. Please try again.',
                    ];
                }
            }

            $this->edgeShield->purgeDomainConfigCache($hostname);
            $refreshed[] = $hostname;
        }

        return ['ok' => true, 'refreshed' => $refreshed];
    }

    /**
     * @param  array<string, mixed>  $customHostname
     */
    private function syncTenantDomain(string $domain, array $customHostname, ?string $tenantId, bool $isAdmin): ?TenantDomain
    {
        if (! Schema::hasTable('tenant_domains')) {
            return null;
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
            return null;
        }

        $domain->forceFill([
            'cloudflare_custom_hostname_id' => (string) ($customHostname['id'] ?? ''),
            'hostname_status' => $hostnameStatus,
            'ssl_status' => $sslStatus,
            'ownership_verification' => $customHostname['ownership_verification'] ?? null,
            'verified_at' => $hostnameStatus === 'active' && $sslStatus === 'active' ? now() : null,
        ])->save();

        $this->domainProvisioning->markActiveIfVerified($domain->refresh());

        return $domain->refresh();
    }

    /**
     * @param  array<int, string>  $hostnames
     * @return array<int, string>
     */
    private function manageableHostnames(array $hostnames, ?string $tenantId, bool $isAdmin): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (string $hostname): string => strtolower(trim($hostname)),
            $hostnames
        ))));

        if ($isAdmin) {
            return $normalized;
        }

        if (! Schema::hasTable('tenant_domains') || trim((string) $tenantId) === '' || $normalized === []) {
            return [];
        }

        return TenantDomain::query()
            ->where('tenant_id', trim((string) $tenantId))
            ->whereIn('hostname', $normalized)
            ->pluck('hostname')
            ->map(static fn (string $hostname): string => strtolower(trim($hostname)))
            ->values()
            ->all();
    }

    private function domainRecord(string $domain, ?string $tenantId, bool $isAdmin): ?TenantDomain
    {
        if (! Schema::hasTable('tenant_domains')) {
            return null;
        }

        $query = TenantDomain::query()
            ->where('hostname', strtolower(trim($domain)));

        if (! $isAdmin) {
            $query->where('tenant_id', trim((string) $tenantId));
        }

        return $query->first();
    }

    private function notReadyForCloudflareRefresh(TenantDomain $domain): ?array
    {
        if (trim((string) $domain->cloudflare_custom_hostname_id) !== '') {
            return null;
        }

        $status = (string) ($domain->provisioning_status ?? '');
        if ($status === TenantDomain::PROVISIONING_FAILED) {
            return [
                'ok' => false,
                'error' => (string) ($domain->provisioning_error ?: 'Domain setup failed. Update the server/origin and try adding it again.'),
            ];
        }

        if (in_array($status, [TenantDomain::PROVISIONING_PENDING, TenantDomain::PROVISIONING_PROVISIONING], true)) {
            return [
                'ok' => false,
                'error' => 'Domain setup is still running. Please wait a minute, then refresh again.',
            ];
        }

        return null;
    }
}
