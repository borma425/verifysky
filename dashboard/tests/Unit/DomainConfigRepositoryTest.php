<?php

namespace Tests\Unit;

use App\Repositories\DomainConfigRepository;
use App\Services\EdgeShieldService;
use Mockery;
use PHPUnit\Framework\TestCase;

class DomainConfigRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_list_marks_active_domain_pending_when_dns_no_longer_points_to_verifysky(): void
    {
        $edgeShield = Mockery::mock(EdgeShieldService::class);
        $edgeShield->shouldReceive('listDomains')->once()->with('tenant-1', false)->andReturn([
            'ok' => true,
            'error' => null,
            'domains' => [[
                'domain_name' => 'www.example.com',
                'cname_target' => 'customers.verifysky.com',
                'status' => 'active',
                'hostname_status' => 'active',
                'ssl_status' => 'active',
            ]],
        ]);
        $edgeShield->shouldReceive('verifySaasDnsRouteSet')
            ->once()
            ->with('www.example.com', 'customers.verifysky.com')
            ->andReturn([
                'ok' => false,
                'reason' => 'DNS is not pointing at the VerifySky CNAME target.',
                'resolved' => [['type' => 'A', 'name' => 'www.example.com', 'target' => '203.0.113.10']],
            ]);

        $repository = new DomainConfigRepository($edgeShield);
        $result = $repository->listForTenant('tenant-1', false);

        $this->assertTrue($result['ok']);
        $this->assertSame('pending', $result['domains'][0]['status']);
        $this->assertSame('pending', $result['domains'][0]['hostname_status']);
        $this->assertSame('mismatch', $result['domains'][0]['dns_route_status']);
    }

    public function test_list_keeps_domain_active_when_dns_points_to_verifysky(): void
    {
        $edgeShield = Mockery::mock(EdgeShieldService::class);
        $edgeShield->shouldReceive('listDomains')->once()->with('tenant-1', false)->andReturn([
            'ok' => true,
            'error' => null,
            'domains' => [[
                'domain_name' => 'www.example.com',
                'cname_target' => 'customers.verifysky.com',
                'status' => 'active',
                'hostname_status' => 'active',
                'ssl_status' => 'active',
            ]],
        ]);
        $edgeShield->shouldReceive('verifySaasDnsRouteSet')
            ->once()
            ->with('www.example.com', 'customers.verifysky.com')
            ->andReturn(['ok' => true, 'reason' => null, 'resolved' => []]);

        $repository = new DomainConfigRepository($edgeShield);
        $result = $repository->listForTenant('tenant-1', false);

        $this->assertTrue($result['ok']);
        $this->assertSame('active', $result['domains'][0]['status']);
        $this->assertSame('active', $result['domains'][0]['hostname_status']);
        $this->assertSame('active', $result['domains'][0]['dns_route_status']);
    }
}
