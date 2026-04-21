<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantPlanGrant;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class EffectiveTenantPlanService
{
    public function __construct(private readonly TenantSubscriptionService $subscriptions) {}

    /**
     * @return array{
     *   key:string,
     *   name:string,
     *   price_monthly:int,
     *   limits:array,
     *   upgrade_to:mixed,
     *   source:string,
     *   baseline_key:string,
     *   baseline_name:string,
     *   active_subscription:?TenantSubscription,
     *   active_grant:?TenantPlanGrant
     * }
     */
    public function effectivePlanForTenant(Tenant $tenant, ?CarbonInterface $at = null): array
    {
        $baseline = $this->planDefinitionForKey((string) ($tenant->plan ?: config('plans.default', 'starter')));
        $activeSubscription = $this->activeSubscriptionForTenant($tenant);
        $activeGrant = $this->activeGrantForTenant($tenant, $at);

        $effective = $baseline;
        $source = 'baseline';

        if ($activeSubscription instanceof TenantSubscription) {
            $effective = $this->planDefinitionForKey($activeSubscription->plan_key);
            $source = 'paid_subscription';
        }

        if ($activeGrant instanceof TenantPlanGrant) {
            $effective = $this->planDefinitionForKey($activeGrant->granted_plan_key);
            $source = 'manual_grant';
        }

        return array_merge($effective, [
            'source' => $source,
            'baseline_key' => $baseline['key'],
            'baseline_name' => $baseline['name'],
            'active_subscription' => $activeSubscription,
            'active_grant' => $activeGrant,
        ]);
    }

    public function effectivePlanKeyForTenant(Tenant $tenant, ?CarbonInterface $at = null): string
    {
        return (string) $this->effectivePlanForTenant($tenant, $at)['key'];
    }

    public function activeGrantForTenant(Tenant $tenant, ?CarbonInterface $at = null): ?TenantPlanGrant
    {
        if (! $this->grantStorageReady()) {
            return null;
        }

        $pointInTime = $at
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');

        $grant = $tenant->planGrants()
            ->where('status', TenantPlanGrant::STATUS_ACTIVE)
            ->where('starts_at', '<=', $pointInTime->toDateTimeString())
            ->where('ends_at', '>', $pointInTime->toDateTimeString())
            ->latest('starts_at')
            ->first();

        return $grant instanceof TenantPlanGrant ? $grant : null;
    }

    public function activeSubscriptionForTenant(Tenant $tenant): ?TenantSubscription
    {
        return $this->subscriptions->storageReady()
            ? $this->subscriptions->activeSubscriptionForTenant($tenant)
            : null;
    }

    public function grantStorageReady(): bool
    {
        return Schema::hasTable('tenant_plan_grants');
    }

    /**
     * @return array{key:string,name:string,price_monthly:int,limits:array,upgrade_to:mixed}
     */
    public function planDefinitionForKey(string $planKey): array
    {
        $defaultKey = (string) config('plans.default', 'starter');
        $resolved = $this->resolvePlanKey($planKey);
        $plan = config("plans.plans.{$resolved}");

        if (! is_array($plan)) {
            $resolved = $defaultKey;
            $plan = (array) config("plans.plans.{$resolved}", []);
        }

        return [
            'key' => $resolved,
            'name' => (string) ($plan['name'] ?? ucfirst($resolved)),
            'price_monthly' => (int) ($plan['price_monthly'] ?? 0),
            'limits' => is_array($plan['limits'] ?? null) ? $plan['limits'] : [],
            'upgrade_to' => $plan['upgrade_to'] ?? null,
        ];
    }

    public function isPaidPlan(string $planKey): bool
    {
        return $this->resolvePlanKey($planKey) !== 'starter';
    }

    private function resolvePlanKey(string $plan): string
    {
        $normalized = strtolower(trim($plan));
        $aliases = (array) config('plans.aliases', []);
        $resolved = (string) ($aliases[$normalized] ?? $normalized);

        return is_array(config("plans.plans.{$resolved}")) ? $resolved : (string) config('plans.default', 'starter');
    }
}
