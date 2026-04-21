<?php

namespace App\Actions\Billing;

use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\Tenant;
use App\Models\TenantUsage;
use App\Services\Billing\BillingCycleService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ForceResetTenantBillingCycleAction
{
    public function __construct(private readonly BillingCycleService $billingCycles) {}

    /**
     * @return array{tenant:Tenant,cycle:TenantUsage,reset_at:CarbonImmutable}
     */
    public function execute(Tenant $tenant, ?CarbonInterface $resetAt = null): array
    {
        $anchor = $resetAt
            ? CarbonImmutable::instance($resetAt)->utc()
            : CarbonImmutable::now('UTC');

        DB::transaction(function () use ($tenant, $anchor): void {
            $tenant->forceFill([
                'billing_start_at' => $anchor->toDateTimeString(),
            ])->save();
        });

        $tenant->refresh();
        $cycle = $this->billingCycles->getOrCreateCurrentCycle($tenant, $anchor);

        foreach ($tenant->domains()->orderBy('id')->pluck('hostname')->filter()->unique() as $hostname) {
            PurgeRuntimeBundleCache::dispatch((string) $hostname);
        }

        return [
            'tenant' => $tenant,
            'cycle' => $cycle,
            'reset_at' => $anchor,
        ];
    }
}
