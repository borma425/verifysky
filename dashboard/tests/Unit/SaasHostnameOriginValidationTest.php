<?php

namespace Tests\Unit;

use App\Services\EdgeShield\CloudflareApiClient;
use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\EdgeShield\EdgeShieldConfig;
use App\Services\EdgeShield\SaasHostnameService;
use App\Services\EdgeShield\SaasSecurityService;
use App\Services\EdgeShield\TurnstileService;
use App\Services\EdgeShield\WorkerRouteService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SaasHostnameOriginValidationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_ip_origin_probe_preserves_hostname_for_tls_sni(): void
    {
        Http::fake([
            'https://www.example.com' => Http::response('', 200, ['Server' => 'nginx']),
        ]);

        $service = $this->service();

        $result = $service->validateOriginServerForHostname('www.example.com', '192.0.2.10');

        $this->assertTrue($result['ok']);
        $this->assertSame('https', $result['scheme']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.example.com');
    }

    public function test_local_server_ip_can_pass_when_http_hairpin_probe_fails(): void
    {
        Http::fake([
            'https://www.example.com' => Http::response('', 503, ['Server' => 'nginx']),
            'http://www.example.com' => Http::response('', 503, ['Server' => 'nginx']),
        ]);

        $service = $this->service(['152.53.247.192'], true);

        $result = $service->validateOriginServerForHostname('www.example.com', '152.53.247.192');

        $this->assertTrue($result['ok']);
        $this->assertSame('local', $result['scheme']);
        $this->assertSame(0, $result['status']);
    }

    private function service(array $localIps = [], bool $tcpOpen = false): TestableSaasHostnameService
    {
        $config = Mockery::mock(EdgeShieldConfig::class);
        $config->shouldReceive('saasZoneId')->andReturn('zone-id');
        $config->shouldReceive('saasCnameTarget')->andReturn('customers.verifysky.com');

        $cloudflare = Mockery::mock(CloudflareApiClient::class);
        $cloudflare->shouldReceive('request')
            ->with('GET', '/zones/zone-id/dns_records', Mockery::type('array'))
            ->andReturn(['ok' => true, 'error' => null, 'result' => []]);
        $cloudflare->shouldReceive('request')
            ->with('POST', '/zones/zone-id/dns_records', [], Mockery::type('array'))
            ->andReturn(['ok' => true, 'error' => null, 'result' => []]);

        $service = new TestableSaasHostnameService(
            $config,
            $cloudflare,
            Mockery::mock(D1DatabaseClient::class),
            Mockery::mock(TurnstileService::class),
            Mockery::mock(WorkerRouteService::class),
            Mockery::mock(SaasSecurityService::class)
        );
        $service->localIps = $localIps;
        $service->tcpOpen = $tcpOpen;

        return $service;
    }
}

class TestableSaasHostnameService extends SaasHostnameService
{
    public array $localIps = [];

    public bool $tcpOpen = false;

    protected function serverLocalIpAddresses(): array
    {
        return $this->localIps;
    }

    protected function canOpenTcpConnection(string $ipAddress, int $port): bool
    {
        return $this->tcpOpen;
    }
}
