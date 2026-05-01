<?php

namespace Tests\Feature;

use App\Models\DashboardSetting;
use App\Services\EdgeShield\DomainConfigService;
use App\Services\EdgeShield\EdgeShieldConfig;
use App\Services\EdgeShield\SaasSecurityService;
use App\Services\EdgeShield\WorkerRouteService;
use App\Services\EdgeShield\WorkerSecretSyncService;
use App\Services\EdgeShield\WranglerProcessRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class WorkerSecretSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_worker_secret_sync_rejects_missing_jwt_secret_before_deploy(): void
    {
        DashboardSetting::query()->create(['key' => 'cf_api_token', 'value' => 'cf-token']);
        DashboardSetting::query()->create(['key' => 'es_admin_token', 'value' => 'admin-token']);
        Config::set('edgeshield.runtime.es_admin_token', 'admin-token-from-env');

        $runner = Mockery::mock(WranglerProcessRunner::class);
        $runner->shouldReceive('runInProject')->never();
        $config = Mockery::mock(EdgeShieldConfig::class);
        $config->shouldReceive('cloudflareApiToken')->andReturn('cf-token-from-env');

        $sync = new WorkerSecretSyncService(
            $config,
            $runner,
            Mockery::mock(DomainConfigService::class),
            Mockery::mock(WorkerRouteService::class),
            Mockery::mock(SaasSecurityService::class)
        );

        $result = $sync->syncFromDashboardSettings();

        $this->assertFalse($result['ok']);
        $this->assertSame(['JWT_SECRET is required in environment configuration before sync.'], $result['errors']);
    }

    public function test_worker_secret_sync_deploys_to_default_production_script_without_env_override(): void
    {
        DashboardSetting::query()->create(['key' => 'cf_api_token', 'value' => 'cf-token']);
        DashboardSetting::query()->create(['key' => 'jwt_secret', 'value' => str_repeat('a', 32)]);
        DashboardSetting::query()->create(['key' => 'es_admin_token', 'value' => 'admin-token']);
        Config::set('edgeshield.runtime.jwt_secret', str_repeat('b', 32));
        Config::set('edgeshield.runtime.es_admin_token', 'admin-token-from-env');

        $config = Mockery::mock(EdgeShieldConfig::class);
        $config->shouldReceive('wranglerBin')->andReturn('npx wrangler');
        $config->shouldReceive('cloudflareApiToken')->andReturn('cf-token-from-env');

        $runner = Mockery::mock(WranglerProcessRunner::class);
        $runner->shouldReceive('runInProject')
            ->times(4)
            ->andReturnUsing(function (string $command): array {
                if (str_contains($command, 'deploy --keep-vars')) {
                    $this->assertStringNotContainsString('--env staging', $command);
                    $this->assertStringContainsString('OPENROUTER_MODEL:qwen/qwen3-next-80b-a3b-instruct:free', $command);

                    return ['ok' => true, 'output' => 'deploy ok', 'error' => null, 'exit_code' => 0];
                }

                return ['ok' => true, 'output' => 'secret ok', 'error' => null, 'exit_code' => 0];
            });

        $domains = Mockery::mock(DomainConfigService::class);
        $domains->shouldReceive('listActiveDomainsForRouteSync')
            ->once()
            ->andReturn([
                'ok' => true,
                'rows' => [
                    ['domain_name' => 'example.com', 'zone_id' => 'zone-123'],
                ],
            ]);

        $routes = Mockery::mock(WorkerRouteService::class);
        $routes->shouldReceive('ensureWorkerRoute')
            ->once()
            ->with('zone-123', 'example.com', Mockery::type('array'))
            ->andReturn([
                'ok' => true,
                'action' => 'example.com/*:updated',
            ]);

        $saas = Mockery::mock(SaasSecurityService::class);
        $saas->shouldReceive('ensureCacheRuleForEdgeShield')
            ->once()
            ->with('zone-123', 'example.com')
            ->andReturn([
                'ok' => true,
                'action' => 'created',
            ]);

        $sync = new WorkerSecretSyncService($config, $runner, $domains, $routes, $saas);

        $result = $sync->syncFromDashboardSettings();

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
        $this->assertContains('deploy-with-vars exit=0', $result['logs']);
    }
}
