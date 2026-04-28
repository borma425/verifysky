<?php

namespace App\Actions\Domains;

use App\Models\TenantDomain;
use App\Services\Domains\DnsVerificationService;
use App\Services\Domains\RedirectVerificationService;
use App\Services\EdgeShieldService;
use Illuminate\Support\Facades\Schema;

class RefreshDomainGroupVerificationAction
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly DnsVerificationService $dnsVerification,
        private readonly RedirectVerificationService $redirectVerification
    ) {}

    public function execute(string $domain, ?string $tenantId = null, bool $isAdmin = true): array
    {
        $hostnames = $this->candidateHostnames($domain, $tenantId, $isAdmin);
        if (count($hostnames) === 0) {
            $hostnames = [$domain];
        }

        $hostnames = $this->manageableHostnames($hostnames, $tenantId, $isAdmin);
        if ($hostnames === []) {
            return ['ok' => false, 'error' => 'You do not have access to refresh this domain.'];
        }

        $refreshed = [];
        foreach ($hostnames as $hostname) {
            $sync = $this->edgeShield->refreshSaasCustomHostname($hostname);
            if (! $sync['ok']) {
                return ['ok' => false, 'error' => 'We could not refresh this domain yet. Please try again in a few minutes.'];
            }
            $this->syncTenantDomain($hostname, $sync['custom_hostname'] ?? [], $tenantId, $isAdmin);
            $this->edgeShield->purgeDomainConfigCache($hostname);
            $refreshed[] = $hostname;
        }

        $this->refreshRootRedirectStatus($domain, $tenantId, $isAdmin);

        return ['ok' => true, 'refreshed' => $refreshed];
    }

    /**
     * @return array<int, string>
     */
    private function candidateHostnames(string $domain, ?string $tenantId, bool $isAdmin): array
    {
        $normalized = $this->dnsVerification->normalizeDomain($domain);
        if (! Schema::hasTable('tenant_domains') || $normalized === '') {
            return $this->edgeShield->saasHostnamesForInput($domain);
        }

        $candidates = [$normalized];
        if ($this->dnsVerification->looksLikeApexDomain($normalized)) {
            $candidates[] = 'www.'.$normalized;
        } elseif (str_starts_with($normalized, 'www.')) {
            $candidates[] = substr($normalized, 4);
        }

        $query = TenantDomain::query()
            ->where(function ($query) use ($normalized, $candidates): void {
                $query->whereIn('hostname', array_values(array_unique($candidates)))
                    ->orWhere('requested_domain', $normalized)
                    ->orWhere('canonical_hostname', $normalized);
            });

        if (! $isAdmin) {
            $query->where('tenant_id', trim((string) $tenantId));
        }

        $hostnames = $query->pluck('hostname')
            ->map(static fn (string $hostname): string => strtolower(trim($hostname)))
            ->values()
            ->all();

        return $hostnames !== [] ? $hostnames : $this->edgeShield->saasHostnamesForInput($domain);
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

        $normalized = $this->dnsVerification->normalizeDomain($domain);
        $query = TenantDomain::query()
            ->where('apex_mode', TenantDomain::APEX_MODE_WWW_REDIRECT)
            ->where(function ($query) use ($normalized): void {
                $query->where('requested_domain', $normalized)
                    ->orWhere('canonical_hostname', $normalized)
                    ->orWhere('hostname', $normalized);
            });

        if (! $isAdmin) {
            $query->where('tenant_id', trim((string) $tenantId));
        }

        $rows = $query->get();
        $first = $rows->first();
        if (! $first instanceof TenantDomain || trim((string) $first->requested_domain) === '' || trim((string) $first->canonical_hostname) === '') {
            return;
        }

        $result = $this->redirectVerification->verifyRootRedirect((string) $first->requested_domain, (string) $first->canonical_hostname);
        foreach ($rows as $row) {
            $row->forceFill([
                'apex_redirect_status' => $result['status'],
                'apex_redirect_checked_at' => now(),
            ])->save();
        }
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

        if (! Schema::hasTable('tenant_domains') || trim((string) $tenantId) === '' || $normalized === []) {
            return $isAdmin ? $normalized : [];
        }

        $query = TenantDomain::query()->whereIn('hostname', $normalized);
        if (! $isAdmin) {
            $query->where('tenant_id', trim((string) $tenantId));
        }

        $owned = $query->pluck('hostname')
            ->map(static fn (string $hostname): string => strtolower(trim($hostname)))
            ->values()
            ->all();

        return $isAdmin && $owned === [] ? $normalized : $owned;
    }
}
