<?php

namespace Tests\Feature;

use App\Services\EdgeShieldService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function bindServiceMock(): MockInterface
    {
        $mock = Mockery::mock(EdgeShieldService::class);
        $this->app->instance(EdgeShieldService::class, $mock);
        return $mock;
    }

    public function test_domains_index_renders_expected_action_buttons(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('ensureSecurityModeColumn')->once();
        $mock->shouldReceive('queryD1')->once()->andReturn([
            'ok' => true,
            'output' => 'ignored',
            'error' => null,
        ]);
        $mock->shouldReceive('parseWranglerJson')->once()->andReturn([[
            'results' => [[
                'domain_name' => 'example.com',
                'zone_id' => 'zone1',
                'status' => 'active',
                'force_captcha' => 1,
                'security_mode' => 'balanced',
                'created_at' => '2026-03-22 00:00:00',
            ]],
        ]]);

        $response = $this->withSession(['is_admin' => true])->get('/domains');

        $response->assertOk()
            ->assertSee('Rules')
            ->assertSee('Sync Route')
            ->assertSee('Disable Forced CAPTCHA')
            ->assertSee('Pause')
            ->assertSee('Delete');
    }

    public function test_rules_route_loads_domain_rules_screen(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('getDomainConfig')->once()->andReturn([
            'ok' => true,
            'domain' => [
                'domain_name' => 'example.com',
                'zone_id' => 'zone1',
                'status' => 'active',
                'force_captcha' => 1,
            ],
        ]);
        $mock->shouldReceive('listZoneWorkerRoutes')->once()->andReturn([
            'ok' => true,
            'routes' => [],
        ]);
        $mock->shouldReceive('listZoneFirewallRules')->once()->andReturn([
            'ok' => true,
            'rules' => [],
        ]);

        $response = $this->withSession(['is_admin' => true])->get('/domains/example.com/rules');

        $response->assertOk()->assertSee('Rules Management: example.com');
    }

    public function test_sync_route_button_endpoint_works(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')->once()->andReturn([
            'ok' => true,
            'output' => 'ignored',
            'error' => null,
        ]);
        $mock->shouldReceive('parseWranglerJson')->once()->andReturn([[
            'results' => [[
                'domain_name' => 'example.com',
                'zone_id' => 'zone1',
            ]],
        ]]);
        $mock->shouldReceive('ensureWorkerRoute')->once()->with('zone1', 'example.com')->andReturn([
            'ok' => true,
            'action' => 'created',
        ]);

        $response = $this->withSession(['is_admin' => true])->post('/domains/example.com/sync-route');

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_disable_forced_captcha_button_endpoint_works(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')->once()->andReturn([
            'ok' => true,
            'output' => '',
            'error' => null,
        ]);

        $response = $this->withSession(['is_admin' => true])->post('/domains/example.com/force-captcha', [
            'force_captcha' => '0',
        ]);

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_pause_button_endpoint_works(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')->once()->andReturn([
            'ok' => true,
            'output' => '',
            'error' => null,
        ]);

        $response = $this->withSession(['is_admin' => true])->post('/domains/example.com/status', [
            'status' => 'paused',
        ]);

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_delete_button_endpoint_removes_domain_and_artifacts(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')->twice()->andReturn(
            [
                'ok' => true,
                'output' => 'ignored-read',
                'error' => null,
            ],
            [
                'ok' => true,
                'output' => 'ignored-delete',
                'error' => null,
            ]
        );
        $mock->shouldReceive('parseWranglerJson')->once()->andReturn([[
            'results' => [[
                'domain_name' => 'example.com',
                'zone_id' => 'zone1',
                'turnstile_sitekey' => 'sitekey1',
            ]],
        ]]);
        $mock->shouldReceive('removeDomainSecurityArtifacts')->once()->with('zone1', 'example.com', 'sitekey1')->andReturn([
            'ok' => true,
            'details' => ['ok'],
        ]);

        $response = $this->withSession(['is_admin' => true])->delete('/domains/example.com');

        $response->assertRedirect()->assertSessionHas('status');
    }
}

