<?php

namespace App\Actions\Domains;

use App\Models\TenantDomain;
use App\Services\EdgeShieldService;

class UpdateDomainOriginAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(string $domain, string $originServer, ?string $tenantId, bool $isAdmin): array
    {
        $normalizedDomain = strtolower(trim($domain));
        $originServer = trim($originServer);
        $tenantScope = $this->tenantScopeSql($isAdmin, $tenantId);
        if ($tenantScope === null) {
            return ['ok' => false, 'error' => 'Please sign in again before changing this domain.'];
        }

        $tenantDomain = TenantDomain::where('hostname', $normalizedDomain);
        if ($tenantId) {
            $tenantDomain->where('tenant_id', $tenantId);
        }
        $tenantDomain = $tenantDomain->first();

        $config = $this->edgeShield->getDomainConfig($normalizedDomain, $tenantId, $isAdmin);
        if (! ($config['ok'] ?? false) || ! is_array($config['config'] ?? null)) {
            return ['ok' => false, 'error' => 'Could not load this domain configuration from VerifySky.'];
        }

        $domainConfig = $config['config'];
        $customHostnameId = trim((string) ($domainConfig['custom_hostname_id'] ?? ($tenantDomain->cloudflare_custom_hostname_id ?? '')));

        $originValidation = $this->edgeShield->validateOriginServerForHostname($normalizedDomain, $originServer);
        if (! ($originValidation['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => $originValidation['error'] ?? 'We could not reach the server for this domain. Enter a valid server IP or domain before continuing.',
            ];
        }

        if ($customHostnameId !== '') {
            $update = $this->edgeShield->updateSaasCustomOrigin($customHostnameId, $originServer);
            if (! $update['ok']) {
                return ['ok' => false, 'error' => 'Failed to route traffic through VerifySky edge: '.$update['error']];
            }
        }

        $sql = sprintf(
            "UPDATE domain_configs SET origin_server = '%s', updated_at = CURRENT_TIMESTAMP WHERE domain_name = '%s'%s",
            str_replace("'", "''", $originServer),
            str_replace("'", "''", $normalizedDomain),
            $tenantScope
        );
        $result = $this->edgeShield->queryD1($sql);
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'Server updated, but VerifySky could not save the new server.'];
        }

        if ($tenantDomain) {
            $tenantDomain->update(['origin_server' => $originServer]);
        }

        $refresh = $this->edgeShield->refreshSaasCustomHostname($normalizedDomain);
        $this->edgeShield->purgeDomainConfigCache($normalizedDomain);

        if (! ($refresh['ok'] ?? false)) {
            return [
                'ok' => true,
                'warning' => 'Server updated, but VerifySky could not refresh the DNS status immediately. Use Refresh status in a few minutes.',
            ];
        }

        return [
            'ok' => true,
            'dns_route' => $refresh['dns_route'] ?? null,
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
