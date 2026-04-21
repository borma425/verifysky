<?php

namespace Tests\Feature;

use App\Jobs\PurgeRuntimeBundleCache;
use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\EdgeShield\DomainConfigService;
use App\Services\EdgeShield\EdgeShieldConfig;
use App\Services\EdgeShield\FirewallRuleService;
use App\Services\EdgeShield\WorkerAdminClient;
use App\Services\EdgeShield\WranglerProcessRunner;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class RuntimeBundleCachePurgeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_domain_config_purge_dispatches_runtime_bundle_job_for_domain_variants(): void
    {
        Queue::fake();

        $service = new DomainConfigService(
            Mockery::mock(D1DatabaseClient::class),
            Mockery::mock(WranglerProcessRunner::class),
            Mockery::mock(EdgeShieldConfig::class)
        );

        $result = $service->purgeDomainConfigCache('cashup.cash');

        $this->assertTrue($result['ok']);
        Queue::assertPushed(PurgeRuntimeBundleCache::class, fn (PurgeRuntimeBundleCache $job): bool => $job->domain === 'cashup.cash');
        Queue::assertPushed(PurgeRuntimeBundleCache::class, fn (PurgeRuntimeBundleCache $job): bool => $job->domain === 'www.cashup.cash');
    }

    public function test_create_firewall_rule_dispatches_runtime_bundle_purge_job(): void
    {
        Queue::fake();

        $d1 = Mockery::mock(D1DatabaseClient::class);
        $d1->shouldReceive('query')->once()->andReturn(['ok' => true, 'error' => null, 'output' => '']);

        $service = new FirewallRuleService(
            $d1,
            Mockery::mock(WorkerAdminClient::class)
        );

        $result = $service->create(
            'cashup.cash',
            'Block scanner',
            'block',
            json_encode(['field' => 'http.user_agent', 'operator' => 'contains', 'value' => 'curl']) ?: '{}',
            false
        );

        $this->assertTrue($result['ok']);
        Queue::assertPushed(PurgeRuntimeBundleCache::class, fn (PurgeRuntimeBundleCache $job): bool => $job->domain === 'cashup.cash');
    }
}
