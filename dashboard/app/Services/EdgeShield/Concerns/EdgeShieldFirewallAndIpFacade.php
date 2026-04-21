<?php

namespace App\Services\EdgeShield\Concerns;

trait EdgeShieldFirewallAndIpFacade
{
    public function ensureIpAccessRulesTable(): void
    {
        $this->ipRules->ensureTable();
    }

    public function ensureCustomFirewallRulesTable(): void
    {
        $this->firewallRules->ensureTable();
    }

    public function ensureSensitivePathsTable(): void
    {
        $this->sensitivePaths->ensureTable();
    }

    public function purgeSensitivePathsCache(string $domainName = ''): array
    {
        return $this->sensitivePaths->purgeCache($domainName);
    }

    public function listSensitivePaths(): array
    {
        return $this->sensitivePaths->list();
    }

    public function listTenantSensitivePaths(string $tenantId): array
    {
        return $this->sensitivePaths->listForTenant($tenantId);
    }

    public function createSensitivePath(
        string $domainName,
        string $pathPattern,
        string $matchType,
        string $action,
        bool $autoPurge = true,
        ?string $tenantId = null,
        string $scope = 'domain'
    ): array {
        return $this->sensitivePaths->create($domainName, $pathPattern, $matchType, $action, $autoPurge, $tenantId, $scope);
    }

    public function deleteSensitivePath(int $id): array
    {
        return $this->sensitivePaths->delete($id);
    }

    public function deleteBulkSensitivePaths(array $pathIds): array
    {
        return $this->sensitivePaths->deleteBulk($pathIds);
    }

    public function purgeCustomFirewallRulesCache(string $domainName): array
    {
        return $this->firewallRules->purgeCache($domainName);
    }

    public function getCustomFirewallRules(string $domainName): array
    {
        return $this->firewallRules->getByDomain($domainName);
    }

    public function listPaginatedCustomFirewallRules(int $limit = 20, int $offset = 0): array
    {
        return $this->firewallRules->listPaginated($limit, $offset);
    }

    public function listAllCustomFirewallRules(): array
    {
        return $this->firewallRules->listAll();
    }

    public function listTenantCustomFirewallRules(string $tenantId): array
    {
        return $this->firewallRules->listForTenant($tenantId);
    }

    public function listIpFarmRules(?string $tenantId = null): array
    {
        return $this->firewallRules->listIpFarmRules($tenantId);
    }

    public function getIpFarmStats(?string $tenantId = null): array
    {
        return $this->firewallRules->getIpFarmStats($tenantId);
    }

    public function findIpsInFarm(string $inputValue, string $fieldType = 'ip.src', ?string $tenantId = null): array
    {
        return $this->firewallRules->findIpsInFarm($inputValue, $fieldType, $tenantId);
    }

    public function removeIpsFromFarm(array $ipsToRemove, ?string $tenantId = null): array
    {
        return $this->firewallRules->removeIpsFromFarm($ipsToRemove, $tenantId);
    }

    public function createIpFarmRule(
        string $domainName,
        string $description,
        array $ips,
        bool $paused,
        ?string $tenantId = null,
        string $scope = 'domain'
    ): array {
        return $this->firewallRules->createIpFarmRule($domainName, $description, $ips, $paused, $tenantId, $scope);
    }

    public function appendIpsToFarmRule(int $ruleId, array $ips, ?string $tenantId = null): array
    {
        return $this->firewallRules->appendIpsToFarmRule($ruleId, $ips, $tenantId);
    }

    public function updateIpFarmRule(
        int $ruleId,
        string $domainName,
        string $description,
        array $ips,
        bool $paused,
        ?string $tenantId = null,
        string $scope = 'domain'
    ): array {
        return $this->firewallRules->updateIpFarmRule($ruleId, $domainName, $description, $ips, $paused, $tenantId, $scope);
    }

    public function toggleIpFarmRule(int $ruleId, bool $paused, ?string $tenantId = null): array
    {
        return $this->firewallRules->toggleIpFarmRule($ruleId, $paused, $tenantId);
    }

    public function removeIpsFromFarmRule(int $ruleId, array $ipsToRemove, ?string $tenantId = null): array
    {
        return $this->firewallRules->removeIpsFromFarmRule($ruleId, $ipsToRemove, $tenantId);
    }

    public function deleteIpFarmRule(int $ruleId, ?string $tenantId = null): array
    {
        return $this->firewallRules->deleteIpFarmRule($ruleId, $tenantId);
    }

    public function deleteBulkIpFarmRules(array $ruleIds, ?string $tenantId = null): array
    {
        return $this->firewallRules->deleteBulkIpFarmRules($ruleIds, $tenantId);
    }

    public function createCustomFirewallRule(
        string $domainName,
        string $description,
        string $action,
        string $expressionJson,
        bool $paused,
        ?int $expiresAt = null,
        ?string $tenantId = null,
        string $scope = 'domain'
    ): array {
        return $this->firewallRules->create($domainName, $description, $action, $expressionJson, $paused, $expiresAt, $tenantId, $scope);
    }

    public function getCustomFirewallRuleById(string $domainName, int $ruleId): array
    {
        return $this->firewallRules->getById($domainName, $ruleId);
    }

    public function getCustomFirewallRuleByIdGlobal(int $ruleId): array
    {
        return $this->firewallRules->getByIdGlobal($ruleId);
    }

    public function updateCustomFirewallRule(
        string $domainName,
        int $ruleId,
        string $description,
        string $action,
        string $expressionJson,
        bool $paused,
        ?int $expiresAt = null,
        ?string $tenantId = null,
        ?string $scope = null
    ): array {
        return $this->firewallRules->update($domainName, $ruleId, $description, $action, $expressionJson, $paused, $expiresAt, $tenantId, $scope);
    }

    public function deleteExpiredCustomFirewallRules(): array
    {
        return $this->firewallRules->deleteExpired();
    }

    public function toggleCustomFirewallRule(string $domainName, int $ruleId, bool $paused): array
    {
        return $this->firewallRules->toggle($domainName, $ruleId, $paused);
    }

    public function deleteCustomFirewallRule(string $domainName, int $ruleId): array
    {
        return $this->firewallRules->delete($domainName, $ruleId);
    }

    public function deleteBulkCustomFirewallRules(array $ruleIds): array
    {
        return $this->firewallRules->deleteBulk($ruleIds);
    }

    public function listIpAccessRules(string $domainName): array
    {
        return $this->ipRules->list($domainName);
    }

    public function listAllIpAccessRules(): array
    {
        return $this->ipRules->listAll();
    }

    public function purgeIpRulesCache(string $domainName): array
    {
        return $this->ipRules->purgeCache($domainName);
    }

    public function createIpAccessRule(string $domainName, string $ipOrCidr, string $action, ?string $note): array
    {
        return $this->ipRules->create($domainName, $ipOrCidr, $action, $note);
    }

    public function getIpAccessRuleById(string $domainName, int $ruleId): array
    {
        return $this->ipRules->getById($domainName, $ruleId);
    }

    public function updateIpAccessRule(string $domainName, int $ruleId, string $ipOrCidr, string $action, ?string $note): array
    {
        return $this->ipRules->update($domainName, $ruleId, $ipOrCidr, $action, $note);
    }

    public function deleteIpAccessRule(string $domainName, int $ruleId): array
    {
        return $this->ipRules->delete($domainName, $ruleId);
    }

    public function syncKvForFirewallRuleAction(string $domain, string $expressionJson, string $action): void
    {
        $this->firewallRules->syncKvForFirewallRuleAction($domain, $expressionJson, $action);
    }
}
