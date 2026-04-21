<?php

namespace App\Actions\Domains;

use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use Illuminate\Support\Facades\Schema;

class DeleteDomainGroupAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(string $domain, bool $isAdmin, ?string $tenantId): array
    {
        $hostnames = $this->domainRemovalCandidates($domain);
        if (count($hostnames) === 0) {
            return ['ok' => false, 'error' => 'Domain not found in configuration.'];
        }

        $tenantScope = $this->tenantScopeSql($isAdmin, $tenantId);
        if ($tenantScope === null) {
            return ['ok' => false, 'error' => 'You do not have permission to remove this domain.'];
        }

        $quoted = implode(',', array_map(
            fn (string $hostname): string => "'".str_replace("'", "''", $hostname)."'",
            $hostnames
        ));
        $read = $this->edgeShield->queryD1(
            "SELECT domain_name, zone_id, turnstile_sitekey, custom_hostname_id, hostname_status, ssl_status
             FROM domain_configs
             WHERE domain_name IN ({$quoted}){$tenantScope}"
        );
        if (! $read['ok']) {
            return ['ok' => false, 'error' => 'We could not read this domain before deleting it. Please try again.'];
        }

        $rows = $this->edgeShield->parseWranglerJson($read['output'])[0]['results'] ?? [];
        $rows = array_values(array_filter($rows, fn ($row): bool => is_array($row)));
        if (count($rows) === 0) {
            return ['ok' => false, 'error' => 'Domain not found in configuration.'];
        }

        $cleanupWarnings = [];
        foreach ($rows as $row) {
            $artifactCleanup = $this->edgeShield->removeDomainSecurityArtifacts(
                (string) ($row['zone_id'] ?? ''),
                (string) ($row['domain_name'] ?? 'domain'),
                (string) ($row['turnstile_sitekey'] ?? '')
            );
            $hostnameCleanup = $this->edgeShield->deleteSaasCustomHostname((string) ($row['custom_hostname_id'] ?? ''));
            if (! $artifactCleanup['ok'] || ! $hostnameCleanup['ok']) {
                $cleanupWarnings[] = (string) ($row['domain_name'] ?? 'domain');
            }
        }

        $delete = $this->edgeShield->queryD1("DELETE FROM domain_configs WHERE domain_name IN ({$quoted}){$tenantScope}");
        if (! $delete['ok']) {
            return ['ok' => false, 'error' => 'We could not remove this domain from VerifySky. Please try again.'];
        }

        if ($tenantId && Schema::hasTable('tenant_domains')) {
            TenantDomain::query()
                ->whereIn('hostname', $hostnames)
                ->where('tenant_id', $tenantId)
                ->delete();
        }

        foreach ($hostnames as $hostname) {
            $this->edgeShield->purgeDomainConfigCache($hostname);
        }

        $verify = $this->edgeShield->queryD1("SELECT domain_name FROM domain_configs WHERE domain_name IN ({$quoted}){$tenantScope}");
        $remaining = $verify['ok'] ? ($this->edgeShield->parseWranglerJson($verify['output'])[0]['results'] ?? []) : [];
        if (is_array($remaining) && count($remaining) > 0) {
            return ['ok' => false, 'error' => 'Delete is still pending for this domain. Please refresh and try again.'];
        }

        if (count($cleanupWarnings) > 0) {
            return ['ok' => true, 'warning' => 'Domain removed from VerifySky. Background cleanup will continue.'];
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

    private function isApexLike(string $hostname): bool
    {
        $labels = array_values(array_filter(explode('.', $hostname), fn (string $label): bool => $label !== ''));
        if (count($labels) === 2) {
            return true;
        }

        $suffix = implode('.', array_slice($labels, -2));
        $commonSecondLevelSuffixes = [
            'ac.uk', 'co.il', 'co.jp', 'co.nz', 'co.uk', 'com.au', 'com.br', 'com.eg',
            'com.mx', 'com.sa', 'com.tr', 'com.ua', 'net.au', 'net.eg', 'net.sa', 'org.au', 'org.uk',
        ];

        return count($labels) === 3 && in_array($suffix, $commonSecondLevelSuffixes, true);
    }

    private function domainRemovalCandidates(string $domain): array
    {
        $hostname = strtolower(trim($domain));
        $hostname = preg_replace('#^https?://#', '', $hostname) ?? $hostname;
        $hostname = explode('/', $hostname, 2)[0];
        $hostname = trim($hostname);
        if ($hostname === '') {
            return [];
        }

        if (str_starts_with($hostname, 'www.')) {
            $apex = substr($hostname, 4);

            return $this->isApexLike($apex) ? array_values(array_unique([$apex, $hostname])) : [$hostname];
        }

        return $this->isApexLike($hostname) ? array_values(array_unique([$hostname, 'www.'.$hostname])) : [$hostname];
    }
}
