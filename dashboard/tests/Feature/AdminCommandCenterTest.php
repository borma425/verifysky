<?php

namespace Tests\Feature;

use App\Actions\Firewall\CreateFirewallRuleAction;
use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantMembership;
use App\Models\TenantUsage;
use App\Models\User;
use App\Repositories\DomainConfigRepository;
use App\Repositories\SecurityLogRepository;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AdminCommandCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        Mockery::close();
        parent::tearDown();
    }

    public function test_non_admin_users_cannot_access_admin_console(): void
    {
        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => '1',
        ])->get(route('admin.overview'));

        $response->assertNotFound();
    }

    public function test_admin_tenant_index_uses_preloaded_view_data(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme Ops',
            'slug' => 'acme-ops',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'secret',
            'role' => 'user',
        ]);
        TenantMembership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);
        TenantUsage::query()->create([
            'tenant_id' => $tenant->id,
            'cycle_start_at' => '2026-04-01 00:00:00',
            'cycle_end_at' => '2026-05-01 00:00:00',
            'protected_sessions_used' => 12000,
            'bot_requests_used' => 900,
            'quota_status' => TenantUsage::STATUS_PASS_THROUGH,
        ]);

        Model::preventLazyLoading(true);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->get(route('admin.tenants.index'));

        $response->assertOk()
            ->assertSee('Client Billing Operations')
            ->assertSee('Acme Ops')
            ->assertSee('Manage Account')
            ->assertSee('pass through')
            ->assertSee('12,000 / 10,000', false);
    }

    public function test_admin_can_open_tenant_drill_down(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Drill Tenant',
            'slug' => 'drill-tenant',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'drill.example.com',
        ]);
        TenantUsage::query()->create([
            'tenant_id' => $tenant->id,
            'cycle_start_at' => '2026-04-01 00:00:00',
            'cycle_end_at' => '2026-05-01 00:00:00',
            'protected_sessions_used' => 10,
            'bot_requests_used' => 5,
            'quota_status' => TenantUsage::STATUS_ACTIVE,
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->get(route('admin.tenants.show', $tenant));

        $response->assertOk()
            ->assertSee('Drill Tenant')
            ->assertSee('Manual Grants')
            ->assertSee('Memberships')
            ->assertSee('drill.example.com')
            ->assertSee('Manage Domain');
    }

    public function test_admin_cannot_use_mismatched_tenant_domain_pair(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'plan' => 'starter', 'status' => 'active']);
        $other = Tenant::query()->create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'plan' => 'starter', 'status' => 'active']);
        TenantDomain::query()->create([
            'tenant_id' => $other->id,
            'hostname' => 'owned-by-b.example.com',
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->get(route('admin.tenants.domains.show', [$tenant, 'owned-by-b.example.com']));

        $response->assertNotFound();
    }

    public function test_admin_domain_runtime_purge_queues_existing_job(): void
    {
        Queue::fake();
        $tenant = Tenant::query()->create(['name' => 'Purge Tenant', 'slug' => 'purge-tenant', 'plan' => 'starter', 'status' => 'active']);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'purge.example.com',
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->post(route('admin.tenants.domains.runtime_cache.purge', [$tenant, 'purge.example.com']));

        $response->assertRedirect();
        Queue::assertPushed(PurgeRuntimeBundleCache::class, fn (PurgeRuntimeBundleCache $job): bool => $job->domain === 'purge.example.com');
    }

    public function test_admin_domain_status_polling_is_scoped_to_tenant(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Status Tenant', 'slug' => 'status-tenant', 'plan' => 'starter', 'status' => 'active']);

        $repository = Mockery::mock(DomainConfigRepository::class);
        $repository->shouldReceive('listForTenant')->once()->with((string) $tenant->id, false)->andReturn([
            'ok' => true,
            'error' => null,
            'domains' => [[
                'domain_name' => 'www.status.example',
                'status' => 'pending',
                'security_mode' => 'balanced',
                'hostname_status' => 'pending',
                'ssl_status' => 'pending_validation',
                'provisioning_status' => TenantDomain::PROVISIONING_PENDING,
                'cname_target' => 'customers.verifysky.com',
                'created_at' => '2026-04-28 10:00:00',
                'updated_at' => '2026-04-28 10:00:00',
            ]],
        ]);
        $this->app->instance(DomainConfigRepository::class, $repository);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->getJson(route('admin.tenants.domains.statuses', $tenant));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('polling', true)
            ->assertJsonPath('groups.0.live_status.label', 'QUEUED');
    }

    public function test_admin_firewall_create_is_scoped_to_route_tenant_domain(): void
    {
        Queue::fake();

        $tenant = Tenant::query()->create(['name' => 'Firewall Tenant', 'slug' => 'firewall-tenant', 'plan' => 'starter', 'status' => 'active']);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'fw.example.com',
        ]);

        $limits = Mockery::mock(PlanLimitsService::class);
        $limits->shouldReceive('getFirewallRulesUsage')->once()->with((string) $tenant->id, false)->andReturn(['can_add' => true]);
        $this->app->instance(PlanLimitsService::class, $limits);

        $create = Mockery::mock(CreateFirewallRuleAction::class);
        $create->shouldReceive('execute')->once()->with(Mockery::on(
            fn (array $payload): bool => ($payload['domain_name'] ?? null) === 'fw.example.com'
                && ($payload['value'] ?? null) === 'bot'
        ))->andReturn(['ok' => true]);
        $this->app->instance(CreateFirewallRuleAction::class, $create);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->post(route('admin.tenants.domains.firewall.store', [$tenant, 'fw.example.com']), [
            'description' => 'Block bot',
            'action' => 'managed_challenge',
            'field' => 'http.user_agent',
            'operator' => 'contains',
            'value' => 'bot',
            'duration' => '24h',
            'paused' => '0',
        ]);

        $response->assertRedirect()->assertSessionHas('status');
        Queue::assertPushed(PurgeRuntimeBundleCache::class, fn (PurgeRuntimeBundleCache $job): bool => $job->domain === 'fw.example.com');
    }

    public function test_admin_system_log_pages_render(): void
    {
        $repository = Mockery::mock(SecurityLogRepository::class);
        $repository->shouldReceive('fetchIndexPayload')->once()->andReturn([
            'ok' => true,
            'rows' => [],
            'total' => 0,
            'per_page' => 50,
            'page' => 1,
            'filters' => [],
            'filter_options' => ['domains' => [], 'events' => []],
            'general_stats' => [],
        ]);
        $this->app->instance(SecurityLogRepository::class, $repository);

        $session = ['is_authenticated' => true, 'is_admin' => true];
        $this->withSession($session)->get(route('admin.logs.security'))
            ->assertOk()
            ->assertSee('System Logs')
            ->assertSee('Security Logs');
        $this->withSession($session)->get(route('admin.logs.platform'))
            ->assertOk()
            ->assertSee('Platform Logs');
    }
}
