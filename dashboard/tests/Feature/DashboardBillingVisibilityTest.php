<?php

namespace Tests\Feature;

use App\Actions\Domains\DeleteDomainAction;
use App\Actions\Domains\DeleteDomainGroupAction;
use App\Actions\Domains\ProvisionTenantDomainAction;
use App\Actions\Domains\RefreshDomainGroupVerificationAction;
use App\Actions\Domains\RefreshDomainVerificationAction;
use App\Actions\Domains\ToggleDomainForceCaptchaAction;
use App\Actions\Domains\UpdateDomainOriginAction;
use App\Actions\Domains\UpdateDomainSecurityModeAction;
use App\Actions\Domains\UpdateDomainStatusAction;
use App\Actions\Domains\UpdateDomainThresholdsAction;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantPlanGrant;
use App\Models\TenantUsage;
use App\Repositories\DomainConfigRepository;
use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class DashboardBillingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_regular_tenant_dashboard_shows_billing_widget(): void
    {
        $tenant = $this->makeTenant('visible-tenant');
        TenantUsage::query()->create([
            'tenant_id' => $tenant->id,
            'cycle_start_at' => '2026-04-01 00:00:00',
            'cycle_end_at' => '2026-05-01 00:00:00',
            'protected_sessions_used' => 600,
            'bot_requests_used' => 1200,
            'quota_status' => TenantUsage::STATUS_ACTIVE,
        ]);

        $this->bindDashboardEdgeShield();

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get('/dashboard');

        $response->assertOk()
            ->assertSee('Protected Sessions')
            ->assertSee('600')
            ->assertSee('/ 10,000', false)
            ->assertSee('Bot Requests Rejected')
            ->assertDontSee('Your current VerifySky quota has been exhausted.');
    }

    public function test_admin_is_redirected_away_from_customer_dashboard(): void
    {
        $this->bindDashboardEdgeShield();

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->get('/dashboard');

        $response->assertRedirect(route('admin.overview'));
    }

    public function test_pass_through_banner_is_visible_on_multiple_dashboard_pages(): void
    {
        $tenant = $this->makeTenant('pass-through-banner-tenant');
        TenantUsage::query()->create([
            'tenant_id' => $tenant->id,
            'cycle_start_at' => '2026-04-01 00:00:00',
            'cycle_end_at' => '2026-05-01 00:00:00',
            'protected_sessions_used' => 12000,
            'bot_requests_used' => 500,
            'quota_status' => TenantUsage::STATUS_PASS_THROUGH,
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);

        $this->bindDashboardEdgeShield();
        $this->bindDomainsPageDependencies($tenant);

        $dashboardResponse = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get('/dashboard');

        $domainsResponse = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get(route('domains.index'));

        $dashboardResponse->assertSee('Your current VerifySky quota has been exhausted.');
        $domainsResponse->assertSee('Your current VerifySky quota has been exhausted.');
    }

    public function test_banner_and_widget_do_not_render_on_marketing_or_login_pages(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('Your current VerifySky quota has been exhausted.')
            ->assertDontSee('Protected Sessions');

        $this->get('/wow/login')
            ->assertOk()
            ->assertDontSee('Your current VerifySky quota has been exhausted.')
            ->assertDontSee('Protected Sessions');
    }

    public function test_dashboard_shows_active_manual_grant_notice_and_effective_limits(): void
    {
        $tenant = $this->makeTenant('visible-grant-tenant');
        TenantUsage::query()->create([
            'tenant_id' => $tenant->id,
            'cycle_start_at' => '2026-04-01 00:00:00',
            'cycle_end_at' => '2026-05-01 00:00:00',
            'protected_sessions_used' => 600,
            'bot_requests_used' => 1200,
            'quota_status' => TenantUsage::STATUS_ACTIVE,
        ]);
        TenantPlanGrant::query()->create([
            'tenant_id' => $tenant->id,
            'granted_plan_key' => 'pro',
            'source' => 'manual',
            'status' => TenantPlanGrant::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->bindDashboardEdgeShield();

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get('/dashboard');

        $response->assertOk()
            ->assertSee('Manual PRO grant active until')
            ->assertSee('/ 100,000', false);
    }

    public function test_dashboard_gracefully_skips_billing_widget_when_billing_tables_are_missing(): void
    {
        $tenant = $this->makeTenant('billing-schema-pending');
        Schema::drop('tenant_usage');

        $this->bindDashboardEdgeShield();

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get('/dashboard');

        $response->assertOk()
            ->assertDontSee('Protected Sessions')
            ->assertDontSee('Bot Requests Rejected')
            ->assertDontSee('Your current VerifySky quota has been exhausted.');
    }

    public function test_customer_dashboard_stats_are_scoped_to_current_tenant_domains(): void
    {
        Cache::flush();
        $tenant = $this->makeTenant('tenant-a');
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'tenant-a.example.com',
        ]);
        $otherTenant = $this->makeTenant('tenant-b');
        TenantDomain::query()->create([
            'tenant_id' => $otherTenant->id,
            'hostname' => 'tenant-b.example.com',
        ]);

        $edgeShield = Mockery::mock(EdgeShieldService::class);
        $edgeShield->shouldReceive('queryD1')
            ->once()
            ->with(Mockery::on(function (string $sql): bool {
                return str_contains($sql, "domain_name IN ('tenant-a.example.com')")
                    && ! str_contains($sql, 'tenant-b.example.com');
            }), 25)
            ->andReturn(['ok' => true, 'output' => '[]']);
        $edgeShield->shouldReceive('parseWranglerJson')->once()->andReturn([
            ['results' => [['active_domains' => 1]]],
            ['results' => [['total_attacks_today' => 3, 'total_visitors_today' => 9]]],
            ['results' => []],
            ['results' => [['domain_name' => 'tenant-a.example.com', 'attack_count' => 3]]],
            ['results' => []],
        ]);
        $this->app->instance(EdgeShieldService::class, $edgeShield);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get('/dashboard');

        $response->assertOk()
            ->assertSee('tenant-a.example.com')
            ->assertDontSee('tenant-b.example.com');
    }

    public function test_customer_dashboard_without_domains_does_not_query_global_stats(): void
    {
        Cache::flush();
        $tenant = $this->makeTenant('tenant-without-domains');

        $edgeShield = Mockery::mock(EdgeShieldService::class);
        $edgeShield->shouldNotReceive('queryD1');
        $edgeShield->shouldNotReceive('parseWranglerJson');
        $this->app->instance(EdgeShieldService::class, $edgeShield);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get('/dashboard');

        $response->assertOk()
            ->assertSee('0 active')
            ->assertSee('No attacks recorded today.');
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
    }

    private function bindDashboardEdgeShield(): void
    {
        $edgeShield = Mockery::mock(EdgeShieldService::class);
        $edgeShield->shouldIgnoreMissing();
        $edgeShield->shouldReceive('queryD1')->andReturn([
            'ok' => true,
            'output' => json_encode([
                ['results' => [['active_domains' => 1]]],
                ['results' => [['total_attacks_today' => 2, 'total_visitors_today' => 8]]],
                ['results' => []],
                ['results' => []],
                ['results' => []],
            ]),
        ]);
        $edgeShield->shouldReceive('parseWranglerJson')->andReturn([
            ['results' => [['active_domains' => 1]]],
            ['results' => [['total_attacks_today' => 2, 'total_visitors_today' => 8]]],
            ['results' => []],
            ['results' => []],
            ['results' => []],
        ]);
        $this->app->instance(EdgeShieldService::class, $edgeShield);
    }

    private function bindDomainsPageDependencies(Tenant $tenant): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $repository = Mockery::mock(DomainConfigRepository::class);
        $repository->shouldReceive('listForTenant')->once()->with((string) $tenant->id, false)->andReturn([
            'ok' => true,
            'error' => null,
            'domains' => [[
                'domain_name' => 'example.com',
                'zone_id' => 'zone1',
                'cname_target' => 'customers.verifysky.com',
                'hostname_status' => 'active',
                'ssl_status' => 'active',
                'status' => 'active',
                'force_captcha' => 0,
                'security_mode' => 'balanced',
                'created_at' => '2026-03-22 00:00:00',
            ]],
        ]);
        $this->app->instance(DomainConfigRepository::class, $repository);
        $this->app->instance(ProvisionTenantDomainAction::class, Mockery::mock(ProvisionTenantDomainAction::class));
        $this->app->instance(RefreshDomainVerificationAction::class, Mockery::mock(RefreshDomainVerificationAction::class));
        $this->app->instance(RefreshDomainGroupVerificationAction::class, Mockery::mock(RefreshDomainGroupVerificationAction::class));
        $this->app->instance(DeleteDomainAction::class, Mockery::mock(DeleteDomainAction::class));
        $this->app->instance(DeleteDomainGroupAction::class, Mockery::mock(DeleteDomainGroupAction::class));
        $this->app->instance(UpdateDomainOriginAction::class, Mockery::mock(UpdateDomainOriginAction::class));
        $this->app->instance(UpdateDomainSecurityModeAction::class, Mockery::mock(UpdateDomainSecurityModeAction::class));
        $this->app->instance(UpdateDomainStatusAction::class, Mockery::mock(UpdateDomainStatusAction::class));
        $this->app->instance(ToggleDomainForceCaptchaAction::class, Mockery::mock(ToggleDomainForceCaptchaAction::class));
        $this->app->instance(UpdateDomainThresholdsAction::class, Mockery::mock(UpdateDomainThresholdsAction::class));
        $this->app->instance(PlanLimitsService::class, new PlanLimitsService(Mockery::mock(D1DatabaseClient::class)));
    }
}
