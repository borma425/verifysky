<?php

namespace App\Services\EdgeShield\Concerns;

trait EdgeShieldDomainAndSaasFacade
{
    public function projectRoot(): string
    {
        return $this->config->projectRoot();
    }

    public function wranglerBin(): string
    {
        return $this->config->wranglerBin();
    }

    public function nodeBinDir(): ?string
    {
        return $this->config->nodeBinDir();
    }

    public function saasZoneId(): ?string
    {
        return $this->config->saasZoneId();
    }

    public function saasCnameTarget(): string
    {
        return $this->config->saasCnameTarget();
    }

    public function autoProvisionDomainConfig(
        string $domainName,
        ?string $zoneId = null,
        ?string $turnstileSiteKey = null,
        ?string $turnstileSecret = null
    ): array {
        return $this->saasHostnames->autoProvisionDomainConfig($domainName, $zoneId, $turnstileSiteKey, $turnstileSecret);
    }

    public function updateSaasCustomOrigin(string $hostnameId, string $originServer): array
    {
        return $this->saasHostnames->updateSaasCustomOrigin($hostnameId, $originServer);
    }

    public function provisionSaasCustomHostname(string $domainName, string $originServer): array
    {
        return $this->saasHostnames->provisionSaasCustomHostname($domainName, $originServer);
    }

    public function detectOriginServerForInput(string $domainName): array
    {
        return $this->saasHostnames->detectOriginServerForInput($domainName);
    }

    public function validateOriginServerForHostname(string $domainName, string $originServer): array
    {
        return $this->saasHostnames->validateOriginServerForHostname($domainName, $originServer);
    }

    public function saasHostnamesForInput(string $domainName): array
    {
        return $this->saasHostnames->saasHostnamesForInput($domainName);
    }

    public function refreshSaasCustomHostname(string $domainName): array
    {
        return $this->saasHostnames->refreshSaasCustomHostname($domainName);
    }

    public function verifySaasDnsRoute(string $domainName, ?string $expectedTarget = null): array
    {
        return $this->saasHostnames->verifySaasDnsRoute($domainName, $expectedTarget);
    }

    public function deleteSaasCustomHostname(string $customHostnameId): array
    {
        return $this->saasHostnames->deleteSaasCustomHostname($customHostnameId);
    }

    public function removeDomainSecurityArtifacts(string $zoneId, string $domainName, ?string $turnstileSiteKey = null): array
    {
        return $this->saasHostnames->removeDomainSecurityArtifacts($zoneId, $domainName, $turnstileSiteKey);
    }

    public function ensureWorkerRoute(string $zoneId, string $domainName): array
    {
        if (! $this->config->allowsCloudflareMutations()) {
            return ['ok' => false, 'error' => $this->config->mutationBlockedError()];
        }

        $cacheRuleResult = $this->ensureCacheRuleForEdgeShield($zoneId, $domainName);

        return $this->workerRoutes->ensureWorkerRoute($zoneId, $domainName, $cacheRuleResult);
    }

    public function ensureWorkerRouteOnly(string $zoneId, string $domainName): array
    {
        return $this->workerRoutes->ensureWorkerRoute($zoneId, $domainName);
    }

    public function removeWorkerRoutes(string $zoneId, string $domainName): array
    {
        return $this->workerRoutes->removeWorkerRoutes($zoneId, $domainName);
    }

    public function syncAllActiveDomainRoutes(): array
    {
        $query = $this->domains->listActiveDomainsForRouteSync();
        if (! $query['ok']) {
            return ['ok' => false, 'error' => $query['error'], 'synced' => []];
        }

        $synced = [];
        $errors = [];
        foreach ($query['rows'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $domain = trim((string) ($row['domain_name'] ?? ''));
            $zoneId = trim((string) ($row['zone_id'] ?? ''));
            if ($domain === '' || $zoneId === '') {
                continue;
            }

            $result = $this->ensureWorkerRoute($zoneId, $domain);
            if ($result['ok']) {
                $synced[] = $domain.': '.($result['action'] ?? 'synced');
            } else {
                $errors[] = $domain.': '.($result['error'] ?? 'sync failed');
            }
        }

        return ['ok' => count($errors) === 0, 'error' => count($errors) ? implode(' | ', $errors) : null, 'synced' => $synced];
    }

    public function ensureSecurityModeColumn(): void
    {
        $this->domains->ensureSecurityModeColumn();
    }

    public function ensureThresholdsColumn(): void
    {
        $this->domains->ensureThresholdsColumn();
    }

    public function ensureSecurityLogsDomainColumn(): void
    {
        $this->domains->ensureSecurityLogsDomainColumn();
    }

    public function getDomainConfig(string $domainName, ?string $tenantId = null, bool $isAdmin = true): array
    {
        return $this->domains->getDomainConfig($domainName, $tenantId, $isAdmin);
    }

    public function listDomains(?string $tenantId = null, bool $isAdmin = true): array
    {
        return $this->domains->listDomains($tenantId, $isAdmin);
    }

    public function updateDomainThresholds(string $domainName, string $json, ?string $tenantId = null, bool $isAdmin = true): array
    {
        return $this->domains->updateDomainThresholds($domainName, $json, $tenantId, $isAdmin);
    }

    public function purgeDomainConfigCache(string $domainName): array
    {
        return $this->domains->purgeDomainConfigCache($domainName);
    }

    public function listZoneWorkerRoutes(string $zoneId): array
    {
        return $this->workerRoutes->listZoneWorkerRoutes($zoneId);
    }

    public function ensureCacheRuleForEdgeShield(string $zoneId, string $triggeringDomain): array
    {
        if (! $this->config->allowsCloudflareMutations()) {
            return ['ok' => false, 'error' => $this->config->mutationBlockedError()];
        }

        return $this->saasSecurity->ensureCacheRuleForEdgeShield($zoneId, $triggeringDomain);
    }

    public function ensureSaasFallbackBypassRules(): array
    {
        if (! $this->config->allowsCloudflareMutations()) {
            return ['ok' => false, 'error' => $this->config->mutationBlockedError()];
        }

        return $this->saasSecurity->ensureSaasFallbackBypassRules();
    }

    public function ensureSaasBotManagementSettings(): array
    {
        if (! $this->config->allowsCloudflareMutations()) {
            return ['ok' => false, 'error' => $this->config->mutationBlockedError()];
        }

        return $this->saasSecurity->ensureSaasBotManagementSettings();
    }

    public function syncCloudflareFromDashboardSettings(): array
    {
        if (! $this->config->allowsCloudflareMutations()) {
            return ['ok' => false, 'error' => $this->config->mutationBlockedError()];
        }

        return $this->secretSync->syncFromDashboardSettings();
    }
}
