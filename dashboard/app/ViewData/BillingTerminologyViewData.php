<?php

namespace App\ViewData;

class BillingTerminologyViewData
{
    public function sourceLabel(?string $source): string
    {
        return match ($source) {
            'manual_grant' => 'Bonus allowance',
            'paid_subscription' => 'Paid plan',
            default => 'Plan limit',
        };
    }

    public function sourceDescription(?string $source): string
    {
        return match ($source) {
            'manual_grant' => 'Temporary extra allowance added by VerifySky support.',
            'paid_subscription' => 'Limits come from the active paid subscription.',
            default => 'Limits come from the account plan.',
        };
    }

    /**
     * @param  array<string, mixed>  $billingStatus
     * @param  array<string, mixed>  $metric
     * @return array{base:string,bonus:string,total:string,has_bonus:bool}
     */
    public function billingMetricEquation(array $billingStatus, array $metric, string $limitKey): array
    {
        $total = max(0, (int) ($metric['limit'] ?? 0));
        $source = (string) ($billingStatus['effective_plan_source'] ?? 'baseline');
        $base = $total;

        if ($source === 'manual_grant') {
            $base = $this->planLimit((string) ($billingStatus['baseline_plan_key'] ?? ''), $limitKey);
        }

        $bonus = max(0, $total - $base);

        return [
            'base' => number_format($base),
            'bonus' => number_format($bonus),
            'total' => number_format($total),
            'has_bonus' => $bonus > 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $domainsUsage
     * @return array{base:string,bonus:string,total:string,has_bonus:bool}
     */
    public function domainEquation(array $domainsUsage): array
    {
        $base = $domainsUsage['included_limit'] ?? $domainsUsage['limit'] ?? null;
        $bonus = max(0, (int) ($domainsUsage['extra_allowance'] ?? 0));
        $total = $domainsUsage['limit'] ?? null;

        return [
            'base' => $base === null ? 'Unlimited' : number_format(max(0, (int) $base)),
            'bonus' => number_format($bonus),
            'total' => $total === null ? 'Unlimited' : number_format(max(0, (int) $total)),
            'has_bonus' => $bonus > 0,
        ];
    }

    private function planLimit(string $planKey, string $limitKey): int
    {
        $normalized = strtolower(trim($planKey));
        $aliases = (array) config('plans.aliases', []);
        $resolved = (string) ($aliases[$normalized] ?? $normalized);
        $plans = (array) config('plans.plans', []);
        if (! isset($plans[$resolved]) || ! is_array($plans[$resolved])) {
            $resolved = (string) config('plans.default', 'starter');
        }

        return max(0, (int) config("plans.plans.{$resolved}.limits.{$limitKey}", 0));
    }
}
