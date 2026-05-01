<?php

namespace Tests\Feature;

use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SensitivePathsRuntimePurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_tenant_global_sensitive_path_purges_each_tenant_domain(): void
    {
        $tenant = $this->tenantWithDomains();

        $edge = Mockery::mock(EdgeShieldService::class);
        $edge->shouldReceive('createSensitivePath')
            ->once()
            ->with('global', '/admin', 'contains', 'block', false, (string) $tenant->id, 'tenant')
            ->andReturn(['ok' => true, 'error' => null]);
        $edge->shouldNotReceive('purgeSensitivePathsCache');
        $this->app->instance(EdgeShieldService::class, $edge);

        $this->withTenantSession($tenant)->post(route('sensitive_paths.store'), [
            'paths' => [[
                'domain_name' => 'global',
                'path_pattern' => '/admin',
                'match_type' => 'contains',
                'action' => 'block',
            ]],
        ])->assertRedirect(route('sensitive_paths.index'));

        Queue::assertPushed(PurgeRuntimeBundleCache::class, fn (PurgeRuntimeBundleCache $job): bool => $job->domain === 'one.example.com');
        Queue::assertPushed(PurgeRuntimeBundleCache::class, fn (PurgeRuntimeBundleCache $job): bool => $job->domain === 'two.example.com');
    }

    public function test_domain_sensitive_path_purges_selected_domain_only(): void
    {
        $tenant = $this->tenantWithDomains();

        $edge = Mockery::mock(EdgeShieldService::class);
        $edge->shouldReceive('listDomains')->once()->with((string) $tenant->id, false)->andReturn([
            'ok' => true,
            'domains' => [['domain_name' => 'one.example.com']],
        ]);
        $edge->shouldReceive('createSensitivePath')
            ->once()
            ->with('one.example.com', '/login', 'contains', 'challenge', false, (string) $tenant->id, 'domain')
            ->andReturn(['ok' => true, 'error' => null]);
        $edge->shouldReceive('purgeSensitivePathsCache')->once()->with('one.example.com')->andReturn(['ok' => true]);
        $this->app->instance(EdgeShieldService::class, $edge);

        $this->withTenantSession($tenant)->post(route('sensitive_paths.store'), [
            'paths' => [[
                'domain_name' => 'one.example.com',
                'path_pattern' => '/login',
                'match_type' => 'contains',
                'action' => 'challenge',
            ]],
        ])->assertRedirect(route('sensitive_paths.index'));

        Queue::assertNotPushed(PurgeRuntimeBundleCache::class);
    }

    public function test_sensitive_paths_index_preserves_form_and_table_controls(): void
    {
        $tenant = $this->tenantWithDomains();

        $edge = Mockery::mock(EdgeShieldService::class);
        $edge->shouldReceive('listTenantSensitivePaths')->once()->with((string) $tenant->id)->andReturn([
            'ok' => true,
            'paths' => [
                [
                    'id' => 11,
                    'domain_name' => 'global',
                    'path_pattern' => '.env',
                    'match_type' => 'ends_with',
                    'action' => 'block',
                ],
                [
                    'id' => 12,
                    'domain_name' => 'one.example.com',
                    'path_pattern' => '/login',
                    'match_type' => 'exact',
                    'action' => 'challenge',
                ],
            ],
        ]);
        $edge->shouldReceive('listDomains')->once()->with((string) $tenant->id, false)->andReturn([
            'ok' => true,
            'domains' => [
                ['domain_name' => 'one.example.com', 'status' => 'active'],
            ],
        ]);
        $this->app->instance(EdgeShieldService::class, $edge);

        $this->withTenantSession($tenant)->get(route('sensitive_paths.index'))
            ->assertOk()
            ->assertSee('Sensitive Paths Protection')
            ->assertSee('Protect New Sensitive Path')
            ->assertSee('name="paths[0][match_type]"', false)
            ->assertSee('name="paths[0][path_pattern]"', false)
            ->assertSee('name="paths[0][domain_name]"', false)
            ->assertSee('name="paths[0][action]"', false)
            ->assertSee('id="bulk-paths-form"', false)
            ->assertSee('id="paths-container"', false)
            ->assertSee('class="path-row', false)
            ->assertSee('js-add-path-row', false)
            ->assertSee('Hard Block')
            ->assertSee('Forced Challenge')
            ->assertSee('id="selectAllCritical"', false)
            ->assertSee('id="selectAllMedium"', false)
            ->assertSee('class="rule-cb-crit', false)
            ->assertSee('class="rule-cb-med', false)
            ->assertSee('id="singleUnlockForm"', false);
    }

    private function tenantWithDomains(): Tenant
    {
        $tenant = Tenant::query()->create([
            'name' => 'Sensitive Tenant',
            'slug' => 'sensitive-tenant',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        foreach (['one.example.com', 'two.example.com'] as $hostname) {
            TenantDomain::query()->create([
                'tenant_id' => $tenant->id,
                'hostname' => $hostname,
            ]);
        }

        return $tenant;
    }

    private function withTenantSession(Tenant $tenant): self
    {
        return $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ]);
    }
}
