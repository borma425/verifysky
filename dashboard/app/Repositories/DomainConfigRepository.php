<?php

namespace App\Repositories;

use App\Services\EdgeShieldService;

class DomainConfigRepository
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function listForTenant(?string $tenantId, bool $isAdmin): array
    {
        $result = $this->edgeShield->listDomains($tenantId, $isAdmin);
        if (! ($result['ok'] ?? false) || ! is_array($result['domains'] ?? null)) {
            return $result;
        }

        $domains = [];
        foreach ($result['domains'] as $domain) {
            $domains[] = is_array($domain) ? $this->applyLiveDnsRouteStatus($domain) : $domain;
        }
        $result['domains'] = $domains;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $domain
     * @return array<string, mixed>
     */
    private function applyLiveDnsRouteStatus(array $domain): array
    {
        $hostname = strtolower(trim((string) ($domain['domain_name'] ?? '')));
        if ($hostname === '') {
            return $domain;
        }

        $dnsRoute = $this->edgeShield->verifySaasDnsRouteSet(
            $hostname,
            (string) ($domain['cname_target'] ?? '')
        );
        $domain['dns_route_status'] = ($dnsRoute['ok'] ?? false) ? 'active' : 'mismatch';
        $domain['dns_route_error'] = (string) ($dnsRoute['reason'] ?? '');

        if (! ($dnsRoute['ok'] ?? false)) {
            $domain['hostname_status'] = 'pending';
        }

        return $domain;
    }
}
