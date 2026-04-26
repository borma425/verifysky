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
