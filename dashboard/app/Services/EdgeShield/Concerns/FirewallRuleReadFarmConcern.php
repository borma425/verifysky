<?php

namespace App\Services\EdgeShield\Concerns;

use App\Jobs\PurgeRuntimeBundleCache;

trait FirewallRuleReadFarmConcern
{
    public function ensureTable(): void
    {
        throw new \RuntimeException('D1 schema auto-repair was removed from request handling. Run `php artisan edgeshield:d1:schema-sync` before retrying.');
    }

    public function purgeCache(string $domainName): array
    {
        $domain = strtolower(trim($domainName));
        if ($domain !== '') {
            PurgeRuntimeBundleCache::dispatch($domain);
        }

        return ['ok' => true, 'error' => null];
    }

    public function getByDomain(string $domainName): array
    {
        $sql = sprintf(
            "SELECT * FROM custom_firewall_rules WHERE domain_name = '%s' ORDER BY id DESC",
            str_replace("'", "''", strtolower(trim($domainName)))
        );
        $result = $this->d1->query($sql);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load firewall rules.', 'rules' => []];
        }

        $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];

        return ['ok' => true, 'error' => null, 'rules' => $rows];
    }

    public function listPaginated(int $limit = 20, int $offset = 0): array
    {
        $manualFilter = $this->manualFirewallDescriptionFilterSql();
        $result = $this->d1->query(sprintf(
            'SELECT * FROM custom_firewall_rules WHERE %s ORDER BY domain_name ASC, id DESC LIMIT %d OFFSET %d',
            $manualFilter,
            $limit,
            $offset
        ));
        $countResult = $this->d1->query(sprintf('SELECT COUNT(*) as total FROM custom_firewall_rules WHERE %s', $manualFilter));
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load firewall rules for all domains.', 'rules' => [], 'total' => 0];
        }

        $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];
        $total = 0;
        if ($countResult['ok']) {
            $totalRows = $this->d1->parseWranglerJson($countResult['output'])[0]['results'] ?? [];
            $total = $totalRows[0]['total'] ?? 0;
        }

        return ['ok' => true, 'error' => null, 'rules' => $rows, 'total' => $total];
    }

    public function listAll(): array
    {
        return $this->listPaginated(1000, 0);
    }

    public function listForTenant(string $tenantId): array
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            return ['ok' => true, 'error' => null, 'rules' => []];
        }

        $sql = sprintf(
            "SELECT * FROM custom_firewall_rules
             WHERE (
                    tenant_id = '%s'
                    OR (
                        tenant_id IS NULL
                        AND domain_name IN (SELECT domain_name FROM domain_configs WHERE tenant_id = '%s')
                    )
                )
                AND %s
             ORDER BY scope DESC, domain_name ASC, id DESC",
            str_replace("'", "''", $tenantId),
            str_replace("'", "''", $tenantId),
            $this->manualFirewallDescriptionFilterSql()
        );
        $result = $this->d1->query($sql);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load firewall rules for this user.', 'rules' => []];
        }

        $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];

        return ['ok' => true, 'error' => null, 'rules' => $rows];
    }

    public function listIpFarmRules(?string $tenantId = null): array
    {
        $tenantSql = $this->tenantFarmScopeSql($tenantId);
        $result = $this->d1->query("SELECT * FROM custom_firewall_rules WHERE description LIKE '[IP-FARM]%'{$tenantSql} ORDER BY id ASC");
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load blocked IP rules.', 'rules' => []];
        }

        $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];

        return ['ok' => true, 'error' => null, 'rules' => $rows];
    }

    public function getIpFarmStats(?string $tenantId = null): array
    {
        $rulesResult = $this->listIpFarmRules($tenantId);
        if (! $rulesResult['ok']) {
            return ['totalIps' => 0, 'totalRules' => 0, 'lastUpdated' => null];
        }

        $totalIps = 0;
        $lastUpdated = null;
        foreach ($rulesResult['rules'] as $rule) {
            $expr = json_decode($rule['expression_json'] ?? '{}', true);
            if (isset($expr['value']) && is_string($expr['value'])) {
                $totalIps += count(array_filter(array_map('trim', explode(',', $expr['value']))));
            }
            $updatedAt = $rule['updated_at'] ?? $rule['created_at'] ?? null;
            if ($updatedAt && (! $lastUpdated || $updatedAt > $lastUpdated)) {
                $lastUpdated = $updatedAt;
            }
        }

        return ['totalIps' => $totalIps, 'totalRules' => count($rulesResult['rules']), 'lastUpdated' => $lastUpdated];
    }

    public function findIpsInFarm(string $inputValue, string $fieldType = 'ip.src', ?string $tenantId = null): array
    {
        if ($fieldType !== 'ip.src') {
            return [];
        }
        $inputIps = array_filter(array_map('trim', explode(',', strtolower($inputValue))));
        if (empty($inputIps)) {
            return [];
        }

        $farmResult = $this->listIpFarmRules($tenantId);
        if (! $farmResult['ok']) {
            return [];
        }

        $farmIps = [];
        foreach ($farmResult['rules'] as $rule) {
            $expr = json_decode($rule['expression_json'] ?? '{}', true);
            if (($expr['field'] ?? '') === 'ip.src' && isset($expr['value'])) {
                $farmIps = array_merge($farmIps, array_map('trim', explode(',', strtolower((string) $expr['value']))));
            }
        }

        return array_values(array_intersect($inputIps, array_unique($farmIps)));
    }

    public function removeIpsFromFarm(array $ipsToRemove, ?string $tenantId = null): array
    {
        if (empty($ipsToRemove)) {
            return ['ok' => true, 'removed' => 0];
        }

        $ipsToRemove = array_filter(array_map(fn ($ip) => strtolower(trim((string) $ip)), $ipsToRemove));
        if (empty($ipsToRemove)) {
            return ['ok' => true, 'removed' => 0];
        }

        $farmResult = $this->listIpFarmRules($tenantId);
        if (! $farmResult['ok']) {
            return ['ok' => false, 'error' => $farmResult['error'], 'removed' => 0];
        }

        $totalRemoved = 0;
        foreach ($farmResult['rules'] as $rule) {
            $expr = json_decode($rule['expression_json'] ?? '{}', true);
            if (($expr['field'] ?? '') !== 'ip.src') {
                continue;
            }
            $existingIps = array_filter(array_map('trim', explode(',', strtolower((string) ($expr['value'] ?? '')))));
            $remaining = array_values(array_diff($existingIps, $ipsToRemove));
            $removedCount = count($existingIps) - count($remaining);
            if ($removedCount === 0) {
                continue;
            }

            $totalRemoved += $removedCount;
            if (empty($remaining)) {
                $this->d1->query(sprintf('DELETE FROM custom_firewall_rules WHERE id = %d', (int) ($rule['id'] ?? 0)));

                continue;
            }

            $newExpr = json_encode(['field' => 'ip.src', 'operator' => 'in', 'value' => implode(', ', $remaining)]);
            $newDesc = preg_replace('/\(\d+ IPs\)/', '('.count($remaining).' IPs)', (string) ($rule['description'] ?? ''));
            $this->d1->query(sprintf(
                "UPDATE custom_firewall_rules SET expression_json = '%s', description = '%s', updated_at = CURRENT_TIMESTAMP WHERE id = %d",
                str_replace("'", "''", (string) $newExpr),
                str_replace("'", "''", (string) $newDesc),
                (int) ($rule['id'] ?? 0)
            ));
        }

        if ($totalRemoved > 0) {
            $this->purgeCache('global');
            $this->cleanupRemovedFarmIpsAcrossDomains($ipsToRemove, $tenantId);
        }

        return ['ok' => true, 'removed' => $totalRemoved];
    }

    private function cleanupRemovedFarmIpsAcrossDomains(array $ips, ?string $tenantId = null): void
    {
        $ips = array_values(array_filter(
            $ips,
            static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
        ));
        if ($ips === []) {
            return;
        }

        $domains = $this->activeDomainsForRuntimeCleanup($tenantId);
        foreach ($domains as $domain) {
            $this->purgeCache($domain);
            foreach ($ips as $ip) {
                $this->workerAdmin->cleanupIp($domain, $ip);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function activeDomainsForRuntimeCleanup(?string $tenantId = null): array
    {
        $tenantSql = '';
        if ($tenantId !== null && trim($tenantId) !== '') {
            $tenantSql = " AND tenant_id = '".str_replace("'", "''", trim($tenantId))."'";
        }

        $result = $this->d1->query("SELECT domain_name FROM domain_configs WHERE status = 'active'{$tenantSql} ORDER BY domain_name");
        if (! ($result['ok'] ?? false)) {
            return [];
        }

        $rows = $this->d1->parseWranglerJson((string) ($result['output'] ?? ''))[0]['results'] ?? [];

        return array_values(array_filter(array_map(
            static fn (array $row): string => strtolower(trim((string) ($row['domain_name'] ?? ''))),
            is_array($rows) ? $rows : []
        )));
    }

    public function createIpFarmRule(
        string $domainName,
        string $description,
        array $ips,
        bool $paused,
        ?string $tenantId = null,
        string $scope = 'domain'
    ): array {
        $normalized = $this->normalizeIpFarmTargets($ips);
        if (! empty($normalized['invalid'])) {
            return ['ok' => false, 'error' => 'Invalid IP/CIDR target(s): '.implode(', ', array_slice($normalized['invalid'], 0, 8))];
        }

        $targets = array_values(array_diff($normalized['valid'], $this->allIpFarmTargets($tenantId)));
        if ($targets === []) {
            return ['ok' => false, 'error' => 'All provided IPs/CIDRs are already in the blocked IP list.'];
        }

        $created = 0;
        foreach (array_chunk($targets, 500) as $index => $chunk) {
            $label = $this->ipFarmDescription($description, count($chunk), count($targets) > 500 ? $index + 1 : null);
            $result = $this->create(
                $domainName,
                $label,
                'block',
                $this->ipFarmExpression($chunk),
                $paused,
                null,
                $tenantId,
                $scope
            );
            if (! ($result['ok'] ?? false)) {
                return ['ok' => false, 'error' => $result['error'] ?? 'Failed to create blocked IP rule.', 'created' => $created];
            }
            $created++;
        }

        return ['ok' => true, 'created' => $created, 'added' => count($targets), 'error' => null];
    }

    public function appendIpsToFarmRule(int $ruleId, array $ips, ?string $tenantId = null): array
    {
        $rule = $this->getIpFarmRuleOrNull($ruleId, $tenantId);
        if (! $rule) {
            return ['ok' => false, 'error' => 'Blocked IP rule not found.'];
        }

        $normalized = $this->normalizeIpFarmTargets($ips);
        if (! empty($normalized['invalid'])) {
            return ['ok' => false, 'error' => 'Invalid IP/CIDR target(s): '.implode(', ', array_slice($normalized['invalid'], 0, 8))];
        }

        $existingInTenant = $this->allIpFarmTargets($tenantId);
        $newTargets = array_values(array_diff($normalized['valid'], $existingInTenant));
        if ($newTargets === []) {
            return ['ok' => false, 'error' => 'All provided IPs/CIDRs are already in the blocked IP list.'];
        }

        $currentTargets = $this->targetsFromFarmRule($rule);
        $merged = array_values(array_unique(array_merge($currentTargets, $newTargets)));
        $primary = array_slice($merged, 0, 500);
        $overflow = array_slice($merged, 500);
        $description = $this->ipFarmDescription((string) ($rule['description'] ?? ''), count($primary));
        $update = $this->updateFarmRow($ruleId, (string) ($rule['domain_name'] ?? 'global'), $description, $this->ipFarmExpression($primary), (bool) ($rule['paused'] ?? false), (string) ($rule['tenant_id'] ?? ''), (string) ($rule['scope'] ?? 'domain'));
        if (! ($update['ok'] ?? false)) {
            return $update;
        }

        $created = 0;
        foreach (array_chunk($overflow, 500) as $index => $chunk) {
            $create = $this->create(
                (string) ($rule['domain_name'] ?? 'global'),
                $this->ipFarmDescription((string) ($rule['description'] ?? ''), count($chunk), $index + 2),
                'block',
                $this->ipFarmExpression($chunk),
                (bool) ($rule['paused'] ?? false),
                null,
                trim((string) ($rule['tenant_id'] ?? '')) !== '' ? (string) $rule['tenant_id'] : null,
                (string) ($rule['scope'] ?? 'domain')
            );
            if (! ($create['ok'] ?? false)) {
                return ['ok' => false, 'error' => $create['error'] ?? 'Failed to create extra blocked IP rule.', 'added' => count($newTargets), 'created' => $created];
            }
            $created++;
        }

        return ['ok' => true, 'added' => count($newTargets), 'created' => $created, 'error' => null];
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
        $rule = $this->getIpFarmRuleOrNull($ruleId, $tenantId);
        if (! $rule) {
            return ['ok' => false, 'error' => 'Blocked IP rule not found.'];
        }

        $normalized = $this->normalizeIpFarmTargets($ips);
        if (! empty($normalized['invalid'])) {
            return ['ok' => false, 'error' => 'Invalid IP/CIDR target(s): '.implode(', ', array_slice($normalized['invalid'], 0, 8))];
        }
        if ($normalized['valid'] === []) {
            return ['ok' => false, 'error' => 'Blocked IP rule must contain at least one IP/CIDR target.'];
        }

        $primary = array_slice($normalized['valid'], 0, 500);
        $overflow = array_slice($normalized['valid'], 500);
        $update = $this->updateFarmRow(
            $ruleId,
            $domainName,
            $this->ipFarmDescription($description, count($primary)),
            $this->ipFarmExpression($primary),
            $paused,
            $tenantId,
            $scope
        );
        if (! ($update['ok'] ?? false)) {
            return $update;
        }

        $created = 0;
        foreach (array_chunk($overflow, 500) as $index => $chunk) {
            $create = $this->create(
                $domainName,
                $this->ipFarmDescription($description, count($chunk), $index + 2),
                'block',
                $this->ipFarmExpression($chunk),
                $paused,
                null,
                $tenantId,
                $scope
            );
            if (! ($create['ok'] ?? false)) {
                return ['ok' => false, 'error' => $create['error'] ?? 'Failed to create extra blocked IP rule.', 'created' => $created];
            }
            $created++;
        }

        return ['ok' => true, 'updated' => 1, 'created' => $created, 'error' => null];
    }

    public function toggleIpFarmRule(int $ruleId, bool $paused, ?string $tenantId = null): array
    {
        $rule = $this->getIpFarmRuleOrNull($ruleId, $tenantId);
        if (! $rule) {
            return ['ok' => false, 'error' => 'Blocked IP rule not found.'];
        }

        return $this->toggle((string) ($rule['domain_name'] ?? 'global'), $ruleId, $paused);
    }

    public function removeIpsFromFarmRule(int $ruleId, array $ipsToRemove, ?string $tenantId = null): array
    {
        $rule = $this->getIpFarmRuleOrNull($ruleId, $tenantId);
        if (! $rule) {
            return ['ok' => false, 'error' => 'Blocked IP rule not found.', 'removed' => 0];
        }

        $normalized = $this->normalizeIpFarmTargets($ipsToRemove);
        if (! empty($normalized['invalid'])) {
            return ['ok' => false, 'error' => 'Invalid IP/CIDR target(s): '.implode(', ', array_slice($normalized['invalid'], 0, 8)), 'removed' => 0];
        }

        $existing = $this->targetsFromFarmRule($rule);
        $remaining = array_values(array_diff($existing, $normalized['valid']));
        $removed = count($existing) - count($remaining);
        if ($removed === 0) {
            return ['ok' => true, 'removed' => 0, 'error' => null];
        }

        if ($remaining === []) {
            $delete = $this->deleteIpFarmRule($ruleId, $tenantId);

            return ['ok' => (bool) ($delete['ok'] ?? false), 'removed' => $removed, 'error' => $delete['error'] ?? null];
        }

        $description = $this->ipFarmDescription((string) ($rule['description'] ?? ''), count($remaining));
        $update = $this->updateFarmRow($ruleId, (string) ($rule['domain_name'] ?? 'global'), $description, $this->ipFarmExpression($remaining), (bool) ($rule['paused'] ?? false), trim((string) ($rule['tenant_id'] ?? '')) ?: null, (string) ($rule['scope'] ?? 'domain'));

        if (($update['ok'] ?? false) === true) {
            $this->cleanupRemovedFarmIpsAcrossDomains($normalized['valid'], $tenantId);
        }

        return ['ok' => (bool) ($update['ok'] ?? false), 'removed' => $removed, 'error' => $update['error'] ?? null];
    }

    public function deleteIpFarmRule(int $ruleId, ?string $tenantId = null): array
    {
        $rule = $this->getIpFarmRuleOrNull($ruleId, $tenantId);
        if (! $rule) {
            return ['ok' => false, 'error' => 'Blocked IP rule not found.'];
        }

        $targets = $this->targetsFromFarmRule($rule);
        $delete = $this->delete((string) ($rule['domain_name'] ?? 'global'), $ruleId);
        if (($delete['ok'] ?? false) === true) {
            $this->cleanupRemovedFarmIpsAcrossDomains($targets, $tenantId);
        }

        return $delete;
    }

    public function deleteBulkIpFarmRules(array $ruleIds, ?string $tenantId = null): array
    {
        $ids = array_values(array_filter(array_map('intval', $ruleIds), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return ['ok' => true, 'deleted' => 0, 'error' => null];
        }

        $safeIds = [];
        $targets = [];
        foreach ($ids as $id) {
            $rule = $this->getIpFarmRuleOrNull($id, $tenantId);
            if ($rule) {
                $safeIds[] = $id;
                $targets = array_merge($targets, $this->targetsFromFarmRule($rule));
            }
        }
        if ($safeIds === []) {
            return ['ok' => false, 'error' => 'No matching blocked IP rules found.', 'deleted' => 0];
        }

        $delete = $this->deleteBulk($safeIds);
        if (($delete['ok'] ?? false) === true) {
            $this->cleanupRemovedFarmIpsAcrossDomains($targets, $tenantId);
        }

        return ['ok' => (bool) ($delete['ok'] ?? false), 'deleted' => count($safeIds), 'error' => $delete['error'] ?? null];
    }

    private function getIpFarmRuleOrNull(int $ruleId, ?string $tenantId = null): ?array
    {
        $tenantSql = $this->tenantFarmScopeSql($tenantId);
        $result = $this->d1->query(sprintf(
            "SELECT * FROM custom_firewall_rules WHERE id = %d AND description LIKE '[IP-FARM]%%'%s LIMIT 1",
            $ruleId,
            $tenantSql
        ));
        if (! ($result['ok'] ?? false)) {
            return null;
        }

        $rows = $this->d1->parseWranglerJson((string) ($result['output'] ?? ''))[0]['results'] ?? [];

        return is_array($rows[0] ?? null) ? $rows[0] : null;
    }

    private function allIpFarmTargets(?string $tenantId = null): array
    {
        $farmResult = $this->listIpFarmRules($tenantId);
        if (! ($farmResult['ok'] ?? false)) {
            return [];
        }

        $targets = [];
        foreach ($farmResult['rules'] ?? [] as $rule) {
            if (is_array($rule)) {
                $targets = array_merge($targets, $this->targetsFromFarmRule($rule));
            }
        }

        return array_values(array_unique($targets));
    }

    private function targetsFromFarmRule(array $rule): array
    {
        $expr = json_decode((string) ($rule['expression_json'] ?? '{}'), true);
        if (! is_array($expr) || ($expr['field'] ?? '') !== 'ip.src') {
            return [];
        }

        return $this->normalizeIpFarmTargets(preg_split('/[\s,;]+/', (string) ($expr['value'] ?? '')) ?: [])['valid'];
    }

    private function updateFarmRow(int $ruleId, string $domainName, string $description, string $expressionJson, bool $paused, ?string $tenantId, string $scope): array
    {
        $result = $this->d1->query(sprintf(
            "UPDATE custom_firewall_rules
             SET domain_name = '%s', tenant_id = %s, scope = '%s', description = '%s', action = 'block', expression_json = '%s', paused = %d, expires_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = %d",
            str_replace("'", "''", strtolower(trim($domainName))),
            $this->nullableSql($tenantId),
            str_replace("'", "''", $this->normalizeScope($scope, $domainName, $tenantId)),
            str_replace("'", "''", $description),
            str_replace("'", "''", $expressionJson),
            $paused ? 1 : 0,
            $ruleId
        ));
        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Failed to update blocked IP rule.'];
        }
        $this->purgeCache($domainName);

        return ['ok' => true, 'error' => null];
    }

    private function ipFarmExpression(array $targets): string
    {
        return (string) json_encode(['field' => 'ip.src', 'operator' => 'in', 'value' => implode(', ', $targets)]);
    }

    private function ipFarmDescription(string $description, int $count, ?int $chunk = null): string
    {
        $label = trim(preg_replace('/^\[IP-FARM\]\s*/', '', $description) ?? '');
        $label = trim(preg_replace('/\s*\(\d+\s+IPs\)\s*$/', '', $label) ?? '');
        $label = $label !== '' ? $label : 'Manual list';
        if ($chunk !== null) {
            $label .= ' '.$chunk;
        }

        return sprintf('[IP-FARM] %s (%d IPs)', $label, $count);
    }

    /**
     * @param  array<int, mixed>  $targets
     * @return array{valid: array<int, string>, invalid: array<int, string>}
     */
    private function normalizeIpFarmTargets(array $targets): array
    {
        $valid = [];
        $invalid = [];
        foreach ($targets as $target) {
            foreach (preg_split('/[\s,;]+/', strtolower(trim((string) $target))) ?: [] as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') {
                    continue;
                }
                if ($this->isValidIpFarmTarget($candidate)) {
                    $valid[] = $candidate;
                } else {
                    $invalid[] = $candidate;
                }
            }
        }

        return [
            'valid' => array_values(array_unique($valid)),
            'invalid' => array_values(array_unique($invalid)),
        ];
    }

    private function isValidIpFarmTarget(string $target): bool
    {
        if (filter_var($target, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (! str_contains($target, '/')) {
            return false;
        }

        [$ip, $prefix] = array_pad(explode('/', $target, 2), 2, '');
        if (! ctype_digit($prefix) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;
        $prefixInt = (int) $prefix;

        return $prefixInt >= 0 && $prefixInt <= $max;
    }

    private function tenantFarmScopeSql(?string $tenantId): string
    {
        $tenantId = trim((string) $tenantId);
        if ($tenantId === '') {
            return '';
        }

        return " AND tenant_id = '".str_replace("'", "''", $tenantId)."'";
    }

    private function manualFirewallDescriptionFilterSql(): string
    {
        return "(description IS NULL OR description NOT LIKE '[IP-FARM]%')";
    }
}
