<?php

namespace App\Console\Commands;

use App\Actions\Billing\ForceResetTenantBillingCycleAction;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Billing\TenantSubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReconcileExpiredSubscriptions extends Command
{
    protected $signature = 'billing:reconcile-expired-subscriptions
        {--now= : Override the reconciliation timestamp in UTC for testing}';

    protected $description = 'Downgrade tenants whose canceled or suspended paid subscriptions have fully expired.';

    public function handle(
        TenantSubscriptionService $subscriptions,
        ForceResetTenantBillingCycleAction $forceResetTenantBillingCycle
    ): int {
        if (! $subscriptions->storageReady()) {
            $this->info('Billing subscription tables are not ready. Skipping reconciliation.');

            return self::SUCCESS;
        }

        $now = $this->option('now')
            ? CarbonImmutable::parse((string) $this->option('now'), 'UTC')->utc()
            : CarbonImmutable::now('UTC');

        $resetTenantIds = [];
        TenantSubscription::query()
            ->with('tenant')
            ->whereIn('status', [
                TenantSubscription::STATUS_CANCELED,
                TenantSubscription::STATUS_SUSPENDED,
            ])
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '<=', $now->toDateTimeString())
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($forceResetTenantBillingCycle, $now, &$resetTenantIds): void {
                foreach ($chunk as $subscription) {
                    $tenant = $subscription->tenant;
                    if (! $tenant instanceof Tenant) {
                        continue;
                    }

                    $hasActiveReplacement = $tenant->subscriptions()
                        ->where('id', '!=', $subscription->id)
                        ->where('status', TenantSubscription::STATUS_ACTIVE)
                        ->exists();

                    $subscription->forceFill([
                        'status' => TenantSubscription::STATUS_EXPIRED,
                        'cancel_at_period_end' => false,
                    ])->save();

                    if ($hasActiveReplacement || in_array($tenant->getKey(), $resetTenantIds, true)) {
                        continue;
                    }

                    if ($tenant->plan !== 'starter') {
                        $tenant->forceFill([
                            'plan' => 'starter',
                        ])->save();
                    }

                    $forceResetTenantBillingCycle->execute($tenant, $now);
                    $resetTenantIds[] = $tenant->getKey();
                }
            });

        $this->info(sprintf('Reconciled expired subscriptions at %s UTC.', $now->format('Y-m-d H:i:s')));

        return self::SUCCESS;
    }
}
