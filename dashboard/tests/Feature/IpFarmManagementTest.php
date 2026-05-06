<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class IpFarmManagementTest extends TestCase
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

    public function test_user_ip_farm_index_is_tenant_scoped_and_shows_controls(): void
    {
        [$tenant] = $this->tenantWithDomain('cashup', 'www.cashup.cash');
        $edge = $this->bindEdgeShieldMock();
        $edge->shouldReceive('listIpFarmRules')->once()->with((string) $tenant->id)->andReturn([
            'ok' => true,
            'rules' => [
                [
                    'id' => 9,
                    'domain_name' => 'global',
                    'tenant_id' => (string) $tenant->id,
                    'scope' => 'tenant',
                    'description' => '[IP-FARM] Manual list (2 IPs)',
                    'action' => 'block',
                    'expression_json' => json_encode(['field' => 'ip.src', 'operator' => 'in', 'value' => '203.0.113.10, 198.51.100.0/24']),
                    'paused' => 0,
                    'created_at' => '2026-04-21 10:00:00',
                    'updated_at' => '2026-04-21 10:00:00',
                ],
            ],
        ]);
        $edge->shouldReceive('listDomains')->once()->with((string) $tenant->id, false)->andReturn([
            'ok' => true,
            'domains' => [['domain_name' => 'www.cashup.cash']],
        ]);
        $edge->shouldReceive('getIpFarmStats')->once()->with((string) $tenant->id)->andReturn([
            'totalIps' => 2,
            'totalRules' => 1,
            'lastUpdated' => '2026-04-21 10:00:00',
        ]);
        $this->bindLimitsMock($tenant, true);

        $response = $this->withTenantSession($tenant)->get(route('ip_farm.index'));

        $response->assertOk()
            ->assertSee('Create block list')
            ->assertSee('Apply to')
            ->assertSee('All domains')
            ->assertDontSee('Specific domain')
            ->assertDontSee('Remove Targets From All Farms')
            ->assertSee('Add to list')
            ->assertSee('Delete list')
            ->assertSee('203.0.113.10')
            ->assertDontSee('Allow trusted ASN');
    }

    public function test_user_can_create_tenant_scoped_ip_farm_within_plan_limit(): void
    {
        [$tenant] = $this->tenantWithDomain('cashup', 'www.cashup.cash');
        $edge = $this->bindEdgeShieldMock();
        $edge->shouldReceive('createIpFarmRule')
            ->once()
            ->with('global', 'Ready Farm', ['203.0.113.10', '198.51.100.0/24'], false, (string) $tenant->id, 'tenant')
            ->andReturn(['ok' => true, 'added' => 2]);
        $this->bindLimitsMock($tenant, true);

        $response = $this->withTenantSession($tenant)->from(route('ip_farm.index'))->post(route('ip_farm.store'), [
            'scope' => 'tenant',
            'domain_name' => 'www.cashup.cash',
            'description' => 'Ready Farm',
            'ips' => "203.0.113.10\n198.51.100.0/24",
            'paused' => '0',
        ]);

        $response->assertRedirect(route('ip_farm.index'));
        $response->assertSessionHas('status', 'Blocked IP list saved with 2 IP(s).');
    }

    public function test_user_can_create_global_ip_farm_like_global_firewall_target_environment(): void
    {
        [$tenant] = $this->tenantWithDomain('cashup', 'www.cashup.cash');
        $edge = $this->bindEdgeShieldMock();
        $edge->shouldReceive('createIpFarmRule')
            ->once()
            ->with('global', 'Global Farm', ['203.0.113.10'], false, (string) $tenant->id, 'tenant')
            ->andReturn(['ok' => true, 'added' => 1]);
        $this->bindLimitsMock($tenant, true);

        $response = $this->withTenantSession($tenant)->from(route('ip_farm.index'))->post(route('ip_farm.store'), [
            'domain_name' => 'global',
            'description' => 'Global Farm',
            'ips' => '203.0.113.10',
        ]);

        $response->assertRedirect(route('ip_farm.index'));
        $response->assertSessionHas('status', 'Blocked IP list saved with 1 IP(s).');
    }

    public function test_user_cannot_create_ip_farm_after_plan_limit(): void
    {
        [$tenant] = $this->tenantWithDomain('cashup', 'www.cashup.cash');
        $edge = $this->bindEdgeShieldMock();
        $edge->shouldNotReceive('createIpFarmRule');
        $this->bindLimitsMock($tenant, false);

        $response = $this->withTenantSession($tenant)->from(route('ip_farm.index'))->post(route('ip_farm.store'), [
            'scope' => 'tenant',
            'domain_name' => 'www.cashup.cash',
            'description' => 'Ready Farm',
            'ips' => '203.0.113.10',
        ]);

        $response->assertRedirect(route('ip_farm.index'));
        $response->assertSessionHas('error', 'Limit reached.');
    }

    public function test_user_can_append_remove_and_delete_owned_farm(): void
    {
        [$tenant] = $this->tenantWithDomain('cashup', 'www.cashup.cash');
        $edge = $this->bindEdgeShieldMock();
        $this->bindLimitsMock($tenant, true);

        $edge->shouldReceive('appendIpsToFarmRule')->once()->with(7, ['203.0.113.20'], (string) $tenant->id)->andReturn(['ok' => true, 'added' => 1]);
        $this->withTenantSession($tenant)->post(route('ip_farm.append', 7), ['ips' => '203.0.113.20'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Added 1 IP(s) to the blocked list.');

        $edge->shouldReceive('removeIpsFromFarmRule')->once()->with(7, ['203.0.113.20'], (string) $tenant->id)->andReturn(['ok' => true, 'removed' => 1]);
        $this->withTenantSession($tenant)->post(route('ip_farm.remove_ips', 7), ['ips' => '203.0.113.20'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Removed 1 IP(s) from the blocked list.');

        $edge->shouldReceive('deleteIpFarmRule')->once()->with(7, (string) $tenant->id)->andReturn(['ok' => true]);
        $this->withTenantSession($tenant)->delete(route('ip_farm.destroy', 7))
            ->assertRedirect()
            ->assertSessionHas('status', 'Blocked IP rule deleted.');
    }

    private function bindEdgeShieldMock(): MockInterface
    {
        $edge = Mockery::mock(EdgeShieldService::class);
        $this->app->instance(EdgeShieldService::class, $edge);

        return $edge;
    }

    private function bindLimitsMock(Tenant $tenant, bool $canAdd): void
    {
        $limits = Mockery::mock(PlanLimitsService::class);
        $limits->shouldReceive('getFirewallRulesUsage')->byDefault()->with((string) $tenant->id, false)->andReturn([
            'used' => $canAdd ? 1 : 5,
            'limit' => 5,
            'remaining' => $canAdd ? 4 : 0,
            'can_add' => $canAdd,
            'plan_name' => 'Starter',
            'message' => $canAdd ? null : 'Limit reached.',
        ]);
        $limits->shouldReceive('getBillingUsageLimits')->byDefault()->with(Mockery::type(Tenant::class))->andReturn([
            'protected_sessions' => 10000,
            'bot_fair_use' => 25000,
            'plan_key' => 'starter',
            'plan_name' => 'Starter',
        ]);
        $this->app->instance(PlanLimitsService::class, $limits);
    }

    private function tenantWithDomain(string $slug, string $hostname): array
    {
        $tenant = Tenant::query()->create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'plan' => 'starter',
            'status' => 'active',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => $hostname,
        ]);

        return [$tenant];
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
