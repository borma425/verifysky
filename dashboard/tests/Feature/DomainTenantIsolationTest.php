<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DomainTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_non_admin_can_refresh_owned_domain(): void
    {
        $tenant = $this->tenant('owner');
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'owned.example.com',
        ]);

        $edge = Mockery::mock(EdgeShieldService::class);
        $this->app->instance(EdgeShieldService::class, $edge);
        $edge->shouldReceive('refreshSaasCustomHostname')->once()->with('owned.example.com')->andReturn([
            'ok' => true,
            'error' => null,
            'custom_hostname' => [],
        ]);
        $edge->shouldReceive('purgeDomainConfigCache')->once()->with('owned.example.com')->andReturn(['ok' => true]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->post('/domains/owned.example.com/sync-route');

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_non_admin_refresh_shows_failed_setup_error_before_cloudflare_refresh(): void
    {
        $tenant = $this->tenant('owner');
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'failed.example.com',
            'cloudflare_custom_hostname_id' => null,
            'provisioning_status' => TenantDomain::PROVISIONING_FAILED,
            'provisioning_error' => 'We could not reach the server for this domain. Enter a valid hosting IP or server domain before continuing.',
        ]);

        $edge = Mockery::mock(EdgeShieldService::class);
        $this->app->instance(EdgeShieldService::class, $edge);
        $edge->shouldReceive('refreshSaasCustomHostname')->never();
        $edge->shouldReceive('purgeDomainConfigCache')->never();

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->post('/domains/failed.example.com/sync-route');

        $response->assertRedirect()->assertSessionHas(
            'error',
            'We could not reach the server for this domain. Enter a valid hosting IP or server domain before continuing.'
        );
    }

    public function test_non_admin_can_open_tuning_for_failed_local_domain_to_fix_origin(): void
    {
        $tenant = $this->tenant('owner');
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'failed.example.com',
            'origin_server' => '203.0.113.10',
            'cloudflare_custom_hostname_id' => null,
            'provisioning_status' => TenantDomain::PROVISIONING_FAILED,
            'provisioning_error' => 'Old failure',
            'security_mode' => 'balanced',
        ]);

        $edge = Mockery::mock(EdgeShieldService::class);
        $this->app->instance(EdgeShieldService::class, $edge);
        $edge->shouldReceive('getDomainConfig')->once()->with('failed.example.com', (string) $tenant->id, false)->andReturn([
            'ok' => false,
            'error' => 'Domain not found.',
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get('/domains/failed.example.com/tuning');

        $response->assertOk()
            ->assertSee('Protection settings for failed.example.com')
            ->assertSee('203.0.113.10');
    }

    public function test_non_admin_cannot_refresh_another_tenant_domain(): void
    {
        $owner = $this->tenant('owner');
        $other = $this->tenant('other');
        TenantDomain::query()->create([
            'tenant_id' => $owner->id,
            'hostname' => 'locked.example.com',
        ]);

        $edge = Mockery::mock(EdgeShieldService::class);
        $this->app->instance(EdgeShieldService::class, $edge);
        $edge->shouldReceive('refreshSaasCustomHostname')->never();
        $edge->shouldReceive('purgeDomainConfigCache')->never();

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $other->id,
        ])->post('/domains/locked.example.com/sync-route');

        $response->assertRedirect()->assertSessionHas('error', 'We could not refresh this domain yet. Please try again in a few minutes.');
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => ucfirst($slug).' Tenant',
            'slug' => $slug.'-tenant',
            'plan' => 'starter',
            'status' => 'active',
        ]);
    }
}
