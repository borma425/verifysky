<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Models\TenantUsage;
use App\Services\Billing\BillingCycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingCycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_gets_current_cycle_from_billing_start_at_in_utc(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Cycle Tenant',
            'slug' => 'cycle-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-01-15 10:30:00',
        ]);

        $service = app(BillingCycleService::class);
        $cycle = $service->getOrCreateCurrentCycle($tenant, CarbonImmutable::parse('2026-02-14 09:00:00', 'UTC'));

        $this->assertSame('2026-01-15 10:30:00', $cycle->cycle_start_at->utc()->toDateTimeString());
        $this->assertSame('2026-02-15 10:30:00', $cycle->cycle_end_at->utc()->toDateTimeString());
        $this->assertSame(TenantUsage::STATUS_ACTIVE, $cycle->quota_status);
    }

    public function test_no_overflow_month_math_keeps_end_of_month_anchor_stable(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Month End Tenant',
            'slug' => 'month-end-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-01-31 12:00:00',
        ]);

        $service = app(BillingCycleService::class);
        $cycle = $service->getOrCreateCurrentCycle($tenant, CarbonImmutable::parse('2026-03-15 00:00:00', 'UTC'));

        $this->assertSame('2026-02-28 12:00:00', $cycle->cycle_start_at->utc()->toDateTimeString());
        $this->assertSame('2026-03-31 12:00:00', $cycle->cycle_end_at->utc()->toDateTimeString());
    }

    public function test_rollover_creates_new_cycle_and_returns_to_active_status(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Rollover Tenant',
            'slug' => 'rollover-tenant',
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);

        $service = app(BillingCycleService::class);

        $firstCycle = $service->getOrCreateCurrentCycle($tenant, CarbonImmutable::parse('2026-04-20 00:00:00', 'UTC'));
        $firstCycle->forceFill([
            'quota_status' => TenantUsage::STATUS_PASS_THROUGH,
        ])->save();

        $nextCycle = $service->getOrCreateCurrentCycle($tenant, CarbonImmutable::parse('2026-05-02 00:00:00', 'UTC'));

        $this->assertNotSame($firstCycle->getKey(), $nextCycle->getKey());
        $this->assertSame('2026-05-01 00:00:00', $nextCycle->cycle_start_at->utc()->toDateTimeString());
        $this->assertSame(TenantUsage::STATUS_ACTIVE, $nextCycle->quota_status);
        $this->assertSame(2, TenantUsage::query()->count());
    }
}
