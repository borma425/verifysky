<?php

namespace Tests\Unit;

use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\EdgeShield\FirewallRuleService;
use App\Services\EdgeShield\WorkerAdminClient;
use Mockery;
use Tests\TestCase;

class FirewallRuleReadFiltersTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_list_paginated_queries_exclude_ip_farm_rows_in_results_and_count(): void
    {
        $d1 = new class extends D1DatabaseClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $sql, int $timeout = 90): array
            {
                $this->queries[] = $sql;
                if (str_contains($sql, 'COUNT(*) as total')) {
                    return ['ok' => true, 'output' => json_encode([['results' => [['total' => 0]]]])];
                }

                return ['ok' => true, 'output' => json_encode([['results' => []]])];
            }

            public function parseWranglerJson(string $raw): array
            {
                return json_decode($raw, true) ?: [];
            }
        };

        $service = new FirewallRuleService($d1, Mockery::mock(WorkerAdminClient::class));
        $service->listPaginated(20, 0);

        $filter = "(description IS NULL OR description NOT LIKE '[IP-FARM]%')";
        $this->assertTrue(collect($d1->queries)->contains(fn (string $sql): bool => str_contains($sql, 'SELECT * FROM custom_firewall_rules') && str_contains($sql, $filter)));
        $this->assertTrue(collect($d1->queries)->contains(fn (string $sql): bool => str_contains($sql, 'SELECT COUNT(*) as total FROM custom_firewall_rules') && str_contains($sql, $filter)));
    }

    public function test_list_for_tenant_query_excludes_ip_farm_rows(): void
    {
        $d1 = new class extends D1DatabaseClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $sql, int $timeout = 90): array
            {
                $this->queries[] = $sql;

                return ['ok' => true, 'output' => json_encode([['results' => []]])];
            }

            public function parseWranglerJson(string $raw): array
            {
                return json_decode($raw, true) ?: [];
            }
        };

        $service = new FirewallRuleService($d1, Mockery::mock(WorkerAdminClient::class));
        $service->listForTenant('tenant-1');

        $this->assertNotEmpty($d1->queries);
        $sql = (string) $d1->queries[0];
        $this->assertStringContainsString("tenant_id = 'tenant-1'", $sql);
        $this->assertStringContainsString("AND (description IS NULL OR description NOT LIKE '[IP-FARM]%')", $sql);
    }
}
