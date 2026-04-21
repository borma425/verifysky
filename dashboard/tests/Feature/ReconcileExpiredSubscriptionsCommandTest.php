<?php

namespace Tests\Feature;

use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReconcileExpiredSubscriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_canceled_subscription_downgrades_tenant_to_starter_at_period_end(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant('growth');
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);
        $subscription = TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => TenantSubscription::PROVIDER_PAYPAL,
            'provider_subscription_id' => 'I-OLD1',
            'plan_key' => 'growth',
            'provider_plan_id' => 'P-GROWTH',
            'status' => TenantSubscription::STATUS_CANCELED,
            'current_period_starts_at' => '2026-04-01 00:00:00',
            'current_period_ends_at' => '2026-05-01 00:00:00',
            'cancel_at_period_end' => true,
        ]);

        $this->artisan('billing:reconcile-expired-subscriptions', ['--now' => '2026-05-02 00:00:00'])
            ->assertSuccessful();

        $tenant->refresh();
        $subscription->refresh();

        $this->assertSame('starter', $tenant->plan);
        $this->assertSame(TenantSubscription::STATUS_EXPIRED, $subscription->status);
        Queue::assertPushed(PurgeRuntimeBundleCache::class, 1);
    }

    public function test_reconciliation_does_not_downgrade_tenant_with_active_replacement_subscription(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant('pro');
        $expiredSubscription = TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => TenantSubscription::PROVIDER_PAYPAL,
            'provider_subscription_id' => 'I-OLD2',
            'plan_key' => 'growth',
            'provider_plan_id' => 'P-GROWTH',
            'status' => TenantSubscription::STATUS_CANCELED,
            'current_period_starts_at' => '2026-04-01 00:00:00',
            'current_period_ends_at' => '2026-05-01 00:00:00',
            'cancel_at_period_end' => true,
        ]);
        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => TenantSubscription::PROVIDER_PAYPAL,
            'provider_subscription_id' => 'I-ACTIVE2',
            'plan_key' => 'pro',
            'provider_plan_id' => 'P-PRO',
            'status' => TenantSubscription::STATUS_ACTIVE,
            'current_period_starts_at' => '2026-05-01 00:00:00',
            'current_period_ends_at' => '2026-06-01 00:00:00',
        ]);

        $this->artisan('billing:reconcile-expired-subscriptions', ['--now' => '2026-05-02 00:00:00'])
            ->assertSuccessful();

        $tenant->refresh();
        $expiredSubscription->refresh();

        $this->assertSame('pro', $tenant->plan);
        $this->assertSame(TenantSubscription::STATUS_EXPIRED, $expiredSubscription->status);
        Queue::assertNothingPushed();
    }

    private function makeTenant(string $plan): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Reconcile Tenant '.$plan,
            'slug' => 'reconcile-tenant-'.$plan,
            'plan' => $plan,
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
    }
}
