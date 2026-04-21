<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantPlanGrant;
use App\Models\TenantUsage;
use App\Services\Plans\PlanLimitsService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class TenantBillingStatusService
{
    public function __construct(
        private readonly BillingCycleService $billingCycles,
        private readonly PlanLimitsService $planLimits,
        private readonly EffectiveTenantPlanService $effectivePlans
    ) {}

    public function forTenant(Tenant $tenant, ?CarbonInterface $at = null): ?array
    {
        if (! $this->billingCycles->usageStorageReady()) {
            return null;
        }

        $cycle = $this->billingCycles->getOrCreateCurrentCycle($tenant, $at);
        $limits = $this->planLimits->getBillingUsageLimits($tenant);
        $effectivePlan = $this->effectivePlans->effectivePlanForTenant($tenant, $at);

        return [
            'tenant_id' => (int) $tenant->getKey(),
            'tenant_name' => (string) $tenant->name,
            'tenant_slug' => (string) $tenant->slug,
            'tenant_status' => (string) $tenant->status,
            'plan_key' => (string) $limits['plan_key'],
            'plan_name' => (string) $limits['plan_name'],
            'baseline_plan_key' => (string) $effectivePlan['baseline_key'],
            'baseline_plan_name' => (string) $effectivePlan['baseline_name'],
            'effective_plan_source' => (string) $effectivePlan['source'],
            'active_grant' => $this->formatGrant($effectivePlan['active_grant']),
            'quota_status' => (string) $cycle->quota_status,
            'is_pass_through' => (string) $cycle->quota_status === TenantUsage::STATUS_PASS_THROUGH,
            'current_cycle_start_at' => CarbonImmutable::parse((string) $cycle->cycle_start_at, 'UTC')->utc(),
            'current_cycle_end_at' => CarbonImmutable::parse((string) $cycle->cycle_end_at, 'UTC')->utc(),
            'protected_sessions' => $this->metric(
                (int) $cycle->protected_sessions_used,
                (int) $limits['protected_sessions']
            ),
            'bot_requests' => $this->metric(
                (int) $cycle->bot_requests_used,
                (int) $limits['bot_fair_use']
            ),
        ];
    }

    public function forTenantId(?string $tenantId, ?CarbonInterface $at = null): ?array
    {
        if (! $this->billingCycles->usageStorageReady()) {
            return null;
        }

        $normalizedTenantId = trim((string) $tenantId);
        if ($normalizedTenantId === '') {
            return null;
        }

        $tenant = Tenant::query()->find($normalizedTenantId);

        return $tenant instanceof Tenant ? $this->forTenant($tenant, $at) : null;
    }

    /**
     * @return array{used:int,limit:int,remaining:int,percentage:int,formatted_used:string,formatted_limit:string,formatted_remaining:string,level:string,is_exhausted:bool}
     */
    private function metric(int $used, int $limit): array
    {
        $normalizedLimit = max(0, $limit);
        $normalizedUsed = max(0, $used);
        $remaining = max(0, $normalizedLimit - $normalizedUsed);
        $percentage = $normalizedLimit === 0
            ? ($normalizedUsed > 0 ? 100 : 0)
            : min(100, (int) round(($normalizedUsed / $normalizedLimit) * 100));
        $level = $percentage >= 100 ? 'danger' : ($percentage >= 80 ? 'warning' : 'normal');

        return [
            'used' => $normalizedUsed,
            'limit' => $normalizedLimit,
            'remaining' => $remaining,
            'percentage' => $percentage,
            'formatted_used' => number_format($normalizedUsed),
            'formatted_limit' => number_format($normalizedLimit),
            'formatted_remaining' => number_format($remaining),
            'level' => $level,
            'is_exhausted' => $normalizedLimit > 0 && $normalizedUsed >= $normalizedLimit,
        ];
    }

    private function formatGrant(mixed $grant): ?array
    {
        if (! $grant instanceof TenantPlanGrant) {
            return null;
        }

        return [
            'id' => (int) $grant->getKey(),
            'granted_plan_key' => (string) $grant->granted_plan_key,
            'starts_at' => CarbonImmutable::parse((string) $grant->starts_at, 'UTC')->utc(),
            'ends_at' => CarbonImmutable::parse((string) $grant->ends_at, 'UTC')->utc(),
            'reason' => $grant->reason !== null ? (string) $grant->reason : null,
        ];
    }
}
