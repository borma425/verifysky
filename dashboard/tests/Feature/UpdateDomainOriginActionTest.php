<?php

namespace Tests\Feature;

use App\Actions\Domains\UpdateDomainOriginAction;
use App\Jobs\Domains\EnsureCloudflareWorkerRouteJob;
use App\Jobs\Domains\ProvisionCloudflareSaasHostnameJob;
use App\Jobs\Domains\SyncDomainConfigToD1Job;
use App\Jobs\Domains\SyncSaasSecurityArtifactsJob;
use App\Jobs\Domains\ValidateOriginServerJob;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class UpdateDomainOriginActionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_origin_update_validates_saves_refreshes_and_purges_runtime_cache(): void
    {
        $tenant = $this->tenant();
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'www.example.com',
            'origin_server' => '192.0.2.1',
            'cloudflare_custom_hostname_id' => 'host-id',
        ]);

        $edge = Mockery::mock(EdgeShieldService::class);
        $edge->shouldReceive('getDomainConfig')->once()->with('www.example.com', (string) $tenant->id, false)->andReturn([
            'ok' => true,
            'config' => [
                'custom_hostname_id' => 'host-id',
            ],
        ]);
        $edge->shouldReceive('validateOriginServerForHostname')->once()->with('www.example.com', '192.0.2.10')->andReturn([
            'ok' => true,
            'error' => null,
        ]);
        $edge->shouldReceive('updateSaasCustomOrigin')->once()->with('host-id', '192.0.2.10')->andReturn([
            'ok' => true,
            'error' => null,
        ]);
        $edge->shouldReceive('queryD1')->once()->with(Mockery::on(
            fn (string $sql): bool => str_contains($sql, "origin_server = '192.0.2.10'")
                && str_contains($sql, "domain_name = 'www.example.com'")
                && str_contains($sql, "tenant_id = '".$tenant->id."'")
        ))->andReturn([
            'ok' => true,
            'error' => null,
        ]);
        $edge->shouldReceive('refreshSaasCustomHostname')->once()->with('www.example.com')->andReturn([
            'ok' => true,
            'dns_route' => ['ok' => true],
        ]);
        $edge->shouldReceive('purgeDomainConfigCache')->once()->with('www.example.com')->andReturn([
            'ok' => true,
        ]);

        $result = (new UpdateDomainOriginAction($edge))->execute('WWW.EXAMPLE.COM', ' 192.0.2.10 ', (string) $tenant->id, false);

        $this->assertTrue($result['ok']);
        $this->assertSame('192.0.2.10', TenantDomain::query()->where('hostname', 'www.example.com')->value('origin_server'));
    }

    public function test_origin_update_rejects_unreachable_origin_before_edge_changes(): void
    {
        $tenant = $this->tenant();
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'www.example.com',
            'origin_server' => '192.0.2.1',
            'cloudflare_custom_hostname_id' => 'host-id',
        ]);

        $edge = Mockery::mock(EdgeShieldService::class);
        $edge->shouldReceive('getDomainConfig')->once()->with('www.example.com', (string) $tenant->id, false)->andReturn([
            'ok' => true,
            'config' => [
                'custom_hostname_id' => 'host-id',
            ],
        ]);
        $edge->shouldReceive('validateOriginServerForHostname')->once()->with('www.example.com', '203.0.113.77')->andReturn([
            'ok' => false,
            'error' => 'Origin is not reachable.',
        ]);
        $edge->shouldReceive('updateSaasCustomOrigin')->never();
        $edge->shouldReceive('queryD1')->never();
        $edge->shouldReceive('refreshSaasCustomHostname')->never();
        $edge->shouldReceive('purgeDomainConfigCache')->never();

        $result = (new UpdateDomainOriginAction($edge))->execute('www.example.com', '203.0.113.77', (string) $tenant->id, false);

        $this->assertFalse($result['ok']);
        $this->assertSame('Origin is not reachable.', $result['error']);
        $this->assertSame('192.0.2.1', TenantDomain::query()->where('hostname', 'www.example.com')->value('origin_server'));
    }

    public function test_origin_update_requeues_failed_local_domain_setup_when_edge_config_is_missing(): void
    {
        Bus::fake();

        $tenant = $this->tenant();
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'www.example.com',
            'origin_server' => '192.0.2.1',
            'cloudflare_custom_hostname_id' => null,
            'provisioning_status' => TenantDomain::PROVISIONING_FAILED,
            'provisioning_error' => 'Old failure',
        ]);

        $edge = Mockery::mock(EdgeShieldService::class);
        $edge->shouldReceive('getDomainConfig')->once()->with('www.example.com', (string) $tenant->id, false)->andReturn([
            'ok' => false,
            'error' => 'Domain not found.',
        ]);
        $edge->shouldReceive('validateOriginServerForHostname')->once()->with('www.example.com', '192.0.2.10')->andReturn([
            'ok' => true,
            'error' => null,
        ]);
        $edge->shouldReceive('updateSaasCustomOrigin')->never();
        $edge->shouldReceive('queryD1')->never();
        $edge->shouldReceive('refreshSaasCustomHostname')->never();
        $edge->shouldReceive('purgeDomainConfigCache')->never();

        $result = (new UpdateDomainOriginAction($edge))->execute('www.example.com', '192.0.2.10', (string) $tenant->id, false);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['queued']);
        $this->assertDatabaseHas('tenant_domains', [
            'tenant_id' => $tenant->id,
            'hostname' => 'www.example.com',
            'origin_server' => '192.0.2.10',
            'provisioning_status' => TenantDomain::PROVISIONING_PENDING,
            'provisioning_error' => null,
        ]);

        Bus::assertChained([
            ValidateOriginServerJob::class,
            EnsureCloudflareWorkerRouteJob::class,
            ProvisionCloudflareSaasHostnameJob::class,
            SyncDomainConfigToD1Job::class,
            SyncSaasSecurityArtifactsJob::class,
        ]);
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Example Tenant',
            'slug' => 'example-tenant',
            'plan' => 'starter',
            'status' => 'active',
        ]);
    }
}
