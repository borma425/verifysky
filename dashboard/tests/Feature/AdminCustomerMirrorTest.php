<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantUsage;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AdminCustomerMirrorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_admin_sidebar_only_contains_admin_navigation(): void
    {
        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
            'user_email' => 'admin@example.test',
        ])->get(route('admin.overview'));

        $response->assertOk()
            ->assertSee('Overview')
            ->assertSee('Clients')
            ->assertSee('Settings')
            ->assertSee('System Logs')
            ->assertDontSee('Global Firewall')
            ->assertDontSee('IP Farm')
            ->assertDontSee('Sensitive Paths')
            ->assertDontSee('Customer Dashboard');
    }

    public function test_customer_layout_does_not_render_admin_sidebar_items(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Customer Tenant',
            'slug' => 'customer-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get(route('billing.index'));

        $response->assertOk()
            ->assertSee('Billing')
            ->assertDontSee('Tenants')
            ->assertDontSee(route('admin.settings.index'), false)
            ->assertDontSee('Platform Settings')
            ->assertDontSee('System Logs');
    }

    public function test_admin_is_redirected_away_from_customer_routes(): void
    {
        $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->get('/dashboard')->assertRedirect(route('admin.overview'));

        $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->get('/domains')->assertRedirect(route('admin.overview'));

        $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->get('/settings')->assertRedirect(route('admin.overview'));
    }

    public function test_tenant_show_links_to_unified_admin_console(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Mirror Tenant',
            'slug' => 'mirror-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
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
            ->assertSee(route('admin.tenants.firewall.index', $tenant), false)
            ->assertSee(route('admin.tenants.sensitive_paths.index', $tenant), false)
            ->assertSee(route('admin.tenants.ip_farm.index', $tenant), false);
    }

    public function test_non_admin_cannot_open_customer_mirror_routes(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Blocked Tenant',
            'slug' => 'blocked-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get(route('admin.tenants.customer.billing.index', $tenant));

        $response->assertNotFound();
    }

    public function test_admin_customer_mirror_renders_only_selected_tenant_context(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Alpha Tenant',
            'slug' => 'alpha-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
        Tenant::query()->create([
            'name' => 'Bravo Tenant',
            'slug' => 'bravo-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
            'user_id' => 99,
            'user_email' => 'admin@example.test',
        ])->get(route('admin.tenants.customer.billing.index', $tenant));

        $response->assertOk()
            ->assertSee('Viewing as customer: Alpha Tenant')
            ->assertDontSee('Bravo Tenant');
    }

    public function test_customer_mirror_blocks_mutation_attempts_without_changing_session_context(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Read Only Tenant',
            'slug' => 'read-only-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
            'current_tenant_id' => 'locked-tenant',
            'user_email' => 'admin@example.test',
        ])->post('/admin/tenants/'.$tenant->id.'/customer/firewall');

        $response->assertForbidden();
        $response->assertSessionHas('current_tenant_id', 'locked-tenant');
    }

    public function test_opening_customer_mirror_records_audit_event(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Audit Tenant',
            'slug' => 'audit-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);

        $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
            'user_id' => 41,
            'user_email' => 'admin@example.test',
        ])->get(route('admin.tenants.customer.billing.index', $tenant))
            ->assertOk();

        $this->assertDatabaseHas('admin_impersonation_events', [
            'admin_user_id' => 41,
            'admin_email' => 'admin@example.test',
            'tenant_id' => $tenant->id,
            'route_action' => 'admin.tenants.customer.billing.index',
        ]);
    }

    public function test_admin_customer_mirror_firewall_hides_ip_farm_rules_and_uses_global_labeling(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Mirror Firewall Tenant',
            'slug' => 'mirror-firewall-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'www.mirror-firewall.test',
        ]);

        $edge = Mockery::mock(EdgeShieldService::class);
        $edge->shouldReceive('listDomains')->once()->with((string) $tenant->id, false)->andReturn([
            'ok' => true,
            'domains' => [['domain_name' => 'www.mirror-firewall.test']],
        ]);
        $edge->shouldReceive('listAllCustomFirewallRules')->once()->andReturn([
            'ok' => true,
            'rules' => [[
                'id' => 11,
                'domain_name' => 'www.mirror-firewall.test',
                'description' => 'Allow trusted automation',
                'action' => 'allow',
                'expression_json' => json_encode(['field' => 'ip.src.asnum', 'operator' => 'eq', 'value' => '12345']),
                'paused' => 0,
                'expires_at' => null,
            ]],
        ]);
        $this->app->instance(EdgeShieldService::class, $edge);

        $limits = Mockery::mock(PlanLimitsService::class);
        $limits->shouldReceive('managedDomainNames')->once()->with((string) $tenant->id, false)->andReturn(['www.mirror-firewall.test']);
        $limits->shouldReceive('getFirewallRulesUsage')->once()->with((string) $tenant->id, false)->andReturn([
            'used' => 1,
            'limit' => 5,
            'remaining' => 4,
            'can_add' => true,
            'plan_name' => 'Starter',
            'message' => null,
        ]);
        $this->app->instance(PlanLimitsService::class, $limits);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
            'user_id' => 99,
            'user_email' => 'admin@example.test',
        ])->get(route('admin.tenants.customer.firewall.index', $tenant));

        $response->assertOk()
            ->assertSee('Firewall')
            ->assertSee('Rules for all domains')
            ->assertSee('Allow trusted automation')
            ->assertDontSee('[IP-FARM]');
    }
}
