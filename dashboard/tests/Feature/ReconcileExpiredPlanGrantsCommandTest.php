<?php

namespace Tests\Feature;

use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantPlanGrant;
use App\Models\TenantSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReconcileExpiredPlanGrantsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_manual_grant_falls_back_to_active_subscription_plan_without_reset_when_effective_plan_matches(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant('starter');
        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => TenantSubscription::PROVIDER_PAYPAL,
            'provider_subscription_id' => 'I-ACTIVE1',
            'plan_key' => 'growth',
            'provider_plan_id' => 'P-GROWTH',
            'status' => TenantSubscription::STATUS_ACTIVE,
            'current_period_starts_at' => '2026-05-01 00:00:00',
            'current_period_ends_at' => '2026-06-01 00:00:00',
        ]);
        $grant = TenantPlanGrant::query()->create([
            'tenant_id' => $tenant->id,
            'granted_plan_key' => 'growth',
            'source' => 'manual',
            'status' => TenantPlanGrant::STATUS_ACTIVE,
            'starts_at' => '2026-05-10 00:00:00',
            'ends_at' => '2026-05-15 00:00:00',
        ]);

        $this->artisan('billing:reconcile-expired-plan-grants', ['--now' => '2026-05-16 00:00:00'])
            ->assertSuccessful();

        $grant->refresh();
        $tenant->refresh();

        $this->assertSame(TenantPlanGrant::STATUS_EXPIRED, $grant->status);
        $this->assertSame('starter', $tenant->plan);
        Queue::assertNothingPushed();
    }

    public function test_expired_manual_grant_resets_when_effective_plan_drops_to_baseline(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant('starter');
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);
        $grant = TenantPlanGrant::query()->create([
            'tenant_id' => $tenant->id,
            'granted_plan_key' => 'pro',
            'source' => 'manual',
            'status' => TenantPlanGrant::STATUS_ACTIVE,
            'starts_at' => '2026-05-10 00:00:00',
            'ends_at' => '2026-05-15 00:00:00',
        ]);

        $this->artisan('billing:reconcile-expired-plan-grants', ['--now' => '2026-05-16 00:00:00'])
            ->assertSuccessful();

        $grant->refresh();
        $tenant->refresh();

        $this->assertSame(TenantPlanGrant::STATUS_EXPIRED, $grant->status);
        $this->assertSame('starter', $tenant->plan);
        Queue::assertPushed(PurgeRuntimeBundleCache::class, 1);
    }

    private function makeTenant(string $plan): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Grant Reconcile '.$plan,
            'slug' => 'grant-reconcile-'.$plan.'-'.uniqid(),
            'plan' => $plan,
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
    }
}
