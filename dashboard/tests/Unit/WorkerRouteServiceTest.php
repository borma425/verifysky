<?php

namespace Tests\Unit;

use App\Services\EdgeShield\CloudflareApiClient;
use App\Services\EdgeShield\EdgeShieldConfig;
use App\Services\EdgeShield\WorkerRouteService;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class WorkerRouteServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_apex_domain_syncs_apex_and_www_routes(): void
    {
        $cloudflare = Mockery::mock(CloudflareApiClient::class);
        $cloudflare->shouldReceive('request')
            ->once()
            ->with('GET', '/zones/zone-1/workers/routes', ['page' => 1, 'per_page' => 100])
            ->andReturn(['ok' => true, 'error' => null, 'result' => []]);
        $cloudflare->shouldReceive('request')
            ->once()
            ->with('POST', '/zones/zone-1/workers/routes', [], [
                'pattern' => 'cashup.cash/*',
                'script' => 'verifysky-edge',
            ])
            ->andReturn(['ok' => true, 'error' => null, 'result' => ['id' => 'route-apex']]);
        $cloudflare->shouldReceive('request')
            ->once()
            ->with('POST', '/zones/zone-1/workers/routes', [], [
                'pattern' => 'www.cashup.cash/*',
                'script' => 'verifysky-edge',
            ])
            ->andReturn(['ok' => true, 'error' => null, 'result' => ['id' => 'route-www']]);

        $result = $this->service($cloudflare)->ensureWorkerRoute('zone-1', 'cashup.cash');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('cashup.cash/*:created', $result['action']);
        $this->assertStringContainsString('www.cashup.cash/*:created', $result['action']);
    }

    public function test_www_apex_domain_syncs_www_and_apex_routes(): void
    {
        $cloudflare = Mockery::mock(CloudflareApiClient::class);
        $cloudflare->shouldReceive('request')
            ->once()
            ->with('GET', '/zones/zone-1/workers/routes', ['page' => 1, 'per_page' => 100])
            ->andReturn(['ok' => true, 'error' => null, 'result' => []]);
        $cloudflare->shouldReceive('request')
            ->once()
            ->with('POST', '/zones/zone-1/workers/routes', [], [
                'pattern' => 'www.cashup.cash/*',
                'script' => 'verifysky-edge',
            ])
            ->andReturn(['ok' => true, 'error' => null, 'result' => ['id' => 'route-www']]);
        $cloudflare->shouldReceive('request')
            ->once()
            ->with('POST', '/zones/zone-1/workers/routes', [], [
                'pattern' => 'cashup.cash/*',
                'script' => 'verifysky-edge',
            ])
            ->andReturn(['ok' => true, 'error' => null, 'result' => ['id' => 'route-apex']]);

        $result = $this->service($cloudflare)->ensureWorkerRoute('zone-1', 'www.cashup.cash');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('www.cashup.cash/*:created', $result['action']);
        $this->assertStringContainsString('cashup.cash/*:created', $result['action']);
    }

    public function test_multi_part_apex_domain_syncs_www_variant(): void
    {
        $cloudflare = Mockery::mock(CloudflareApiClient::class);
        $cloudflare->shouldReceive('request')
            ->once()
            ->with('GET', '/zones/zone-1/workers/routes', ['page' => 1, 'per_page' => 100])
            ->andReturn(['ok' => true, 'error' => null, 'result' => []]);
        $cloudflare->shouldReceive('request')
            ->once()
            ->with('POST', '/zones/zone-1/workers/routes', [], [
                'pattern' => 'example.co.uk/*',
                'script' => 'verifysky-edge',
            ])
            ->andReturn(['ok' => true, 'error' => null, 'result' => ['id' => 'route-apex']]);
        $cloudflare->shouldReceive('request')
            ->once()
            ->with('POST', '/zones/zone-1/workers/routes', [], [
                'pattern' => 'www.example.co.uk/*',
                'script' => 'verifysky-edge',
            ])
            ->andReturn(['ok' => true, 'error' => null, 'result' => ['id' => 'route-www']]);

        $result = $this->service($cloudflare)->ensureWorkerRoute('zone-1', 'example.co.uk');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('example.co.uk/*:created', $result['action']);
        $this->assertStringContainsString('www.example.co.uk/*:created', $result['action']);
    }

    public function test_subdomain_syncs_primary_route_only_and_deletes_legacy_www_orphan_for_same_worker(): void
    {
        $cloudflare = Mockery::mock(CloudflareApiClient::class);
        $cloudflare->shouldReceive('request')
            ->once()
            ->with('GET', '/zones/zone-1/workers/routes', ['page' => 1, 'per_page' => 100])
            ->andReturn([
                'ok' => true,
                'error' => null,
                'result' => [
                    ['id' => 'route-subdomain', 'pattern' => 'ar.cashup.cash/*', 'script' => 'verifysky-edge'],
                    ['id' => 'route-legacy-www', 'pattern' => 'www.ar.cashup.cash/*', 'script' => 'verifysky-edge'],
                    ['id' => 'route-apex-www', 'pattern' => 'www.cashup.cash/*', 'script' => 'verifysky-edge'],
                    ['id' => 'route-other-worker', 'pattern' => 'www.ar.cashup.cash/*', 'script' => 'other-worker'],
                ],
            ]);
        $cloudflare->shouldReceive('request')
            ->once()
            ->with('DELETE', '/zones/zone-1/workers/routes/route-legacy-www')
            ->andReturn(['ok' => true, 'error' => null, 'result' => null]);
        $cloudflare->shouldNotReceive('request')
            ->with('POST', Mockery::any(), Mockery::any(), Mockery::any());
        $cloudflare->shouldNotReceive('request')
            ->with('DELETE', '/zones/zone-1/workers/routes/route-apex-www');
        $cloudflare->shouldNotReceive('request')
            ->with('DELETE', '/zones/zone-1/workers/routes/route-other-worker');

        $result = $this->service($cloudflare)->ensureWorkerRoute('zone-1', 'ar.cashup.cash');

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('ar.cashup.cash/*:already_synced', $result['action']);
        $this->assertStringContainsString('www.ar.cashup.cash/*:orphan_deleted', $result['action']);
    }

    private function service(CloudflareApiClient $cloudflare): WorkerRouteService
    {
        Config::set('edgeshield.target_env', 'staging');
        Config::set('edgeshield.worker_name', 'verifysky-edge');

        return new WorkerRouteService($cloudflare, app(EdgeShieldConfig::class));
    }
}
