<?php

namespace App\Console\Commands;

use App\Actions\Billing\ForceResetTenantBillingCycleAction;
use App\Models\Tenant;
use App\Models\TenantPlanGrant;
use App\Services\Billing\EffectiveTenantPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReconcileExpiredPlanGrants extends Command
{
    protected $signature = 'billing:reconcile-expired-plan-grants
        {--now= : Override the reconciliation timestamp in UTC for testing}';

    protected $description = 'Expire manual plan grants and restore each tenant to the correct effective plan.';

    public function handle(
        EffectiveTenantPlanService $effectivePlans,
        ForceResetTenantBillingCycleAction $forceResetTenantBillingCycle
    ): int {
        if (! $effectivePlans->grantStorageReady()) {
            $this->info('Tenant plan grant table is not ready. Skipping reconciliation.');

            return self::SUCCESS;
        }

        $now = $this->option('now')
            ? CarbonImmutable::parse((string) $this->option('now'), 'UTC')->utc()
            : CarbonImmutable::now('UTC');

        TenantPlanGrant::query()
            ->with('tenant')
            ->where('status', TenantPlanGrant::STATUS_ACTIVE)
            ->where('ends_at', '<=', $now->toDateTimeString())
            ->orderBy('id')
            ->chunkById(100, function ($grants) use ($effectivePlans, $forceResetTenantBillingCycle, $now): void {
                foreach ($grants as $grant) {
                    $tenant = $grant->tenant;
                    if (! $tenant instanceof Tenant) {
                        continue;
                    }

                    $beforeKey = (string) $grant->granted_plan_key;

                    $grant->forceFill([
                        'status' => TenantPlanGrant::STATUS_EXPIRED,
                    ])->save();

                    $afterKey = $effectivePlans->effectivePlanKeyForTenant($tenant, $now);
                    if ($beforeKey !== $afterKey) {
                        $forceResetTenantBillingCycle->execute($tenant, $now);
                    }
                }
            });

        $this->info(sprintf('Reconciled expired plan grants at %s UTC.', $now->format('Y-m-d H:i:s')));

        return self::SUCCESS;
    }
}
