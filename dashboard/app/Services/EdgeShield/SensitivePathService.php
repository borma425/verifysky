<?php

namespace App\Services\EdgeShield;

use App\Jobs\PurgeRuntimeBundleCache;

class SensitivePathService
{
    public function __construct(private readonly D1DatabaseClient $d1) {}

    public function ensureTable(): void
    {
        throw new \RuntimeException('D1 schema auto-repair was removed from request handling. Run `php artisan edgeshield:d1:schema-sync` before retrying.');
    }

    public function purgeCache(string $domainName = ''): array
    {
        $domain = strtolower(trim($domainName));
        if ($domain === '') {
            PurgeRuntimeBundleCache::dispatch('global');
        } else {
            PurgeRuntimeBundleCache::dispatch($domain);
        }

        return ['ok' => true, 'error' => null];
    }

    public function list(): array
    {
        $result = $this->d1->query('SELECT * FROM sensitive_paths ORDER BY action ASC, id DESC');
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load sensitive paths.', 'paths' => []];
        }

        $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];

        return ['ok' => true, 'error' => null, 'paths' => $rows];
    }

    public function listForTenant(string $tenantId): array
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            return ['ok' => true, 'error' => null, 'paths' => []];
        }

        $result = $this->d1->query(sprintf(
            "SELECT * FROM sensitive_paths
             WHERE tenant_id = '%s'
                OR (
                    tenant_id IS NULL
                    AND domain_name IN (SELECT domain_name FROM domain_configs WHERE tenant_id = '%s')
                )
             ORDER BY action ASC, id DESC",
            str_replace("'", "''", $tenantId),
            str_replace("'", "''", $tenantId)
        ));
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load sensitive paths.', 'paths' => []];
        }

        $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];

        return ['ok' => true, 'error' => null, 'paths' => $rows];
    }

    public function create(
        string $domainName,
        string $pathPattern,
        string $matchType,
        string $action,
        bool $autoPurge = true,
        ?string $tenantId = null,
        string $scope = 'domain'
    ): array {
        $sql = sprintf(
            "INSERT INTO sensitive_paths (domain_name, tenant_id, scope, path_pattern, match_type, action) VALUES ('%s', %s, '%s', '%s', '%s', '%s')",
            str_replace("'", "''", trim($domainName)),
            $this->nullableSql($tenantId),
            str_replace("'", "''", $this->normalizeScope($scope, $domainName, $tenantId)),
            str_replace("'", "''", trim($pathPattern)),
            str_replace("'", "''", trim($matchType)),
            str_replace("'", "''", trim($action))
        );
        $result = $this->d1->query($sql);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to create sensitive path.'];
        }

        if ($autoPurge) {
            $this->purgeCache($domainName);
        }

        return ['ok' => true, 'error' => null];
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

    public function delete(int $id): array
    {
        $domainsToPurge = $this->domainsForPathIds([$id]);
        $result = $this->d1->query(sprintf('DELETE FROM sensitive_paths WHERE id = %d', $id));
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to delete sensitive path.'];
        }

        foreach ($domainsToPurge as $domain) {
            $this->purgeCache($domain);
        }

        return ['ok' => true, 'error' => null];
    }

    public function deleteBulk(array $pathIds): array
    {
        if (empty($pathIds)) {
            return ['ok' => true, 'error' => null];
        }

        $safeIds = array_map('intval', $pathIds);
        $domainsToPurge = $this->domainsForPathIds($safeIds);
        $result = $this->d1->query(sprintf('DELETE FROM sensitive_paths WHERE id IN (%s)', implode(',', $safeIds)));
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to delete selected sensitive paths.'];
        }

        foreach ($domainsToPurge as $domain) {
            $this->purgeCache($domain);
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * @param  array<int, int>  $pathIds
     * @return array<int, string>
     */
    private function domainsForPathIds(array $pathIds): array
    {
        if ($pathIds === []) {
            return [];
        }

        $safeIds = array_map('intval', $pathIds);
        $result = $this->d1->query(sprintf(
            'SELECT DISTINCT domain_name FROM sensitive_paths WHERE id IN (%s)',
            implode(',', $safeIds)
        ));
        if (! $result['ok']) {
            return ['global'];
        }

        $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];
        $domains = [];
        foreach ($rows as $row) {
            $domain = strtolower(trim((string) ($row['domain_name'] ?? '')));
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains ?: ['global']));
    }
}
