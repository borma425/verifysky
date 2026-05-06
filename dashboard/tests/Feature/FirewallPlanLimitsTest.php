<?php

namespace Tests\Feature;

use App\Actions\Firewall\CreateFirewallRuleAction;
use App\Actions\Firewall\DeleteBulkFirewallRulesAction;
use App\Actions\Firewall\DeleteFirewallRuleAction;
use App\Actions\Firewall\ToggleFirewallRuleAction;
use App\Actions\Firewall\UpdateFirewallRuleAction;
use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use App\Support\UserFacingErrorSanitizer;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class FirewallPlanLimitsTest extends TestCase
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

    public function test_non_admin_firewall_index_is_filtered_to_tenant_domains(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Cashup',
            'slug' => 'cashup',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);

        $edge = $this->bindEdgeShieldMock();
        $edge->shouldReceive('listDomains')->once()->with((string) $tenant->id, false)->andReturn([
            'ok' => true,
            'domains' => [
                ['domain_name' => 'example.com'],
            ],
        ]);
        $edge->shouldReceive('listTenantCustomFirewallRules')->once()->with((string) $tenant->id)->andReturn([
            'ok' => true,
            'rules' => [
                [
                    'id' => 1,
                    'domain_name' => 'example.com',
                    'description' => 'Allow trusted ASN',
                    'action' => 'allow',
                    'expression_json' => json_encode(['field' => 'ip.src.asnum', 'operator' => 'eq', 'value' => '12345']),
                    'paused' => 0,
                    'expires_at' => null,
                ],
                [
                    'id' => 2,
                    'domain_name' => 'global',
                    'tenant_id' => (string) $tenant->id,
                    'scope' => 'tenant',
                    'description' => 'Tenant scope rule',
                    'action' => 'block',
                    'expression_json' => json_encode(['field' => 'ip.src', 'operator' => 'eq', 'value' => '203.0.113.4']),
                    'paused' => 0,
                    'expires_at' => null,
                ],
            ],
        ]);

        $limits = Mockery::mock(PlanLimitsService::class);
        $this->app->instance(PlanLimitsService::class, $limits);
        $limits->shouldReceive('getFirewallRulesUsage')->once()->with((string) $tenant->id, false)->andReturn([
            'used' => 1,
            'limit' => 5,
            'remaining' => 4,
            'can_add' => true,
            'plan_name' => 'Starter',
            'message' => null,
        ]);
        $limits->shouldReceive('getBillingUsageLimits')->once()->with(Mockery::type(Tenant::class))->andReturn([
            'protected_sessions' => 10000,
            'bot_fair_use' => 25000,
            'plan_key' => 'starter',
            'plan_name' => 'Starter',
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get('/firewall');

        $response->assertOk()
            ->assertSee('Firewall')
            ->assertSee('Starter')
            ->assertSee('1 / 5 custom rules')
            ->assertSee('All domains')
            ->assertSee('Allow trusted ASN')
            ->assertSee('Tenant scope rule')
            ->assertDontSee('[IP-FARM]');
    }

    public function test_non_admin_can_create_tenant_global_firewall_rule(): void
    {
        Queue::fake();
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'one.example.com',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'two.example.com',
        ]);
        $this->bindEdgeShieldMock();

        $createAction = Mockery::mock(CreateFirewallRuleAction::class);
        $createAction->shouldReceive('execute')
            ->once()
            ->with(Mockery::on(fn (array $payload): bool => $payload['domain_name'] === 'global'
                && $payload['tenant_id'] === (string) $tenant->id
                && $payload['scope'] === 'tenant'))
            ->andReturn(['ok' => true, 'message' => null]);
        $this->app->instance(CreateFirewallRuleAction::class, $createAction);

        $limits = Mockery::mock(PlanLimitsService::class);
        $this->app->instance(PlanLimitsService::class, $limits);
        $limits->shouldReceive('domainBelongsToTenant')->never();
        $limits->shouldReceive('getFirewallRulesUsage')->once()->with((string) $tenant->id, false)->andReturn([
            'can_add' => true,
            'message' => null,
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->post('/firewall', $this->validRulePayload(['domain_name' => 'global']));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Firewall rule created successfully.');
        Queue::assertPushed(PurgeRuntimeBundleCache::class, fn (PurgeRuntimeBundleCache $job): bool => $job->domain === 'one.example.com');
        Queue::assertPushed(PurgeRuntimeBundleCache::class, fn (PurgeRuntimeBundleCache $job): bool => $job->domain === 'two.example.com');
    }

    public function test_non_admin_can_update_only_owned_tenant_global_firewall_rule(): void
    {
        Queue::fake();
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one-update',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'one.example.com',
        ]);
        $this->bindEdgeShieldMock();

        $updateAction = Mockery::mock(UpdateFirewallRuleAction::class);
        $updateAction->shouldReceive('execute')
            ->once()
            ->with('global', 44, Mockery::on(fn (array $payload): bool => $payload['tenant_id'] === (string) $tenant->id
                && $payload['scope'] === 'tenant'))
            ->andReturn(['ok' => true]);
        $this->app->instance(UpdateFirewallRuleAction::class, $updateAction);

        $limits = Mockery::mock(PlanLimitsService::class);
        $this->app->instance(PlanLimitsService::class, $limits);
        $limits->shouldReceive('domainBelongsToTenant')->never();
        $limits->shouldReceive('canManageRuleIds')->once()->with([44], (string) $tenant->id, false)->andReturn(true);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->put('/firewall/global/44', $this->validRulePayload());

        $response->assertRedirect(route('firewall.index'));
        $response->assertSessionHas('status', 'Firewall rule updated successfully.');
        Queue::assertPushed(PurgeRuntimeBundleCache::class, fn (PurgeRuntimeBundleCache $job): bool => $job->domain === 'one.example.com');
    }

    public function test_non_admin_cannot_update_another_tenant_global_firewall_rule(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Tenant One',
            'slug' => 'tenant-one-denied',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        $this->bindEdgeShieldMock();

        $updateAction = Mockery::mock(UpdateFirewallRuleAction::class);
        $updateAction->shouldReceive('execute')->never();
        $this->app->instance(UpdateFirewallRuleAction::class, $updateAction);

        $limits = Mockery::mock(PlanLimitsService::class);
        $this->app->instance(PlanLimitsService::class, $limits);
        $limits->shouldReceive('domainBelongsToTenant')->never();
        $limits->shouldReceive('canManageRuleIds')->once()->with([44], (string) $tenant->id, false)->andReturn(false);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->put('/firewall/global/44', $this->validRulePayload());

        $response->assertForbidden();
    }

    public function test_non_admin_cannot_add_rule_after_plan_limit(): void
    {
        $this->bindEdgeShieldMock();

        $createAction = Mockery::mock(CreateFirewallRuleAction::class);
        $createAction->shouldReceive('execute')->never();
        $this->app->instance(CreateFirewallRuleAction::class, $createAction);

        $limits = Mockery::mock(PlanLimitsService::class);
        $this->app->instance(PlanLimitsService::class, $limits);
        $limits->shouldReceive('domainBelongsToTenant')->once()->with('example.com', 'tenant-1', false)->andReturn(true);
        $limits->shouldReceive('getFirewallRulesUsage')->once()->with('tenant-1', false)->andReturn([
            'can_add' => false,
            'message' => 'Starter includes up to 5 custom firewall rules. Upgrade to Growth to add more.',
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => 'tenant-1',
        ])->from('/firewall')->post('/firewall', $this->validRulePayload());

        $response->assertRedirect('/firewall');
        $response->assertSessionHasErrors([
            'domain_name' => 'Starter includes up to 5 custom firewall rules. Upgrade to Growth to add more.',
        ]);
    }

    public function test_non_admin_can_create_rule_within_plan_limit(): void
    {
        $this->bindEdgeShieldMock();

        $createAction = Mockery::mock(CreateFirewallRuleAction::class);
        $createAction->shouldReceive('execute')->once()->andReturn([
            'ok' => true,
            'message' => null,
        ]);
        $this->app->instance(CreateFirewallRuleAction::class, $createAction);

        $limits = Mockery::mock(PlanLimitsService::class);
        $this->app->instance(PlanLimitsService::class, $limits);
        $limits->shouldReceive('domainBelongsToTenant')->once()->with('example.com', 'tenant-1', false)->andReturn(true);
        $limits->shouldReceive('getFirewallRulesUsage')->once()->with('tenant-1', false)->andReturn([
            'can_add' => true,
            'message' => null,
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => 'tenant-1',
        ])->from('/firewall')->post('/firewall', $this->validRulePayload());

        $response->assertRedirect('/firewall');
        $response->assertSessionHas('status', 'Firewall rule created successfully.');
    }

    public function test_non_admin_can_create_rule_with_not_in_operator(): void
    {
        $this->bindEdgeShieldMock();

        $createAction = Mockery::mock(CreateFirewallRuleAction::class);
        $createAction->shouldReceive('execute')
            ->once()
            ->with(Mockery::on(fn (array $payload): bool => $payload['operator'] === 'not_in'))
            ->andReturn([
                'ok' => true,
                'message' => null,
            ]);
        $this->app->instance(CreateFirewallRuleAction::class, $createAction);

        $limits = Mockery::mock(PlanLimitsService::class);
        $this->app->instance(PlanLimitsService::class, $limits);
        $limits->shouldReceive('domainBelongsToTenant')->once()->with('example.com', 'tenant-1', false)->andReturn(true);
        $limits->shouldReceive('getFirewallRulesUsage')->once()->with('tenant-1', false)->andReturn([
            'can_add' => true,
            'message' => null,
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => 'tenant-1',
        ])->from('/firewall')->post('/firewall', $this->validRulePayload([
            'field' => 'ip.src',
            'operator' => 'not_in',
            'value' => '203.0.113.10,198.51.100.0/24',
        ]));

        $response->assertRedirect('/firewall');
        $response->assertSessionHas('status', 'Firewall rule created successfully.');
    }

    public function test_firewall_page_sanitizes_raw_runtime_error_flash_message(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Cashup',
            'slug' => 'cashup',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);

        $edge = $this->bindEdgeShieldMock();
        $edge->shouldReceive('listDomains')->once()->with((string) $tenant->id, false)->andReturn([
            'ok' => true,
            'domains' => [['domain_name' => 'example.com']],
        ]);
        $edge->shouldReceive('listTenantCustomFirewallRules')->once()->with((string) $tenant->id)->andReturn([
            'ok' => true,
            'rules' => [],
        ]);

        $limits = Mockery::mock(PlanLimitsService::class);
        $this->app->instance(PlanLimitsService::class, $limits);
        $limits->shouldReceive('getFirewallRulesUsage')->once()->with((string) $tenant->id, false)->andReturn([
            'used' => 0,
            'limit' => 5,
            'remaining' => 5,
            'can_add' => true,
            'plan_name' => 'Starter',
            'message' => null,
        ]);
        $limits->shouldReceive('getBillingUsageLimits')->once()->with(Mockery::type(Tenant::class))->andReturn([
            'protected_sessions' => 10000,
            'bot_fair_use' => 25000,
            'plan_key' => 'starter',
            'plan_name' => 'Starter',
        ]);

        $rawError = "\033[31m✘ \033[41;31m[\033[41;97mERROR\033[41;31m]\033[0m no such column: tenant_id at offset 76: SQLITE_ERROR 🪵 Logs were written to \"/opt/lampp/htdocs/verifysky/dashboard/storage/wrangler-runtime/logs/wrangler-2026-04-21_15-16-50_785.log\"";

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
            'error' => $rawError,
        ])->get('/firewall');

        $response->assertOk()
            ->assertSee(UserFacingErrorSanitizer::defaultMessage())
            ->assertDontSee('SQLITE_ERROR')
            ->assertDontSee('no such column')
            ->assertDontSee('wrangler-runtime/logs')
            ->assertDontSee("\u{001b}");
    }

    private function bindEdgeShieldMock(): MockInterface
    {
        $edge = Mockery::mock(EdgeShieldService::class);
        $this->app->instance(EdgeShieldService::class, $edge);

        $this->app->instance(UpdateFirewallRuleAction::class, Mockery::mock(UpdateFirewallRuleAction::class));
        $this->app->instance(ToggleFirewallRuleAction::class, Mockery::mock(ToggleFirewallRuleAction::class));
        $this->app->instance(DeleteFirewallRuleAction::class, Mockery::mock(DeleteFirewallRuleAction::class));
        $this->app->instance(DeleteBulkFirewallRulesAction::class, Mockery::mock(DeleteBulkFirewallRulesAction::class));

        return $edge;
    }

    private function validRulePayload(array $overrides = []): array
    {
        return array_merge([
            'domain_name' => 'example.com',
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
