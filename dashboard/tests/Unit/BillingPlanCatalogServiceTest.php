<?php

namespace Tests\Unit;

use App\Services\Billing\BillingPlanCatalogService;
use Tests\TestCase;

class BillingPlanCatalogServiceTest extends TestCase
{
    public function test_paid_plan_catalog_excludes_starter_and_returns_paid_tiers(): void
    {
        config()->set('services.paypal.plans', [
            'growth' => 'P-GROWTH',
            'pro' => 'P-PRO',
            'business' => 'P-BUSINESS',
            'scale' => 'P-SCALE',
        ]);

        $catalog = app(BillingPlanCatalogService::class);
        $plans = collect($catalog->paidPlans())->keyBy('key');

        $this->assertFalse($plans->has('starter'));
        $this->assertSame(['growth', 'pro', 'business', 'scale'], $plans->keys()->all());
        $this->assertSame('P-GROWTH', $plans['growth']['provider_plan_id']);
        $this->assertTrue($catalog->isPaidPlan('growth'));
        $this->assertFalse($catalog->isPaidPlan('starter'));
    }
}
