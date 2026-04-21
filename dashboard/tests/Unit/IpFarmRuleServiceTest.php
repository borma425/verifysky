<?php

namespace Tests\Unit;

use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\EdgeShield\FirewallRuleService;
use App\Services\EdgeShield\WorkerAdminClient;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class IpFarmRuleServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_ip_farm_rule_dedupes_and_chunks_targets(): void
    {
        Queue::fake();
        $d1 = new class extends D1DatabaseClient
        {
            public array $queries = [];

            public function __construct() {}

            public function query(string $sql, int $timeout = 90): array
            {
                $this->queries[] = $sql;
                if (str_contains($sql, "description LIKE '[IP-FARM]%'")) {
                    return ['ok' => true, 'output' => json_encode([['results' => []]])];
                }

                return ['ok' => true, 'output' => json_encode([['results' => []]])];
            }

            public function parseWranglerJson(string $raw): array
            {
                return json_decode($raw, true) ?: [];
            }
        };

        $service = new FirewallRuleService($d1, Mockery::mock(WorkerAdminClient::class));
        $result = $service->createIpFarmRule('global', 'Manual', ['203.0.113.10', '203.0.113.10', '198.51.100.0/24'], false, 'tenant-1', 'tenant');

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['added']);
        $this->assertTrue(collect($d1->queries)->contains(fn (string $sql): bool => str_contains($sql, 'INSERT INTO custom_firewall_rules') && str_contains($sql, "'tenant-1'") && str_contains($sql, "'tenant'")));
    }

    public function test_update_ip_farm_rule_updates_domain_tenant_scope_and_targets_by_id(): void
    {
        Queue::fake();
        $existingRule = [
            'id' => 7,
            'domain_name' => 'global',
            'tenant_id' => 'tenant-1',
            'scope' => 'tenant',
            'description' => '[IP-FARM] Old (1 IPs)',
            'action' => 'block',
            'expression_json' => json_encode(['field' => 'ip.src', 'operator' => 'in', 'value' => '203.0.113.10']),
            'paused' => 0,
        ];
        $d1 = new class($existingRule) extends D1DatabaseClient
        {
            public array $queries = [];

            public function __construct(private readonly array $existingRule) {}

            public function query(string $sql, int $timeout = 90): array
            {
                $this->queries[] = $sql;
                if (str_contains($sql, 'WHERE id = 7') && str_contains($sql, 'LIMIT 1')) {
                    return ['ok' => true, 'output' => json_encode([['results' => [$this->existingRule]]])];
                }

                return ['ok' => true, 'output' => json_encode([['results' => []]])];
            }

            public function parseWranglerJson(string $raw): array
            {
                return json_decode($raw, true) ?: [];
            }
        };

        $service = new FirewallRuleService($d1, Mockery::mock(WorkerAdminClient::class));
        $result = $service->updateIpFarmRule(7, 'www.cashup.cash', 'Edited', ['203.0.113.20'], true, 'tenant-1', 'domain');

        $this->assertTrue($result['ok']);
        $this->assertTrue(collect($d1->queries)->contains(fn (string $sql): bool => str_contains($sql, 'UPDATE custom_firewall_rules')
            && str_contains($sql, "domain_name = 'www.cashup.cash'")
            && str_contains($sql, "tenant_id = 'tenant-1'")
            && str_contains($sql, "scope = 'domain'")
            && str_contains($sql, 'WHERE id = 7')));
    }
}
