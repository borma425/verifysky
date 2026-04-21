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

        $runner = Mockery::mock(WranglerProcessRunner::class);
        $runner->shouldReceive('runInProject')->never();

        $sync = new WorkerSecretSyncService(
            Mockery::mock(EdgeShieldConfig::class),
            $runner,
            Mockery::mock(DomainConfigService::class),
            Mockery::mock(WorkerRouteService::class),
            Mockery::mock(SaasSecurityService::class)
        );

        $result = $sync->syncFromDashboardSettings();

        $this->assertFalse($result['ok']);
        $this->assertSame(['JWT_SECRET is required in Dashboard settings before sync.'], $result['errors']);
    }
}
