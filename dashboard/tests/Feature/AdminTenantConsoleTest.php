<?php

namespace Tests\Feature;

use App\Actions\Firewall\CreateFirewallRuleAction;
use App\Actions\Firewall\DeleteFirewallRuleAction;
use App\Actions\Firewall\ToggleFirewallRuleAction;
use App\Actions\Firewall\UpdateFirewallRuleAction;
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

class AdminTenantConsoleTest extends TestCase
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

    public function test_domain_firewall_route_renders_unified_tenant_firewall_filtered_to_domain(): void
    {
        [$tenant] = $this->tenantWithDomain('cashup', 'www.cashup.cash');
        $edge = $this->bindEdgeShieldMock();
        $edge->shouldReceive('listTenantCustomFirewallRules')->once()->with((string) $tenant->id)->andReturn([
            'ok' => true,
            'rules' => [
                $this->rule(['id' => 10, 'domain_name' => 'global', 'scope' => 'tenant', 'description' => 'Tenant-wide rule', 'tenant_id' => (string) $tenant->id]),
                $this->rule(['id' => 11, 'domain_name' => 'www.cashup.cash', 'scope' => 'domain', 'description' => 'Domain rule', 'tenant_id' => (string) $tenant->id]),
                $this->rule(['id' => 12, 'domain_name' => 'other.cash', 'scope' => 'domain', 'description' => 'Other domain rule', 'tenant_id' => (string) $tenant->id]),
            ],
        ]);
        $this->bindLimitsMock($tenant);

        $response = $this->withAdminSession()->get(route('admin.tenants.domains.firewall.index', [$tenant, 'www.cashup.cash']));

        $response->assertOk()
            ->assertSee('Global Firewall')
            ->assertSee('Tenant-wide rule')
            ->assertSee('Domain rule')
            ->assertDontSee('Other domain rule')
            ->assertDontSee('[IP-FARM]');
    }

    public function test_admin_creates_tenant_scoped_firewall_rule_without_plan_limit_block(): void
    {
        [$tenant] = $this->tenantWithDomain('cashup', 'www.cashup.cash');
        $this->bindEdgeShieldMock();
        $this->bindLimitsMock($tenant);

        $create = Mockery::mock(CreateFirewallRuleAction::class);
        $create->shouldReceive('execute')
            ->once()
            ->with(Mockery::on(fn (array $payload): bool => $payload['tenant_id'] === (string) $tenant->id
                && $payload['scope'] === 'tenant'
                && $payload['domain_name'] === 'global'))
            ->andReturn(['ok' => true, 'message' => null]);
        $this->app->instance(CreateFirewallRuleAction::class, $create);

        $response = $this->withAdminSession()->post(route('admin.tenants.firewall.store', $tenant), $this->validRulePayload([
            'scope' => 'tenant',
            'domain_name' => 'www.cashup.cash',
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('status');
    }

    public function test_admin_creates_tenant_scoped_sensitive_path(): void
    {
        [$tenant] = $this->tenantWithDomain('cashup', 'www.cashup.cash');
        $edge = $this->bindEdgeShieldMock();
        $edge->shouldReceive('createSensitivePath')
            ->once()
            ->with('global', '/admin', 'contains', 'block', false, (string) $tenant->id, 'tenant')
            ->andReturn(['ok' => true]);

        $response = $this->withAdminSession()->post(route('admin.tenants.sensitive_paths.store', $tenant), [
            'scope' => 'tenant',
            'domain_name' => 'www.cashup.cash',
            'path_pattern' => '/admin',
            'match_type' => 'contains',
            'action' => 'block',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Sensitive path saved.');
    }

    public function test_admin_can_create_tenant_ip_farm_without_plan_limit(): void
    {
        [$tenant] = $this->tenantWithDomain('cashup', 'www.cashup.cash');
        $edge = $this->bindEdgeShieldMock();
        $edge->shouldReceive('createIpFarmRule')
            ->once()
            ->with('global', 'Admin Farm', ['203.0.113.10', '198.51.100.0/24'], false, (string) $tenant->id, 'tenant')
            ->andReturn(['ok' => true, 'added' => 2]);

        $response = $this->withAdminSession()->post(route('admin.tenants.ip_farm.store', $tenant), [
            'scope' => 'tenant',
            'domain_name' => 'www.cashup.cash',
            'description' => 'Admin Farm',
            'ips' => "203.0.113.10\n198.51.100.0/24",
            'paused' => '0',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'IP Farm saved with 2 target(s).');
    }

    public function test_admin_can_bulk_delete_tenant_ip_farms(): void
    {
        [$tenant] = $this->tenantWithDomain('cashup', 'www.cashup.cash');
        $edge = $this->bindEdgeShieldMock();
        $edge->shouldReceive('deleteBulkIpFarmRules')
            ->once()
            ->with([5, 6], (string) $tenant->id)
            ->andReturn(['ok' => true, 'deleted' => 2]);

        $response = $this->withAdminSession()->delete(route('admin.tenants.ip_farm.bulk_destroy', $tenant), [
            'rule_ids' => [5, 6],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Selected IP Farm rules deleted.');
    }

    public function test_suspended_tenant_is_redirected_to_suspended_page(): void
    {
        [$tenant] = $this->tenantWithDomain('cashup', 'www.cashup.cash');
        $tenant->forceFill(['status' => 'suspended'])->save();

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get('/dashboard');

        $response->assertRedirect(route('account.suspended'));

        $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get(route('account.suspended'))
            ->assertOk()
            ->assertSee('تم إيقاف حسابك');
    }

    public function test_admin_can_suspend_and_resume_tenant(): void
    {
        [$tenant] = $this->tenantWithDomain('cashup', 'www.cashup.cash');
        $edge = $this->bindEdgeShieldMock();
        $edge->shouldReceive('queryD1')->twice()->andReturn(['ok' => true]);

        $this->withAdminSession()->post(route('admin.tenants.account.suspend', $tenant))
            ->assertRedirect()
            ->assertSessionHas('status', 'Tenant account suspended.');
        $this->assertSame('suspended', $tenant->refresh()->status);

        $this->withAdminSession()->post(route('admin.tenants.account.resume', $tenant))
            ->assertRedirect()
            ->assertSessionHas('status', 'Tenant account resumed.');
        $this->assertSame('active', $tenant->refresh()->status);
    }

    private function bindEdgeShieldMock(): MockInterface
    {
        $edge = Mockery::mock(EdgeShieldService::class);
        $this->app->instance(EdgeShieldService::class, $edge);
        $this->app->instance(UpdateFirewallRuleAction::class, Mockery::mock(UpdateFirewallRuleAction::class));
        $this->app->instance(ToggleFirewallRuleAction::class, Mockery::mock(ToggleFirewallRuleAction::class));
        $this->app->instance(DeleteFirewallRuleAction::class, Mockery::mock(DeleteFirewallRuleAction::class));

        return $edge;
    }

    private function bindLimitsMock(Tenant $tenant): void
    {
        $limits = Mockery::mock(PlanLimitsService::class);
        $limits->shouldReceive('getFirewallRulesUsage')->byDefault()->with((string) $tenant->id, false)->andReturn([
            'used' => 5,
            'limit' => 5,
            'remaining' => 0,
            'can_add' => false,
            'plan_name' => 'Starter',
            'message' => 'Limit reached.',
        ]);
        $this->app->instance(PlanLimitsService::class, $limits);
    }

    private function withAdminSession(): self
    {
        return $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
            'user_email' => 'admin@example.test',
        ]);
    }

    private function tenantWithDomain(string $slug, string $hostname): array
    {
        $tenant = Tenant::query()->create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $domain = TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => $hostname,
        ]);

        return [$tenant, $domain];
    }

    private function rule(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'domain_name' => 'www.cashup.cash',
            'tenant_id' => '1',
            'scope' => 'domain',
            'description' => 'Test rule',
            'action' => 'block',
            'expression_json' => json_encode(['field' => 'ip.src', 'operator' => 'eq', 'value' => '203.0.113.10']),
            'paused' => 0,
            'expires_at' => null,
        ], $overrides);
    }

    private function validRulePayload(array $overrides = []): array
    {
        return array_merge([
            'scope' => 'domain',
            'domain_name' => 'www.cashup.cash',
            'description' => 'Block test bot',
            'action' => 'managed_challenge',
            'field' => 'http.user_agent',
            'operator' => 'contains',
            'value' => 'bot',
            'duration' => '24h',
            'paused' => '0',
        ], $overrides);
    }
}
