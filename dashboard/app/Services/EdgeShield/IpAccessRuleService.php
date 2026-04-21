<?php

namespace App\Services\EdgeShield;

use App\Jobs\PurgeRuntimeBundleCache;

class IpAccessRuleService
{
    public function __construct(private readonly D1DatabaseClient $d1) {}

    public function ensureTable(): void
    {
        throw new \RuntimeException('D1 schema auto-repair was removed from request handling. Run `php artisan edgeshield:d1:schema-sync` before retrying.');
    }

    public function list(string $domainName): array
    {
        $sql = sprintf(
            "SELECT * FROM ip_access_rules WHERE domain_name = '%s' ORDER BY id DESC",
            str_replace("'", "''", strtolower(trim($domainName)))
        );
        $result = $this->d1->query($sql);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load IP rules.', 'rules' => []];
        }

        $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];

        return ['ok' => true, 'error' => null, 'rules' => $rows];
    }

    public function listAll(): array
    {
        $result = $this->d1->query('SELECT * FROM ip_access_rules ORDER BY id DESC');
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load IP rules.', 'rules' => []];
        }

        $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];

        return ['ok' => true, 'error' => null, 'rules' => $rows];
    }

    public function purgeCache(string $domainName): array
    {
        $domain = strtolower(trim($domainName));
        if ($domain !== '') {
            PurgeRuntimeBundleCache::dispatch($domain);
        }

        return ['ok' => true, 'error' => null];
    }

    public function create(string $domainName, string $ipOrCidr, string $action, ?string $note): array
    {
        $sql = sprintf(
            "INSERT INTO ip_access_rules (domain_name, ip_or_cidr, action, note) VALUES ('%s', '%s', '%s', '%s')",
            str_replace("'", "''", strtolower(trim($domainName))),
            str_replace("'", "''", trim($ipOrCidr)),
            str_replace("'", "''", trim($action)),
            str_replace("'", "''", trim($note ?? ''))
        );
        $result = $this->d1->query($sql);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to create IP rule.'];
        }

        $this->purgeCache($domainName);

        return ['ok' => true, 'error' => null];
    }

    public function getById(string $domainName, int $ruleId): array
    {
        $sql = sprintf(
            "SELECT * FROM ip_access_rules WHERE domain_name = '%s' AND id = %d LIMIT 1",
            str_replace("'", "''", strtolower(trim($domainName))),
            $ruleId
        );
        $result = $this->d1->query($sql);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to load IP rule.', 'rule' => null];
        }

        $rows = $this->d1->parseWranglerJson($result['output'])[0]['results'] ?? [];
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;
        if (! $row) {
            return ['ok' => false, 'error' => 'IP Rule not found.', 'rule' => null];
        }

        return ['ok' => true, 'error' => null, 'rule' => $row];
    }

    public function update(string $domainName, int $ruleId, string $ipOrCidr, string $action, ?string $note): array
    {
        $sql = sprintf(
            "UPDATE ip_access_rules SET ip_or_cidr = '%s', action = '%s', note = '%s' WHERE domain_name = '%s' AND id = %d",
            str_replace("'", "''", trim($ipOrCidr)),
            str_replace("'", "''", trim($action)),
            str_replace("'", "''", trim($note ?? '')),
            str_replace("'", "''", strtolower(trim($domainName))),
            $ruleId
        );
        $result = $this->d1->query($sql);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to update IP rule.'];
        }

        $this->purgeCache($domainName);

        return ['ok' => true, 'error' => null];
    }

    public function delete(string $domainName, int $ruleId): array
    {
        $sql = sprintf(
            "DELETE FROM ip_access_rules WHERE domain_name = '%s' AND id = %d",
            str_replace("'", "''", strtolower(trim($domainName))),
            $ruleId
        );
        $result = $this->d1->query($sql);
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?: 'Failed to delete IP rule.'];
        }

        $this->purgeCache($domainName);

        return ['ok' => true, 'error' => null];
    }
}
