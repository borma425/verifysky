<?php

namespace Tests\Feature;

use App\Actions\Domains\ProvisionTenantDomainAction;
use App\Http\Requests\Domains\AdminStoreTenantDomainRequest;
use App\Jobs\PurgeRuntimeBundleCache;
use App\Jobs\SendManualGrantActivatedMailJob;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantMembership;
use App\Models\TenantPlanGrant;
use App\Models\TenantUsage;
use App\Models\User;
use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\Plans\PlanLimitsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AdminTenantBillingOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_admin_can_view_tenant_billing_operations_page(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Acme Tenant',
            'slug' => 'acme-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
        TenantUsage::query()->create([
            'tenant_id' => $tenant->id,
            'cycle_start_at' => '2026-04-01 00:00:00',
            'cycle_end_at' => '2026-05-01 00:00:00',
            'protected_sessions_used' => 12000,
            'bot_requests_used' => 900,
            'quota_status' => TenantUsage::STATUS_PASS_THROUGH,
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->get(route('admin.tenants.index'));

        $response->assertOk()
            ->assertSee('Users Billing Operations')
            ->assertSee('Acme Tenant')
            ->assertSee('pass through')
            ->assertSee('12,000 / 10,000', false);
    }

    public function test_admin_page_displays_active_manual_grant_details(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Grant Tenant',
            'slug' => 'grant-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
        TenantUsage::query()->create([
            'tenant_id' => $tenant->id,
            'cycle_start_at' => '2026-04-01 00:00:00',
            'cycle_end_at' => '2026-05-01 00:00:00',
            'protected_sessions_used' => 0,
            'bot_requests_used' => 0,
            'quota_status' => TenantUsage::STATUS_ACTIVE,
        ]);
        TenantPlanGrant::query()->create([
            'tenant_id' => $tenant->id,
            'granted_plan_key' => 'pro',
            'source' => 'manual',
            'status' => TenantPlanGrant::STATUS_ACTIVE,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'reason' => 'Beta cohort',
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->get(route('admin.tenants.index'));

        $response->assertOk()
            ->assertSee('Grant Tenant')
            ->assertSee('Starter')
            ->assertSee('Pro')
            ->assertSee('Beta cohort')
            ->assertSee('Revoke Bonus');
    }

    public function test_admin_cannot_manually_add_domain_when_client_reached_plan_limit(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Full Client',
            'slug' => 'full-client',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);

        $action = Mockery::mock(ProvisionTenantDomainAction::class);
        $action->shouldReceive('execute')->never();
        $this->app->instance(ProvisionTenantDomainAction::class, $action);
        $this->app->instance(PlanLimitsService::class, new PlanLimitsService(Mockery::mock(D1DatabaseClient::class)));

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->post(route('admin.tenants.domains.store', $tenant), [
            'domain_name' => 'second-example.com',
            'origin_server' => '192.0.2.10',
            'security_mode' => 'balanced',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors([
            'domain_name' => AdminStoreTenantDomainRequest::DOMAIN_LIMIT_MESSAGE,
        ]);
        $this->assertDatabaseCount('tenant_domains', 1);
    }

    public function test_admin_can_manually_add_domain_and_start_edge_setup_when_space_remains(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Growth Client',
            'slug' => 'growth-client',
            'plan' => 'growth',
            'status' => 'active',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);

        $action = Mockery::mock(ProvisionTenantDomainAction::class);
        $action->shouldReceive('execute')->once()->with([
            'domain_name' => 'second-example.com',
            'origin_server' => '192.0.2.10',
            'security_mode' => 'balanced',
        ], (string) $tenant->id)->andReturn([
            'ok' => true,
            'created' => ['second-example.com'],
            'origin_mode' => 'manual',
        ]);
        $this->app->instance(ProvisionTenantDomainAction::class, $action);
        $this->app->instance(PlanLimitsService::class, new PlanLimitsService(Mockery::mock(D1DatabaseClient::class)));

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->post(route('admin.tenants.domains.store', $tenant), [
            'domain_name' => 'second-example.com',
            'origin_server' => '192.0.2.10',
            'security_mode' => 'balanced',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Domain setup started for second-example.com.')
            && str_contains($message, 'activating protection'));
    }

    public function test_non_admin_cannot_open_tenant_billing_operations_page(): void
    {
        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => '1',
        ])->get(route('admin.tenants.index'));

        $response->assertNotFound();
    }

    public function test_force_cycle_reset_creates_new_active_cycle_and_purges_domains(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 12:30:45', 'UTC'));

        $tenant = Tenant::query()->create([
            'name' => 'Reset Tenant',
            'slug' => 'reset-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'www.example.com',
        ]);
        TenantUsage::query()->create([
            'tenant_id' => $tenant->id,
            'cycle_start_at' => '2026-04-01 00:00:00',
            'cycle_end_at' => '2026-05-01 00:00:00',
            'protected_sessions_used' => 12000,
            'bot_requests_used' => 900,
            'quota_status' => TenantUsage::STATUS_PASS_THROUGH,
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->post(route('admin.tenants.force_cycle_reset', $tenant));

        $response->assertRedirect();
        $response->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Billing cycle reset for Reset Tenant.'));

        $tenant->refresh();
        $this->assertSame('2026-04-21 12:30:45', $tenant->billing_start_at?->utc()->toDateTimeString());
        $this->assertSame(2, TenantUsage::query()->where('tenant_id', $tenant->id)->count());

        $newCycle = TenantUsage::query()
            ->where('tenant_id', $tenant->id)
            ->where('cycle_start_at', '2026-04-21 12:30:45')
            ->sole();

        $this->assertSame(TenantUsage::STATUS_ACTIVE, $newCycle->quota_status);

        Queue::assertPushed(PurgeRuntimeBundleCache::class, fn (PurgeRuntimeBundleCache $job): bool => $job->domain === 'example.com');
        Queue::assertPushed(PurgeRuntimeBundleCache::class, fn (PurgeRuntimeBundleCache $job): bool => $job->domain === 'www.example.com');
        Queue::assertPushed(PurgeRuntimeBundleCache::class, 2);
    }

    public function test_admin_operations_page_handles_missing_billing_tables_gracefully(): void
    {
        Tenant::query()->create([
            'name' => 'Pending Tenant',
            'slug' => 'pending-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);

        Schema::drop('tenant_usage');

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->get(route('admin.tenants.index'));

        $response->assertOk()
            ->assertSee('Billing migrations pending')
            ->assertSee('Run billing migrations first');
    }

    public function test_admin_can_create_manual_plan_grant_and_reset_when_effective_plan_changes(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 12:30:45', 'UTC'));

        $tenant = Tenant::query()->create([
            'name' => 'Manual Grant Tenant',
            'slug' => 'manual-grant-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'password',
            'role' => 'user',
        ]);
        $member = User::query()->create([
            'name' => 'Member',
            'email' => 'member@example.test',
            'password' => 'password',
            'role' => 'user',
        ]);
        TenantMembership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        TenantMembership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);
        TenantUsage::query()->create([
            'tenant_id' => $tenant->id,
            'cycle_start_at' => '2026-04-01 00:00:00',
            'cycle_end_at' => '2026-05-01 00:00:00',
            'protected_sessions_used' => 100,
            'bot_requests_used' => 100,
            'quota_status' => TenantUsage::STATUS_ACTIVE,
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->post(route('admin.tenants.manual_grants.store', $tenant), [
            'plan_key' => 'pro',
            'duration_days' => 14,
            'reason' => 'Beta onboarding',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Manual PRO grant activated'));

        $grant = TenantPlanGrant::query()->sole();
        $tenant->refresh();

        $this->assertSame('pro', $grant->granted_plan_key);
        $this->assertSame(TenantPlanGrant::STATUS_ACTIVE, $grant->status);
        $this->assertSame('2026-05-05 12:30:45', $grant->ends_at?->utc()->toDateTimeString());
        $this->assertSame('2026-04-21 12:30:45', $tenant->billing_start_at?->utc()->toDateTimeString());
        Queue::assertPushed(PurgeRuntimeBundleCache::class, 1);
        Queue::assertPushed(SendManualGrantActivatedMailJob::class, fn (SendManualGrantActivatedMailJob $job): bool => $job->recipientEmails === ['owner@example.test']);
    }

    public function test_new_manual_grant_revokes_previous_active_grant_without_reset_if_effective_plan_stays_the_same(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 12:30:45', 'UTC'));

        $tenant = Tenant::query()->create([
            'name' => 'Grant Rollover Tenant',
            'slug' => 'grant-rollover-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
        TenantUsage::query()->create([
            'tenant_id' => $tenant->id,
            'cycle_start_at' => '2026-04-01 00:00:00',
            'cycle_end_at' => '2026-05-01 00:00:00',
            'protected_sessions_used' => 0,
            'bot_requests_used' => 0,
            'quota_status' => TenantUsage::STATUS_ACTIVE,
        ]);
        TenantPlanGrant::query()->create([
            'tenant_id' => $tenant->id,
            'granted_plan_key' => 'pro',
            'source' => 'manual',
            'status' => TenantPlanGrant::STATUS_ACTIVE,
            'starts_at' => '2026-04-10 00:00:00',
            'ends_at' => '2026-04-25 00:00:00',
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->post(route('admin.tenants.manual_grants.store', $tenant), [
            'plan_key' => 'pro',
            'duration_days' => 7,
        ]);

        $response->assertRedirect();

        $this->assertSame(2, TenantPlanGrant::query()->count());
        $this->assertSame(1, TenantPlanGrant::query()->where('status', TenantPlanGrant::STATUS_ACTIVE)->count());
        $this->assertSame(1, TenantPlanGrant::query()->where('status', TenantPlanGrant::STATUS_REVOKED)->count());
        Queue::assertNotPushed(PurgeRuntimeBundleCache::class);
        Queue::assertPushed(SendManualGrantActivatedMailJob::class, 1);
    }

    public function test_admin_can_revoke_manual_plan_grant_and_reset_when_effective_plan_changes(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 12:30:45', 'UTC'));

        $tenant = Tenant::query()->create([
            'name' => 'Revoke Grant Tenant',
            'slug' => 'revoke-grant-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);
        TenantUsage::query()->create([
            'tenant_id' => $tenant->id,
            'cycle_start_at' => '2026-04-01 00:00:00',
            'cycle_end_at' => '2026-05-01 00:00:00',
            'protected_sessions_used' => 0,
            'bot_requests_used' => 0,
            'quota_status' => TenantUsage::STATUS_ACTIVE,
        ]);
        $grant = TenantPlanGrant::query()->create([
            'tenant_id' => $tenant->id,
            'granted_plan_key' => 'business',
            'source' => 'manual',
            'status' => TenantPlanGrant::STATUS_ACTIVE,
            'starts_at' => '2026-04-10 00:00:00',
            'ends_at' => '2026-04-25 00:00:00',
        ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->post(route('admin.tenants.manual_grants.revoke', [$tenant, $grant]));

        $response->assertRedirect();
        $grant->refresh();
        $tenant->refresh();

        $this->assertSame(TenantPlanGrant::STATUS_REVOKED, $grant->status);
        $this->assertSame('2026-04-21 12:30:45', $grant->revoked_at?->utc()->toDateTimeString());
        $this->assertSame('2026-04-21 12:30:45', $tenant->billing_start_at?->utc()->toDateTimeString());
        Queue::assertPushed(PurgeRuntimeBundleCache::class, 1);
    }
}
