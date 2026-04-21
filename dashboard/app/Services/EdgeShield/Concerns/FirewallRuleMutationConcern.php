<?php

namespace App\Services\EdgeShield\Concerns;

trait FirewallRuleMutationConcern
{
    public function create(
        string $domainName,
        string $description,
        string $action,
        string $expressionJson,
        bool $paused,
        ?int $expiresAt = null,
        ?string $tenantId = null,
        string $scope = 'domain'
    ): array {
        $expiresAtStr = $expiresAt !== null ? (string) $expiresAt : 'NULL';
        $sql = sprintf(
            "INSERT INTO custom_firewall_rules (domain_name, tenant_id, scope, description, action, expression_json, paused, expires_at) VALUES ('%s', %s, '%s', '%s', '%s', '%s', %d, %s)",
            str_replace("'", "''", strtolower(trim($domainName))),
            $this->nullableSql($tenantId),
            str_replace("'", "''", $this->normalizeScope($scope, $domainName, $tenantId)),
            str_replace("'", "''", trim($description)),
            str_replace("'", "''", trim($action)),
            str_replace("'", "''", trim($expressionJson)),
            $paused ? 1 : 0,
            $expiresAtStr
        );
        $result = $this->d1->query($sql);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to create firewall rule.'];
        }
        $this->purgeCache($domainName);

        return ['ok' => true, 'error' => null];
    }

    public function getById(string $domainName, int $ruleId): array
    {
        $sql = sprintf(
            "SELECT * FROM custom_firewall_rules WHERE domain_name = '%s' AND id = %d LIMIT 1",
            str_replace("'", "''", strtolower(trim($domainName))),
            $ruleId
        );

        return $this->getSingle($sql);
    }

    public function getByIdGlobal(int $ruleId): array
    {
        return $this->getSingle(sprintf('SELECT * FROM custom_firewall_rules WHERE id = %d LIMIT 1', $ruleId));
    }

    public function update(
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
        $expiresAtStr = $expiresAt !== null ? (string) $expiresAt : 'NULL';
        $scopeSql = $scope !== null
            ? sprintf(", scope = '%s'", str_replace("'", "''", $this->normalizeScope($scope, $domainName, $tenantId)))
            : '';
        $tenantSql = $tenantId !== null
            ? sprintf(', tenant_id = %s', $this->nullableSql($tenantId))
            : '';
        $sql = sprintf(
            "UPDATE custom_firewall_rules SET description = '%s', action = '%s', expression_json = '%s', paused = %d, expires_at = %s%s%s WHERE domain_name = '%s' AND id = %d",
            str_replace("'", "''", trim($description)),
            str_replace("'", "''", trim($action)),
            str_replace("'", "''", trim($expressionJson)),
            $paused ? 1 : 0,
            $expiresAtStr,
            $tenantSql,
            $scopeSql,
            str_replace("'", "''", strtolower(trim($domainName))),
            $ruleId
        );
        $result = $this->d1->query($sql);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to update firewall rule.'];
        }
        $this->purgeCache($domainName);

        return ['ok' => true, 'error' => null];
    }

    public function deleteExpired(): array
    {
        return $this->d1->query('DELETE FROM custom_firewall_rules WHERE expires_at IS NOT NULL AND expires_at < '.time());
    }

    public function toggle(string $domainName, int $ruleId, bool $paused): array
    {
        $result = $this->d1->query(sprintf(
            "UPDATE custom_firewall_rules SET paused = %d WHERE domain_name = '%s' AND id = %d",
            $paused ? 1 : 0,
            str_replace("'", "''", strtolower(trim($domainName))),
            $ruleId
        ));
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to update firewall rule.'];
        }
        $this->purgeCache($domainName);

        return ['ok' => true, 'error' => null];
    }

    public function delete(string $domainName, int $ruleId): array
    {
        $domainSanitized = str_replace("'", "''", strtolower(trim($domainName)));
        $result = $this->d1->query(sprintf("DELETE FROM custom_firewall_rules WHERE id = %d AND domain_name = '%s'", $ruleId, $domainSanitized));
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to delete custom firewall rule.'];
        }
        $this->purgeCache($domainName);

        return ['ok' => true, 'error' => null];
    }

    public function deleteBulk(array $ruleIds): array
    {
        if (empty($ruleIds)) {
            return ['ok' => true, 'error' => null];
        }

        $safeIds = array_map('intval', $ruleIds);
        $inClause = implode(',', $safeIds);
        $domainsToPurge = [];
        $fetchResult = $this->d1->query(sprintf('SELECT DISTINCT domain_name FROM custom_firewall_rules WHERE id IN (%s)', $inClause));
        if ($fetchResult['ok']) {
            $rows = $this->d1->parseWranglerJson($fetchResult['output'])[0]['results'] ?? [];
            foreach ($rows as $row) {
                if (! empty($row['domain_name'])) {
                    $domainsToPurge[] = (string) $row['domain_name'];
                }
            }
        }

        $result = $this->d1->query(sprintf('DELETE FROM custom_firewall_rules WHERE id IN (%s)', $inClause));
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to delete selected rules.'];
        }
        foreach ($domainsToPurge as $domainName) {
            $this->purgeCache($domainName);
        }

        return ['ok' => true, 'error' => null];
    }

    public function syncKvForFirewallRuleAction(string $domain, string $expressionJson, string $action): void
    {
        $ip = $this->extractIpFromExpression($expressionJson);
        if ($ip === null || trim($domain) === '') {
            return;
        }

        if ($action === 'allow') {
            $this->workerAdmin->revokeAllowIp($domain, $ip);
        } elseif ($action === 'block') {
            $this->workerAdmin->unbanIp($domain, $ip);
        }
    }

    private function extractIpFromExpression(string $expressionJson): ?string
    {
        $decoded = json_decode($expressionJson, true);
        if (! is_array($decoded)) {
            return null;
        }
        $field = trim((string) ($decoded['field'] ?? ''));
        $operator = trim((string) ($decoded['operator'] ?? ''));
        $value = trim((string) ($decoded['value'] ?? ''));

        return ($field === 'ip.src' && $operator === 'eq' && $value !== '' && filter_var($value, FILTER_VALIDATE_IP)) ? $value : null;
    }

    private function getSingle(string $sql): array
    {
        $result = $this->d1->query($sql);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load firewall rule.', 'rule' => null];
        }

        $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;
        if (! $row) {
            return ['ok' => false, 'error' => 'Firewall Rule not found.', 'rule' => null];
        }

        return ['ok' => true, 'error' => null, 'rule' => $row];
    }

    private function nullableSql(?string $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? "'".str_replace("'", "''", $value)."'" : 'NULL';
    }

    private function normalizeScope(string $scope, string $domainName, ?string $tenantId): string
    {
        $scope = strtolower(trim($scope));
        if (in_array($scope, ['domain', 'tenant', 'platform'], true)) {
            return $scope;
        }

        if (strtolower(trim($domainName)) === 'global') {
            return trim((string) $tenantId) !== '' ? 'tenant' : 'platform';
        }

        return 'domain';
    }
}
