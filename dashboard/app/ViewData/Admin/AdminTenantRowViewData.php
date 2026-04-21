<?php

namespace App\ViewData\Admin;

use App\Models\Tenant;
use App\Models\TenantPlanGrant;
use App\Models\TenantSubscription;
use App\Models\TenantUsage;
use App\Services\Billing\EffectiveTenantPlanService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AdminTenantRowViewData
{
    public function __construct(
        private readonly EffectiveTenantPlanService $effectivePlans
    ) {}

    public function fromTenant(Tenant $tenant, bool $billingAvailable, ?CarbonInterface $at = null): array
    {
        $baselinePlan = $this->effectivePlans->planDefinitionForKey((string) $tenant->plan);
        $activeGrant = $billingAvailable ? $this->activeGrant($tenant, $at) : null;
        $activeSubscription = $billingAvailable ? $this->activeSubscription($tenant) : null;
        $effectivePlan = $this->effectivePlan($baselinePlan, $activeSubscription, $activeGrant);
        $cycle = $billingAvailable ? $tenant->latestUsageCycle : null;
        $limits = $effectivePlan['limits'] ?? [];

        return [
            'tenant' => $tenant,
            'billing_available' => $billingAvailable,
            'domains_count' => (int) ($tenant->domains_count ?? $tenant->domains->count()),
            'baseline_plan' => $baselinePlan,
            'effective_plan' => $effectivePlan,
            'active_grant' => $this->formatGrant($activeGrant),
            'active_subscription' => $this->formatSubscription($activeSubscription),
            'billing' => $this->formatBillingCycle($tenant, $cycle, $limits, $effectivePlan),
            'members_count' => $tenant->relationLoaded('memberships') ? $tenant->memberships->count() : null,
        ];
    }

    private function activeGrant(Tenant $tenant, ?CarbonInterface $at): ?TenantPlanGrant
    {
        $now = $at ? CarbonImmutable::instance($at)->utc() : CarbonImmutable::now('UTC');

        $active = null;
        foreach ($tenant->planGrants as $grant) {
            if (! $grant instanceof TenantPlanGrant || $grant->status !== TenantPlanGrant::STATUS_ACTIVE) {
                continue;
            }

            $startsAt = CarbonImmutable::parse((string) $grant->starts_at, 'UTC')->utc();
            $endsAt = CarbonImmutable::parse((string) $grant->ends_at, 'UTC')->utc();
            if ($startsAt->lte($now) && $endsAt->gt($now)) {
                if (! $active instanceof TenantPlanGrant || $startsAt->gt(CarbonImmutable::parse((string) $active->starts_at, 'UTC')->utc())) {
                    $active = $grant;
                }
            }
        }

        return $active;
    }

    private function activeSubscription(Tenant $tenant): ?TenantSubscription
    {
        $active = null;
        foreach ($tenant->subscriptions as $subscription) {
            if (! $subscription instanceof TenantSubscription || $subscription->status !== TenantSubscription::STATUS_ACTIVE) {
                continue;
            }

            if (! $active instanceof TenantSubscription
                || CarbonImmutable::parse((string) $subscription->updated_at, 'UTC')->gt(CarbonImmutable::parse((string) $active->updated_at, 'UTC'))) {
                $active = $subscription;
            }
        }

        return $active;
    }

    private function effectivePlan(array $baselinePlan, ?TenantSubscription $subscription, ?TenantPlanGrant $grant): array
    {
        $source = 'baseline';
        $plan = $baselinePlan;

        if ($subscription instanceof TenantSubscription) {
            $plan = $this->effectivePlans->planDefinitionForKey((string) $subscription->plan_key);
            $source = 'paid_subscription';
        }

        if ($grant instanceof TenantPlanGrant) {
            $plan = $this->effectivePlans->planDefinitionForKey((string) $grant->granted_plan_key);
            $source = 'manual_grant';
        }

        return array_merge($plan, [
            'source' => $source,
            'baseline_key' => $baselinePlan['key'],
            'baseline_name' => $baselinePlan['name'],
            'active_subscription' => $subscription,
            'active_grant' => $grant,
        ]);
    }

    private function formatBillingCycle(Tenant $tenant, mixed $cycle, array $limits, array $effectivePlan): ?array
    {
        if (! $cycle instanceof TenantUsage) {
            return null;
        }

        return [
            'tenant_id' => (int) $tenant->getKey(),
            'tenant_name' => (string) $tenant->name,
            'tenant_slug' => (string) $tenant->slug,
            'tenant_status' => (string) $tenant->status,
            'plan_key' => (string) $effectivePlan['key'],
            'plan_name' => (string) $effectivePlan['name'],
            'baseline_plan_key' => (string) $effectivePlan['baseline_key'],
            'baseline_plan_name' => (string) $effectivePlan['baseline_name'],
            'effective_plan_source' => (string) $effectivePlan['source'],
            'active_grant' => $this->formatGrant($effectivePlan['active_grant']),
            'quota_status' => (string) $cycle->quota_status,
            'is_pass_through' => (string) $cycle->quota_status === TenantUsage::STATUS_PASS_THROUGH,
            'current_cycle_start_at' => CarbonImmutable::parse((string) $cycle->cycle_start_at, 'UTC')->utc(),
            'current_cycle_end_at' => CarbonImmutable::parse((string) $cycle->cycle_end_at, 'UTC')->utc(),
            'protected_sessions' => $this->metric((int) $cycle->protected_sessions_used, (int) ($limits['protected_sessions'] ?? 0)),
            'bot_requests' => $this->metric((int) $cycle->bot_requests_used, (int) ($limits['bot_fair_use'] ?? 0)),
        ];
    }

    private function metric(int $used, int $limit): array
    {
        $remaining = max(0, $limit - $used);
        $percentage = $limit === 0 ? ($used > 0 ? 100 : 0) : min(100, (int) round(($used / $limit) * 100));

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $remaining,
            'percentage' => $percentage,
            'formatted_used' => number_format($used),
            'formatted_limit' => number_format($limit),
            'formatted_remaining' => number_format($remaining),
            'level' => $percentage >= 100 ? 'danger' : ($percentage >= 80 ? 'warning' : 'normal'),
            'is_exhausted' => $limit > 0 && $used >= $limit,
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

    private function formatSubscription(mixed $subscription): ?array
    {
        if (! $subscription instanceof TenantSubscription) {
            return null;
        }

        return [
            'id' => (int) $subscription->getKey(),
            'provider' => (string) $subscription->provider,
            'provider_subscription_id' => (string) $subscription->provider_subscription_id,
            'plan_key' => (string) $subscription->plan_key,
            'status' => (string) $subscription->status,
            'current_period_ends_at' => $subscription->current_period_ends_at !== null
                ? CarbonImmutable::parse((string) $subscription->current_period_ends_at, 'UTC')->utc()
                : null,
            'payer_email' => $subscription->payer_email,
        ];
    }
}
