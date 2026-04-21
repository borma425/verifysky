<?php

namespace Tests\Unit;

use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\EdgeShield\D1SchemaSyncService;
use Tests\TestCase;

class D1SchemaSyncServiceTest extends TestCase
{
    public function test_sync_adds_missing_schema_artifacts_and_becomes_idempotent(): void
    {
        $d1 = new class extends D1DatabaseClient
        {
            /** @var array<string, array<int, string>> */
            public array $columns = [
                'security_logs' => ['id', 'event_type', 'ip_address', 'asn', 'country', 'target_path', 'fingerprint_hash', 'risk_score', 'details', 'created_at'],
                'domain_configs' => ['domain_name', 'zone_id', 'turnstile_sitekey', 'turnstile_secret', 'force_captcha', 'status', 'created_at'],
                'custom_firewall_rules' => ['id', 'domain_name', 'description', 'action', 'expression_json', 'paused', 'created_at'],
                'sensitive_paths' => ['id', 'domain_name', 'path_pattern', 'match_type', 'action', 'created_at'],
            ];

            /** @var array<string, array<int, string>> */
            public array $indexes = [
                'security_logs' => [],
                'domain_configs' => [],
                'custom_firewall_rules' => [],
                'sensitive_paths' => [],
            ];

            /** @var array<int, string> */
            public array $statements = [];

            public function __construct() {}

            public function query(string $sql, int $timeout = 90): array
            {
                $normalized = trim((string) preg_replace('/\s+/', ' ', $sql));
                $this->statements[] = $normalized;

                if (preg_match('/^CREATE TABLE IF NOT EXISTS ([a-z_]+)/i', $normalized, $matches) === 1) {
                    $table = strtolower($matches[1]);
                    $this->columns[$table] ??= $this->baseColumns($table);
                    $this->indexes[$table] ??= [];

                    return $this->ok('[]');
                }

                if (preg_match("/^PRAGMA table_info\\('([^']+)'\\)$/i", $normalized, $matches) === 1) {
                    return $this->ok('columns:'.strtolower($matches[1]));
                }

                if (preg_match("/^PRAGMA index_list\\('([^']+)'\\)$/i", $normalized, $matches) === 1) {
                    return $this->ok('indexes:'.strtolower($matches[1]));
                }

                if (preg_match('/^ALTER TABLE ([a-z_]+) ADD COLUMN ([a-z_]+)/i', $normalized, $matches) === 1) {
                    $table = strtolower($matches[1]);
                    $column = strtolower($matches[2]);
                    $this->columns[$table] ??= [];
                    if (! in_array($column, $this->columns[$table], true)) {
                        $this->columns[$table][] = $column;
                    }

                    return $this->ok('[]');
                }

                if (preg_match('/^CREATE INDEX IF NOT EXISTS ([a-z0-9_]+) ON ([a-z_]+)/i', $normalized, $matches) === 1) {
                    $index = strtolower($matches[1]);
                    $table = strtolower($matches[2]);
                    $this->indexes[$table] ??= [];
                    if (! in_array($index, $this->indexes[$table], true)) {
                        $this->indexes[$table][] = $index;
                    }

                    return $this->ok('[]');
                }

                return $this->ok('[]');
            }

            public function parseWranglerJson(string $raw): array
            {
                if (preg_match('/^columns:([a-z_]+)$/', $raw, $matches) === 1) {
                    $table = $matches[1];

                    return [[
                        'results' => array_map(
                            static fn (string $name): array => ['name' => $name],
                            $this->columns[$table] ?? []
                        ),
                    ]];
                }

                if (preg_match('/^indexes:([a-z_]+)$/', $raw, $matches) === 1) {
                    $table = $matches[1];

                    return [[
                        'results' => array_map(
                            static fn (string $name): array => ['name' => $name],
                            $this->indexes[$table] ?? []
                        ),
                    ]];
                }

                return parent::parseWranglerJson($raw);
            }

            /**
             * @return array<int, string>
             */
            private function baseColumns(string $table): array
            {
                return match ($table) {
                    'security_logs' => ['id', 'domain_name', 'event_type', 'ip_address', 'asn', 'country', 'target_path', 'fingerprint_hash', 'risk_score', 'details', 'created_at'],
                    'domain_configs' => ['domain_name', 'tenant_id', 'zone_id', 'turnstile_sitekey', 'turnstile_secret', 'custom_hostname_id', 'cname_target', 'origin_server', 'hostname_status', 'ssl_status', 'ownership_verification_json', 'force_captcha', 'security_mode', 'status', 'thresholds_json', 'created_at', 'updated_at'],
                    'ip_access_rules' => ['id', 'domain_name', 'ip_or_cidr', 'action', 'note', 'created_at'],
                    'custom_firewall_rules' => ['id', 'domain_name', 'tenant_id', 'scope', 'description', 'action', 'expression_json', 'paused', 'expires_at', 'created_at', 'updated_at'],
                    'sensitive_paths' => ['id', 'domain_name', 'tenant_id', 'scope', 'path_pattern', 'match_type', 'action', 'created_at'],
                    default => [],
                };
            }

            private function ok(string $output): array
            {
                return ['ok' => true, 'output' => $output, 'error' => null];
            }
        };

        $first = (new D1SchemaSyncService($d1))->sync();
        $second = (new D1SchemaSyncService($d1))->sync();

        $this->assertTrue($first['ok']);
        $this->assertNotEmpty($first['changes']);
        $this->assertContains('Added column security_logs.domain_name', $first['changes']);
        $this->assertContains('Added column domain_configs.security_mode', $first['changes']);
        $this->assertContains('Added column custom_firewall_rules.tenant_id', $first['changes']);
        $this->assertContains('Added column custom_firewall_rules.scope', $first['changes']);
        $this->assertContains('Added column custom_firewall_rules.updated_at', $first['changes']);
        $this->assertContains('Added column sensitive_paths.tenant_id', $first['changes']);
        $this->assertContains('Added column sensitive_paths.scope', $first['changes']);
        $this->assertContains('Ensured index idx_sensitive_paths_domain', $first['changes']);
        $this->assertContains('Ensured index idx_sensitive_paths_tenant_scope', $first['changes']);
        $this->assertContains('Ensured index idx_fw_rules_tenant_scope', $first['changes']);
        $this->assertContains('Ensured index idx_ip_rules_domain', $first['changes']);
        $this->assertSame([], $second['changes']);
    }
}
