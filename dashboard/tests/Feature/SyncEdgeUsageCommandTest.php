<?php

namespace Tests\Feature;

use App\Jobs\PurgeRuntimeBundleCache;
use App\Jobs\SendUsageThresholdWarningMailJob;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantMembership;
use App\Models\TenantUsage;
use App\Models\User;
use App\Services\Billing\CloudflareAnalyticsService;
use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SyncEdgeUsageCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_updates_current_cycle_without_switching_to_pass_through_when_within_limits(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant('within-limit-tenant');
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);

        $this->bindPlanLimits([
            'protected_sessions' => 100,
            'bot_fair_use' => 100,
            'plan_key' => 'starter',
            'plan_name' => 'Starter',
        ]);
        $this->bindD1Sequence([12]);
        $this->bindCloudflareSequence([[
            'ok' => true,
            'error' => null,
            'total' => 7,
            'by_hostname' => ['example.com' => 7],
        ]]);

        $this->artisan('billing:sync-edge-usage', ['--now' => '2026-04-15 00:00:00'])
            ->assertExitCode(0);

        $usage = TenantUsage::query()->sole();

        $this->assertSame(12, $usage->protected_sessions_used);
        $this->assertSame(7, $usage->bot_requests_used);
        $this->assertSame(TenantUsage::STATUS_ACTIVE, $usage->quota_status);
        $this->assertSame('2026-04-15 00:00:00', $usage->last_reconciled_at?->utc()->toDateTimeString());
        Queue::assertNothingPushed();
    }

    public function test_it_switches_to_pass_through_and_purges_runtime_cache_once_on_first_transition(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant('pass-through-tenant');
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'www.example.com',
        ]);

        $this->bindPlanLimits([
            'protected_sessions' => 5,
            'bot_fair_use' => 100,
            'plan_key' => 'starter',
            'plan_name' => 'Starter',
        ]);
        $this->bindD1Sequence([6]);
        $this->bindCloudflareSequence([[
            'ok' => true,
            'error' => null,
            'total' => 0,
            'by_hostname' => [
                'example.com' => 0,
                'www.example.com' => 0,
            ],
        ]]);

        $this->artisan('billing:sync-edge-usage', ['--now' => '2026-04-15 00:00:00'])
            ->assertExitCode(0);

        $usage = TenantUsage::query()->sole();

        $this->assertSame(TenantUsage::STATUS_PASS_THROUGH, $usage->quota_status);
        Queue::assertPushed(PurgeRuntimeBundleCache::class, fn (PurgeRuntimeBundleCache $job): bool => $job->domain === 'example.com');
        Queue::assertPushed(PurgeRuntimeBundleCache::class, fn (PurgeRuntimeBundleCache $job): bool => $job->domain === 'www.example.com');
        Queue::assertPushed(PurgeRuntimeBundleCache::class, 2);
    }

    public function test_it_does_not_purge_again_when_tenant_is_already_pass_through(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant('existing-pass-through-tenant');
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);

        TenantUsage::query()->create([
            'tenant_id' => $tenant->id,
            'cycle_start_at' => '2026-04-01 00:00:00',
            'cycle_end_at' => '2026-05-01 00:00:00',
            'protected_sessions_used' => 3,
            'bot_requests_used' => 1,
            'quota_status' => TenantUsage::STATUS_PASS_THROUGH,
        ]);

        $this->bindPlanLimits([
            'protected_sessions' => 5,
            'bot_fair_use' => 100,
            'plan_key' => 'starter',
            'plan_name' => 'Starter',
        ]);
        $this->bindD1Sequence([8]);
        $this->bindCloudflareSequence([[
            'ok' => true,
            'error' => null,
            'total' => 0,
            'by_hostname' => ['example.com' => 0],
        ]]);

        $this->artisan('billing:sync-edge-usage', ['--now' => '2026-04-15 00:00:00'])
            ->assertExitCode(0);

        $usage = TenantUsage::query()->sole();

        $this->assertSame(TenantUsage::STATUS_PASS_THROUGH, $usage->quota_status);
        $this->assertSame(8, $usage->protected_sessions_used);
        Queue::assertNotPushed(PurgeRuntimeBundleCache::class);
        Queue::assertPushed(SendUsageThresholdWarningMailJob::class, 1);
    }

    public function test_it_dispatches_usage_warning_once_per_cycle_to_owner_recipients_only(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant('warning-tenant');
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'warn.example.com',
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

        $this->bindPlanLimits([
            'protected_sessions' => 100,
            'bot_fair_use' => 100,
            'plan_key' => 'starter',
            'plan_name' => 'Starter',
        ]);
        $this->bindD1Sequence([80, 85]);
        $this->bindCloudflareSequence([
            ['ok' => true, 'error' => null, 'total' => 0, 'by_hostname' => ['warn.example.com' => 0]],
            ['ok' => true, 'error' => null, 'total' => 0, 'by_hostname' => ['warn.example.com' => 0]],
        ]);

        $this->artisan('billing:sync-edge-usage', ['--now' => '2026-04-15 00:00:00'])
            ->assertExitCode(0);
        $this->artisan('billing:sync-edge-usage', ['--now' => '2026-04-16 00:00:00'])
            ->assertExitCode(0);

        $usage = TenantUsage::query()->sole();

        $this->assertNotNull($usage->usage_warning_sent_at);
        Queue::assertPushed(SendUsageThresholdWarningMailJob::class, function (SendUsageThresholdWarningMailJob $job) use ($tenant): bool {
            return $job->tenantId === $tenant->id
                && $job->recipientEmails === ['owner@example.test'];
        });
        Queue::assertPushed(SendUsageThresholdWarningMailJob::class, 1);
    }

    public function test_it_skips_failed_tenant_without_partial_overwrite_and_continues_with_next_tenant(): void
    {
        Queue::fake();

        $failingTenant = $this->makeTenant('failing-tenant');
        $successfulTenant = $this->makeTenant('successful-tenant');

        TenantDomain::query()->create([
            'tenant_id' => $failingTenant->id,
            'hostname' => 'fail.example.com',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $successfulTenant->id,
            'hostname' => 'ok.example.com',
        ]);

        $this->bindPlanLimits([
            'protected_sessions' => 100,
            'bot_fair_use' => 100,
            'plan_key' => 'starter',
            'plan_name' => 'Starter',
        ]);
        $this->bindD1Sequence([4, 9]);
        $this->bindCloudflareSequence([
            [
                'ok' => false,
                'error' => 'Cloudflare unavailable',
                'total' => 0,
                'by_hostname' => [],
            ],
            [
                'ok' => true,
                'error' => null,
                'total' => 3,
                'by_hostname' => ['ok.example.com' => 3],
            ],
        ]);

        $this->artisan('billing:sync-edge-usage', ['--now' => '2026-04-15 00:00:00'])
            ->assertExitCode(0);

        $failingUsage = TenantUsage::query()->where('tenant_id', $failingTenant->id)->sole();
        $successfulUsage = TenantUsage::query()->where('tenant_id', $successfulTenant->id)->sole();

        $this->assertSame(0, $failingUsage->protected_sessions_used);
        $this->assertSame(0, $failingUsage->bot_requests_used);
        $this->assertNull($failingUsage->last_reconciled_at);

        $this->assertSame(9, $successfulUsage->protected_sessions_used);
        $this->assertSame(3, $successfulUsage->bot_requests_used);
        $this->assertNotNull($successfulUsage->last_reconciled_at);
        Queue::assertNothingPushed();
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

    /**
     * @param  array{protected_sessions:int,bot_fair_use:int,plan_key:string,plan_name:string}  $limits
     */
    private function bindPlanLimits(array $limits): void
    {
        $service = Mockery::mock(PlanLimitsService::class);
        $service->shouldReceive('getBillingUsageLimits')->andReturn($limits);
        $this->app->instance(PlanLimitsService::class, $service);
    }

    /**
     * @param  array<int, int>  $totals
     */
    private function bindD1Sequence(array $totals): void
    {
        $d1 = Mockery::mock(D1DatabaseClient::class);

        foreach ($totals as $total) {
            $d1->shouldReceive('query')->once()->andReturn([
                'ok' => true,
                'error' => null,
                'output' => json_encode([
                    ['results' => [['total' => $total]]],
                ]),
            ]);
            $d1->shouldReceive('parseWranglerJson')->once()->andReturn([
                ['results' => [['total' => $total]]],
            ]);
        }

        $this->app->instance(D1DatabaseClient::class, $d1);
    }

    /**
     * @param  array<int, array{ok:bool,error:?string,total:int,by_hostname:array<string,int>}>  $responses
     */
    private function bindCloudflareSequence(array $responses): void
    {
        $service = Mockery::mock(CloudflareAnalyticsService::class);

        foreach ($responses as $response) {
            $service->shouldReceive('getBotRequestsUsage')->once()->andReturn($response);
        }

        $this->app->instance(CloudflareAnalyticsService::class, $service);
    }
}
