<?php

namespace App\Services\Billing;

class BillingPlanCatalogService
{
    /**
     * @return array<int, array{key:string,name:string,price_monthly:int,limits:array,provider_plan_id:?string}>
     */
    public function paidPlans(): array
    {
        $plans = [];
        foreach ((array) config('plans.plans', []) as $key => $definition) {
            if (! is_array($definition) || $key === 'starter') {
                continue;
            }

            $plans[] = [
                'key' => (string) $key,
                'name' => (string) ($definition['name'] ?? ucfirst((string) $key)),
                'price_monthly' => max(0, (int) ($definition['price_monthly'] ?? 0)),
                'limits' => is_array($definition['limits'] ?? null) ? $definition['limits'] : [],
                'provider_plan_id' => $this->providerPlanIdFor((string) $key),
            ];
        }

        return $plans;
    }

    public function isPaidPlan(string $planKey): bool
    {
        return $this->plan($planKey) !== null;
    }

    /**
     * @return array{key:string,name:string,price_monthly:int,limits:array,provider_plan_id:?string}|null
     */
    public function plan(string $planKey): ?array
    {
        $normalized = strtolower(trim($planKey));
        foreach ($this->paidPlans() as $plan) {
            if ($plan['key'] === $normalized) {
                return $plan;
            }
        }

        return null;
    }

    public function providerPlanIdFor(string $planKey): ?string
    {
        $planId = config('services.paypal.plans.'.strtolower(trim($planKey)));

        return is_string($planId) && trim($planId) !== '' ? trim($planId) : null;
    }
}
