<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantPlanGrant;
use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PlanLimitsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_standard_plan_alias_resolves_to_starter_firewall_limit(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Alias Tenant',
            'slug' => 'alias-tenant',
            'plan' => 'standard',
            'status' => 'active',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);

        $d1 = Mockery::mock(D1DatabaseClient::class);
        $d1->shouldReceive('query')->once()->andReturn([
            'ok' => true,
            'output' => json_encode([
                ['results' => [['total' => 5]]],
            ]),
        ]);
        $d1->shouldReceive('parseWranglerJson')->once()->andReturn([
            ['results' => [['total' => 5]]],
        ]);

        $service = new PlanLimitsService($d1);
        $usage = $service->getFirewallRulesUsage((string) $tenant->id, false);

        $this->assertSame('starter', $usage['plan_key']);
        $this->assertSame('Starter', $usage['plan_name']);
        $this->assertSame(5, $usage['limit']);
        $this->assertSame(5, $usage['used']);
        $this->assertFalse($usage['can_add']);
    }

    public function test_starter_plan_domain_usage_blocks_additional_domain(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Starter Tenant',
            'slug' => 'starter-tenant',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);

        $service = new PlanLimitsService(Mockery::mock(D1DatabaseClient::class));
        $usage = $service->getDomainsUsage($tenant);

        $this->assertSame(1, $usage['used']);
        $this->assertSame(1, $usage['limit']);
        $this->assertFalse($usage['can_add']);
    }

    public function test_growth_plan_domain_usage_allows_adding_second_domain(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Growth Tenant',
            'slug' => 'growth-tenant',
            'plan' => 'growth',
            'status' => 'active',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);

        $service = new PlanLimitsService(Mockery::mock(D1DatabaseClient::class));

        $this->assertTrue($service->canAddDomain($tenant));
        $this->assertTrue($service->getDomainsUsage($tenant)['can_add']);
    }

    public function test_domain_usage_includes_extra_and_bonus_allowance_from_tenant_settings(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Bonus Tenant',
            'slug' => 'bonus-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'settings' => [
                'extra_domains' => 1,
                'bonus_domains' => 2,
            ],
        ]);

        foreach (['one.example.com', 'two.example.com', 'three.example.com'] as $hostname) {
            TenantDomain::query()->create([
                'tenant_id' => $tenant->id,
                'hostname' => $hostname,
            ]);
        }

        $service = new PlanLimitsService(Mockery::mock(D1DatabaseClient::class));
        $usage = $service->getDomainsUsage($tenant);

        $this->assertSame(3, $usage['used']);
        $this->assertSame(1, $usage['included_limit']);
        $this->assertSame(3, $usage['extra_allowance']);
        $this->assertSame(4, $usage['limit']);
        $this->assertTrue($usage['can_add']);
    }

    public function test_unlimited_plan_returns_null_domain_limit(): void
    {
        config()->set('plans.plans.scale.limits.domains', null);

        $tenant = Tenant::query()->create([
            'name' => 'Scale Tenant',
            'slug' => 'scale-tenant',
            'plan' => 'scale',
            'status' => 'active',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);

        $service = new PlanLimitsService(Mockery::mock(D1DatabaseClient::class));
        $usage = $service->getDomainsUsage($tenant);

        $this->assertSame(1, $usage['used']);
        $this->assertNull($usage['limit']);
        $this->assertTrue($usage['can_add']);
    }

    public function test_non_admin_cannot_manage_global_domain(): void
    {
        $service = new PlanLimitsService(Mockery::mock(D1DatabaseClient::class));

        $this->assertFalse($service->domainBelongsToTenant('global', '1', false));
        $this->assertTrue($service->domainBelongsToTenant('global', '1', true));
    }

    public function test_billing_usage_limits_are_returned_from_plan_config(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Billing Tenant',
            'slug' => 'billing-tenant',
            'plan' => 'growth',
            'status' => 'active',
        ]);

        $service = new PlanLimitsService(Mockery::mock(D1DatabaseClient::class));
        $limits = $service->getBillingUsageLimits($tenant);

        $this->assertSame(30000, $limits['protected_sessions']);
        $this->assertSame(50000, $limits['bot_fair_use']);
        $this->assertSame('growth', $limits['plan_key']);
        $this->assertSame('Growth', $limits['plan_name']);
    }

    public function test_active_manual_grant_overrides_baseline_plan_limits(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Grant Limits Tenant',
            'slug' => 'grant-limits-tenant',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        TenantPlanGrant::query()->create([
            'tenant_id' => $tenant->id,
            'granted_plan_key' => 'pro',
            'source' => 'manual',
            'status' => TenantPlanGrant::STATUS_ACTIVE,
            'starts_at' => now()->subDay()->utc()->toDateTimeString(),
            'ends_at' => now()->addDay()->utc()->toDateTimeString(),
        ]);

        $service = new PlanLimitsService(Mockery::mock(D1DatabaseClient::class));
        $limits = $service->getBillingUsageLimits($tenant);

        $this->assertSame(100000, $limits['protected_sessions']);
        $this->assertSame(100000, $limits['bot_fair_use']);
        $this->assertSame('pro', $limits['plan_key']);
        $this->assertSame('Pro', $limits['plan_name']);
    }
}
