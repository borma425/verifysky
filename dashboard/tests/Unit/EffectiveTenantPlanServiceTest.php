<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Models\TenantPlanGrant;
use App\Models\TenantSubscription;
use App\Services\Billing\EffectiveTenantPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EffectiveTenantPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_grant_takes_precedence_over_active_subscription_and_baseline(): void
    {
        $tenant = $this->makeTenant('starter');
        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => TenantSubscription::PROVIDER_PAYPAL,
            'provider_subscription_id' => 'I-ACTIVE-GROWTH',
            'plan_key' => 'growth',
            'provider_plan_id' => 'P-GROWTH',
            'status' => TenantSubscription::STATUS_ACTIVE,
            'current_period_starts_at' => '2026-05-01 00:00:00',
            'current_period_ends_at' => '2026-06-01 00:00:00',
        ]);
        TenantPlanGrant::query()->create([
            'tenant_id' => $tenant->id,
            'granted_plan_key' => 'pro',
            'source' => 'manual',
            'status' => TenantPlanGrant::STATUS_ACTIVE,
            'starts_at' => '2026-05-10 00:00:00',
            'ends_at' => '2026-05-20 00:00:00',
        ]);

        $effective = app(EffectiveTenantPlanService::class)->effectivePlanForTenant(
            $tenant,
            now()->setDate(2026, 5, 15)->setTime(12, 0)->utc()
        );

        $this->assertSame('pro', $effective['key']);
        $this->assertSame('manual_grant', $effective['source']);
        $this->assertSame('starter', $effective['baseline_key']);
        $this->assertNotNull($effective['active_subscription']);
        $this->assertNotNull($effective['active_grant']);
    }

    public function test_active_subscription_takes_precedence_over_baseline_when_no_manual_grant_exists(): void
    {
        $tenant = $this->makeTenant('starter');
        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => TenantSubscription::PROVIDER_PAYPAL,
            'provider_subscription_id' => 'I-ACTIVE-PRO',
            'plan_key' => 'pro',
            'provider_plan_id' => 'P-PRO',
            'status' => TenantSubscription::STATUS_ACTIVE,
            'current_period_starts_at' => '2026-05-01 00:00:00',
            'current_period_ends_at' => '2026-06-01 00:00:00',
        ]);

        $effective = app(EffectiveTenantPlanService::class)->effectivePlanForTenant($tenant);

        $this->assertSame('pro', $effective['key']);
        $this->assertSame('paid_subscription', $effective['source']);
    }

    public function test_baseline_plan_is_used_when_no_grant_or_subscription_exists(): void
    {
        $tenant = $this->makeTenant('business');

        $effective = app(EffectiveTenantPlanService::class)->effectivePlanForTenant($tenant);

        $this->assertSame('business', $effective['key']);
        $this->assertSame('baseline', $effective['source']);
        $this->assertNull($effective['active_subscription']);
        $this->assertNull($effective['active_grant']);
    }

    private function makeTenant(string $plan): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Effective Tenant '.$plan,
            'slug' => 'effective-tenant-'.$plan.'-'.uniqid(),
            'plan' => $plan,
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
    }
}
