<?php

namespace App\Actions\Domains;

use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use Illuminate\Support\Facades\Schema;

class RefreshDomainGroupVerificationAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

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
            $sync = $this->edgeShield->refreshSaasCustomHostname($hostname);
            if (! $sync['ok']) {
                return ['ok' => false, 'error' => 'We could not refresh this domain yet. Please try again in a few minutes.'];
            }
            $this->edgeShield->purgeDomainConfigCache($hostname);
            $refreshed[] = $hostname;
        }

        return ['ok' => true, 'refreshed' => $refreshed];
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
}
