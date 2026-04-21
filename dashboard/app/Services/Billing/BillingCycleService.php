<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantUsage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BillingCycleService
{
    private ?bool $usageStorageReady = null;

    public function getOrCreateCurrentCycle(Tenant $tenant, ?CarbonInterface $at = null): TenantUsage
    {
        $bounds = $this->currentCycleBounds($tenant, $at);

        return DB::transaction(function () use ($tenant, $bounds): TenantUsage {
            return TenantUsage::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->getKey(),
                    'cycle_start_at' => $bounds['cycle_start_at']->toDateTimeString(),
                ],
                [
                    'cycle_end_at' => $bounds['cycle_end_at']->toDateTimeString(),
                    'protected_sessions_used' => 0,
                    'bot_requests_used' => 0,
                    'quota_status' => TenantUsage::STATUS_ACTIVE,
                    'last_reconciled_at' => null,
                    'usage_warning_sent_at' => null,
                ]
            );
        });
    }

    /**
     * @return array{cycle_start_at:CarbonImmutable,cycle_end_at:CarbonImmutable}
     */
    public function currentCycleBounds(Tenant $tenant, ?CarbonInterface $at = null): array
    {
        $anchor = $this->anchorForTenant($tenant);
        $pointInTime = $at
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');

        if ($pointInTime->lt($anchor)) {
            return [
                'cycle_start_at' => $anchor,
                'cycle_end_at' => $anchor->addMonthNoOverflow(),
            ];
        }

        $months = (($pointInTime->year - $anchor->year) * 12) + ($pointInTime->month - $anchor->month);

        while ($months > 0 && $anchor->addMonthsNoOverflow($months)->gt($pointInTime)) {
            $months--;
        }

        while ($anchor->addMonthsNoOverflow($months + 1)->lte($pointInTime)) {
            $months++;
        }

        return [
            'cycle_start_at' => $anchor->addMonthsNoOverflow($months),
            'cycle_end_at' => $anchor->addMonthsNoOverflow($months + 1),
        ];
    }

    public function anchorForTenant(Tenant $tenant): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $tenant->billing_start_at, 'UTC')->utc();
    }

    public function usageStorageReady(): bool
    {
        return $this->usageStorageReady ??= Schema::hasTable('tenant_usage')
            && Schema::hasColumn('tenants', 'billing_start_at');
    }
}
