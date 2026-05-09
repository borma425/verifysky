<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Repositories\SecurityLogRepository;
use App\Services\EdgeShieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SecurityLogRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_non_admin_queries_are_scoped_to_tenant_domains(): void
    {
        $tenant = $this->makeTenant('analytics-tenant');
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'www.example.com',
        ]);

        $capturedSql = [];
        $edgeShield = Mockery::mock(EdgeShieldService::class);
        $edgeShield->shouldReceive('listIpFarmRules')->once()->with((string) $tenant->id)->andReturn(['ok' => false]);
        $edgeShield->shouldReceive('queryD1')->andReturnUsing(function (string $sql) use (&$capturedSql): array {
            $capturedSql[] = $sql;

            return match (true) {
                str_contains($sql, "SELECT expression_json FROM custom_firewall_rules WHERE action IN ('allow', 'bypass')") => ['ok' => false],
                str_contains($sql, 'SELECT COUNT(*) AS total_rows') => ['ok' => true, 'output' => 'count-output'],
                str_starts_with($sql, 'WITH filtered AS') => ['ok' => true, 'output' => 'rows-output'],
                str_starts_with($sql, 'SELECT domain_name, thresholds_json FROM domain_configs') => ['ok' => false],
                str_starts_with($sql, 'SELECT domain_name, status FROM domain_configs') => ['ok' => false],
                str_starts_with($sql, "SELECT 'event' AS bucket") => ['ok' => false],
                str_contains($sql, 'as total_attacks') => ['ok' => false],
                str_contains($sql, 'GROUP BY country ORDER BY attack_count DESC LIMIT 3') => ['ok' => false],
                default => throw new \RuntimeException('Unexpected SQL: '.$sql),
            };
        });
        $edgeShield->shouldReceive('parseWranglerJson')->andReturnUsing(function (string $output): array {
            return match ($output) {
                'count-output' => [['results' => [['total_rows' => 1]]]],
                'rows-output' => [[
                    'results' => [[
                        'domain_name' => 'example.com',
                        'worst_event_type' => 'challenge_failed',
                        'ip_address' => '203.0.113.10',
                        'requests_today' => 4,
                        'requests_yesterday' => 1,
                        'requests_month' => 10,
                        'flagged_events' => 4,
                        'solved_or_passed_events' => 0,
                        'details' => '{}',
                        'created_at' => '2026-04-21 10:00:00',
                    ]],
                ]],
                default => [['results' => []]],
            };
        });

        $repository = new SecurityLogRepository($edgeShield);
        $payload = $repository->fetchIndexPayload([], (string) $tenant->id, false);

        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['tenant_scoped']);
        $this->assertSame(['example.com', 'www.example.com'], $payload['accessible_domains']);
        $this->assertSame(['example.com'], $payload['filter_options']['domains']);
        $this->assertCount(1, $payload['rows']);

        $joinedSql = implode("\n", $capturedSql);
        $this->assertStringContainsString("domain_name IN ('example.com','www.example.com')", $joinedSql);
        $this->assertStringContainsString("domain_name = 'example.com'", $joinedSql);
        $this->assertStringContainsString("domain_name = 'www.example.com'", $joinedSql);
        $this->assertStringNotContainsString('other.com', $joinedSql);
    }

    public function test_non_admin_without_domains_gets_empty_safe_payload(): void
    {
        $tenant = $this->makeTenant('empty-analytics-tenant');

        $edgeShield = Mockery::mock(EdgeShieldService::class);
        $edgeShield->shouldNotReceive('listIpFarmRules');
        $edgeShield->shouldNotReceive('queryD1');
        $edgeShield->shouldNotReceive('parseWranglerJson');

        $repository = new SecurityLogRepository($edgeShield);
        $payload = $repository->fetchIndexPayload([], (string) $tenant->id, false);

        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['tenant_scoped']);
        $this->assertSame([], $payload['accessible_domains']);
        $this->assertSame(0, $payload['total']);
        $this->assertSame([], $payload['rows']);
        $this->assertSame(['domains' => [], 'events' => []], $payload['filter_options']);
        $this->assertSame(['total_attacks' => 0, 'total_visitors' => 0, 'top_countries' => []], $payload['general_stats']);
    }

    public function test_admin_queries_default_to_active_domains(): void
    {
        $capturedSql = [];
        $edgeShield = Mockery::mock(EdgeShieldService::class);
        $edgeShield->shouldReceive('listIpFarmRules')->once()->with(null)->andReturn(['ok' => false]);
        $edgeShield->shouldReceive('queryD1')->andReturnUsing(function (string $sql) use (&$capturedSql): array {
            $capturedSql[] = $sql;

            return match (true) {
                str_contains($sql, "SELECT expression_json FROM custom_firewall_rules WHERE action IN ('allow', 'bypass')") => ['ok' => false],
                str_contains($sql, 'SELECT COUNT(*) AS total_rows') => ['ok' => true, 'output' => 'count-output'],
                str_starts_with($sql, 'WITH filtered AS') => ['ok' => true, 'output' => 'rows-output'],
                str_starts_with($sql, 'SELECT domain_name, thresholds_json FROM domain_configs') => ['ok' => false],
                str_starts_with($sql, 'SELECT domain_name, status FROM domain_configs') => ['ok' => false],
                str_starts_with($sql, "SELECT 'domain' AS bucket") => ['ok' => false],
                str_contains($sql, 'as total_attacks') => ['ok' => false],
                str_contains($sql, 'GROUP BY country ORDER BY attack_count DESC LIMIT 3') => ['ok' => false],
                default => throw new \RuntimeException('Unexpected SQL: '.$sql),
            };
        });
        $edgeShield->shouldReceive('parseWranglerJson')->andReturnUsing(function (string $output): array {
            return match ($output) {
                'count-output' => [['results' => [['total_rows' => 0]]]],
                'rows-output' => [['results' => []]],
                default => [['results' => []]],
            };
        });

        $repository = new SecurityLogRepository($edgeShield);
        $payload = $repository->fetchIndexPayload([], null, true);

        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['tenant_scoped']);
        $this->assertSame([], $payload['accessible_domains']);

        $joinedSql = implode("\n", $capturedSql);
        $this->assertStringNotContainsString("domain_name IN ('example.com','www.example.com')", $joinedSql);
        $this->assertStringContainsString("domain_name IN (SELECT domain_name FROM domain_configs WHERE status = 'active')", $joinedSql);
    }

    public function test_admin_can_include_archived_domain_logs(): void
    {
        $capturedSql = [];
        $edgeShield = Mockery::mock(EdgeShieldService::class);
        $edgeShield->shouldReceive('listIpFarmRules')->once()->with(null)->andReturn(['ok' => false]);
        $edgeShield->shouldReceive('queryD1')->andReturnUsing(function (string $sql) use (&$capturedSql): array {
            $capturedSql[] = $sql;

            return match (true) {
                str_contains($sql, "SELECT expression_json FROM custom_firewall_rules WHERE action IN ('allow', 'bypass')") => ['ok' => false],
                str_contains($sql, 'SELECT COUNT(*) AS total_rows') => ['ok' => true, 'output' => 'count-output'],
                str_starts_with($sql, 'WITH filtered AS') => ['ok' => true, 'output' => 'rows-output'],
                str_starts_with($sql, 'SELECT domain_name, thresholds_json FROM domain_configs') => ['ok' => false],
                str_starts_with($sql, 'SELECT domain_name, status FROM domain_configs') => ['ok' => false],
                str_starts_with($sql, "SELECT 'domain' AS bucket") => ['ok' => false],
                str_contains($sql, 'as total_attacks') => ['ok' => false],
                str_contains($sql, 'GROUP BY country ORDER BY attack_count DESC LIMIT 3') => ['ok' => false],
                default => throw new \RuntimeException('Unexpected SQL: '.$sql),
            };
        });
        $edgeShield->shouldReceive('parseWranglerJson')->andReturnUsing(function (string $output): array {
            return match ($output) {
                'count-output' => [['results' => [['total_rows' => 0]]]],
                'rows-output' => [['results' => []]],
                default => [['results' => []]],
            };
        });

        $repository = new SecurityLogRepository($edgeShield);
        $payload = $repository->fetchIndexPayload(['include_archived' => '1'], null, true);

        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['filters']['include_archived']);

        $joinedSql = implode("\n", $capturedSql);
        $this->assertStringNotContainsString("domain_name IN (SELECT domain_name FROM domain_configs WHERE status = 'active')", $joinedSql);
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
    }
}
