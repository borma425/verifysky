<?php

namespace App\Services\EdgeShield;

use RuntimeException;

class D1SchemaSyncService
{
    /** @var array<string, array<int, string>> */
    private array $columnCache = [];

    /** @var array<string, array<int, string>> */
    private array $indexCache = [];

    public function __construct(private readonly D1DatabaseClient $d1) {}

    public function sync(): array
    {
        $changes = [];

        $this->ensureSecurityLogsSchema($changes);
        $this->ensureDomainConfigsSchema($changes);
        $this->ensureIpAccessRulesSchema($changes);
        $this->ensureCustomFirewallRulesSchema($changes);
        $this->ensureSensitivePathsSchema($changes);

        return [
            'ok' => true,
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<int, string>  $changes
     */
    private function ensureSecurityLogsSchema(array &$changes): void
    {
        $this->runSchemaStatement('Create security_logs table', <<<'SQL'
CREATE TABLE IF NOT EXISTS security_logs (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_name      TEXT,
    event_type       TEXT    NOT NULL,
    ip_address       TEXT    NOT NULL,
    asn              TEXT,
    country          TEXT,
    target_path      TEXT,
    fingerprint_hash TEXT,
    risk_score       INTEGER,
    details          TEXT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->ensureColumn('security_logs', 'domain_name', 'TEXT', $changes);
        $this->ensureIndex('security_logs', 'idx_security_logs_created', 'CREATE INDEX IF NOT EXISTS idx_security_logs_created ON security_logs (created_at)', $changes);
        $this->ensureIndex('security_logs', 'idx_security_logs_ip_event', 'CREATE INDEX IF NOT EXISTS idx_security_logs_ip_event ON security_logs (ip_address, event_type)', $changes);
        $this->ensureIndex('security_logs', 'idx_security_logs_asn_created', 'CREATE INDEX IF NOT EXISTS idx_security_logs_asn_created ON security_logs (asn, created_at)', $changes);
        $this->ensureIndex('security_logs', 'idx_security_logs_fingerprint', 'CREATE INDEX IF NOT EXISTS idx_security_logs_fingerprint ON security_logs (fingerprint_hash, created_at)', $changes);
        $this->ensureIndex('security_logs', 'idx_security_logs_path', 'CREATE INDEX IF NOT EXISTS idx_security_logs_path ON security_logs (target_path, created_at)', $changes);
        $this->ensureIndex('security_logs', 'idx_security_logs_domain_created', 'CREATE INDEX IF NOT EXISTS idx_security_logs_domain_created ON security_logs (domain_name, created_at)', $changes);
    }

    /**
     * @param  array<int, string>  $changes
     */
    private function ensureDomainConfigsSchema(array &$changes): void
    {
        $this->runSchemaStatement('Create domain_configs table', <<<'SQL'
CREATE TABLE IF NOT EXISTS domain_configs (
    domain_name        TEXT    PRIMARY KEY,
    tenant_id          TEXT,
    zone_id            TEXT    NOT NULL,
    turnstile_sitekey  TEXT    NOT NULL,
    turnstile_secret   TEXT    NOT NULL,
    custom_hostname_id TEXT,
    cname_target       TEXT,
    origin_server      TEXT,
    hostname_status    TEXT,
    ssl_status         TEXT,
    ownership_verification_json TEXT,
    force_captcha      INTEGER NOT NULL DEFAULT 0,
    security_mode      TEXT    NOT NULL DEFAULT 'balanced',
    status             TEXT    NOT NULL DEFAULT 'active',
    thresholds_json    TEXT,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->ensureColumn('domain_configs', 'tenant_id', 'TEXT', $changes);
        $this->ensureColumn('domain_configs', 'custom_hostname_id', 'TEXT', $changes);
        $this->ensureColumn('domain_configs', 'cname_target', 'TEXT', $changes);
        $this->ensureColumn('domain_configs', 'origin_server', 'TEXT', $changes);
        $this->ensureColumn('domain_configs', 'hostname_status', 'TEXT', $changes);
        $this->ensureColumn('domain_configs', 'ssl_status', 'TEXT', $changes);
        $this->ensureColumn('domain_configs', 'ownership_verification_json', 'TEXT', $changes);
        $this->ensureColumn('domain_configs', 'security_mode', "TEXT NOT NULL DEFAULT 'balanced'", $changes);
        $this->ensureColumn('domain_configs', 'thresholds_json', 'TEXT', $changes);
        $this->ensureColumn('domain_configs', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP', $changes);
        $this->ensureIndex('domain_configs', 'idx_domain_configs_status', 'CREATE INDEX IF NOT EXISTS idx_domain_configs_status ON domain_configs (status)', $changes);
        $this->ensureIndex('domain_configs', 'idx_domain_configs_tenant', 'CREATE INDEX IF NOT EXISTS idx_domain_configs_tenant ON domain_configs (tenant_id)', $changes);
        $this->ensureIndex('domain_configs', 'idx_domain_configs_custom_hostname', 'CREATE INDEX IF NOT EXISTS idx_domain_configs_custom_hostname ON domain_configs (custom_hostname_id)', $changes);
    }

    /**
     * @param  array<int, string>  $changes
     */
    private function ensureIpAccessRulesSchema(array &$changes): void
    {
        $this->runSchemaStatement('Create ip_access_rules table', <<<'SQL'
CREATE TABLE IF NOT EXISTS ip_access_rules (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_name      TEXT    NOT NULL,
    ip_or_cidr       TEXT    NOT NULL,
    action           TEXT    NOT NULL DEFAULT 'block',
    note             TEXT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->ensureIndex('ip_access_rules', 'idx_ip_rules_domain', 'CREATE INDEX IF NOT EXISTS idx_ip_rules_domain ON ip_access_rules (domain_name)', $changes);
    }

    /**
     * @param  array<int, string>  $changes
     */
    private function ensureCustomFirewallRulesSchema(array &$changes): void
    {
        $this->runSchemaStatement('Create custom_firewall_rules table', <<<'SQL'
CREATE TABLE IF NOT EXISTS custom_firewall_rules (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_name      TEXT    NOT NULL,
    tenant_id        TEXT,
    scope            TEXT    NOT NULL DEFAULT 'domain',
    description      TEXT,
    action           TEXT    NOT NULL,
    expression_json  TEXT    NOT NULL,
    paused           INTEGER DEFAULT 0,
    expires_at       INTEGER,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->ensureColumn('custom_firewall_rules', 'tenant_id', 'TEXT', $changes);
        $this->ensureColumn('custom_firewall_rules', 'scope', "TEXT NOT NULL DEFAULT 'domain'", $changes);
        $this->ensureColumn('custom_firewall_rules', 'expires_at', 'INTEGER', $changes);
        $this->ensureColumn('custom_firewall_rules', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP', $changes);
        $this->ensureIndex('custom_firewall_rules', 'idx_fw_rules_domain', 'CREATE INDEX IF NOT EXISTS idx_fw_rules_domain ON custom_firewall_rules (domain_name)', $changes);
        $this->ensureIndex('custom_firewall_rules', 'idx_fw_rules_tenant_scope', 'CREATE INDEX IF NOT EXISTS idx_fw_rules_tenant_scope ON custom_firewall_rules (tenant_id, scope, paused)', $changes);
        $this->ensureIndex('custom_firewall_rules', 'idx_fw_rules_ai_merge', 'CREATE INDEX IF NOT EXISTS idx_fw_rules_ai_merge ON custom_firewall_rules (domain_name, action, paused, updated_at DESC)', $changes);
    }

    /**
     * @param  array<int, string>  $changes
     */
    private function ensureSensitivePathsSchema(array &$changes): void
    {
        $this->runSchemaStatement('Create sensitive_paths table', <<<'SQL'
CREATE TABLE IF NOT EXISTS sensitive_paths (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_name      TEXT    NOT NULL,
    tenant_id        TEXT,
    scope            TEXT    NOT NULL DEFAULT 'domain',
    path_pattern     TEXT    NOT NULL,
    match_type       TEXT    NOT NULL,
    action           TEXT    NOT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->ensureColumn('sensitive_paths', 'tenant_id', 'TEXT', $changes);
        $this->ensureColumn('sensitive_paths', 'scope', "TEXT NOT NULL DEFAULT 'domain'", $changes);
        $this->ensureIndex('sensitive_paths', 'idx_sensitive_paths_domain', 'CREATE INDEX IF NOT EXISTS idx_sensitive_paths_domain ON sensitive_paths (domain_name)', $changes);
        $this->ensureIndex('sensitive_paths', 'idx_sensitive_paths_tenant_scope', 'CREATE INDEX IF NOT EXISTS idx_sensitive_paths_tenant_scope ON sensitive_paths (tenant_id, scope)', $changes);
    }

    /**
     * @param  array<int, string>  $changes
     */
    private function ensureColumn(string $table, string $column, string $definition, array &$changes): void
    {
        if (in_array($column, $this->tableColumns($table), true)) {
            return;
        }

        $this->runSchemaStatement(
            sprintf('Add %s.%s column', $table, $column),
            sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition)
        );
        $this->columnCache[$table] = [];
        $changes[] = sprintf('Added column %s.%s', $table, $column);
    }

    /**
     * @param  array<int, string>  $changes
     */
    private function ensureIndex(string $table, string $index, string $sql, array &$changes): void
    {
        if (in_array($index, $this->tableIndexes($table), true)) {
            return;
        }

        $this->runSchemaStatement(sprintf('Create %s index', $index), $sql);
        $this->indexCache[$table] = [];
        $changes[] = sprintf('Ensured index %s', $index);
    }

    /**
     * @return array<int, string>
     */
    private function tableColumns(string $table): array
    {
        if (array_key_exists($table, $this->columnCache) && $this->columnCache[$table] !== []) {
            return $this->columnCache[$table];
        }

        $result = $this->d1->query(sprintf("PRAGMA table_info('%s')", $this->escape($table)));
        $this->assertOk($result, sprintf('Inspect %s columns', $table));
        $rows = $this->parseResults($result);

        return $this->columnCache[$table] = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['name'] ?? '')),
            $rows
        ))));
    }

    /**
     * @return array<int, string>
     */
    private function tableIndexes(string $table): array
    {
        if (array_key_exists($table, $this->indexCache) && $this->indexCache[$table] !== []) {
            return $this->indexCache[$table];
        }

        $result = $this->d1->query(sprintf("PRAGMA index_list('%s')", $this->escape($table)));
        $this->assertOk($result, sprintf('Inspect %s indexes', $table));
        $rows = $this->parseResults($result);

        return $this->indexCache[$table] = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['name'] ?? '')),
            $rows
        ))));
    }

    private function runSchemaStatement(string $label, string $sql): void
    {
        $result = $this->d1->query($sql);
        $this->assertOk($result, $label);
    }

    private function assertOk(array $result, string $label): void
    {
        if (($result['ok'] ?? false) === true) {
            return;
        }

        $error = trim((string) ($result['error'] ?? 'Unknown D1 error.'));

        throw new RuntimeException($label.' failed: '.$error);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseResults(array $result): array
    {
        $payload = $this->d1->parseWranglerJson((string) ($result['output'] ?? ''));
        $rows = $payload[0]['results'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    private function escape(string $value): string
    {
        return str_replace("'", "''", trim($value));
    }
}
