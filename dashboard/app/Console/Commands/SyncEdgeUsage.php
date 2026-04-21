<?php

namespace App\Console\Commands;

use App\Jobs\PurgeRuntimeBundleCache;
use App\Jobs\SendUsageThresholdWarningMailJob;
use App\Models\Tenant;
use App\Models\TenantUsage;
use App\Services\Billing\BillingCycleService;
use App\Services\Billing\CloudflareAnalyticsService;
use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\Mail\TenantOwnerNotificationService;
use App\Services\Plans\PlanLimitsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncEdgeUsage extends Command
{
    protected $signature = 'billing:sync-edge-usage
        {--tenant=* : Restrict syncing to one or more tenant IDs}
        {--now= : Override the current UTC time for testing}';

    protected $description = 'Reconcile protected session and bot usage for each tenant billing cycle.';

    public function handle(
        BillingCycleService $billingCycles,
        PlanLimitsService $planLimits,
        D1DatabaseClient $d1,
        CloudflareAnalyticsService $cloudflareAnalytics,
        TenantOwnerNotificationService $ownerNotifications
    ): int {
        $now = $this->resolveNow();
        if ($now === null) {
            return self::INVALID;
        }

        $processed = 0;
        $skipped = 0;

        $this->tenantQuery()->chunkById(100, function ($tenants) use (
            $billingCycles,
            $planLimits,
            $d1,
            $cloudflareAnalytics,
            $ownerNotifications,
            $now,
            &$processed,
            &$skipped
        ): void {
            foreach ($tenants as $tenant) {
                if (! $tenant instanceof Tenant) {
                    $skipped++;

                    continue;
                }

                $processed++;

                if (! $this->syncTenant($tenant, $now, $billingCycles, $planLimits, $d1, $cloudflareAnalytics, $ownerNotifications)) {
                    $skipped++;
                }
            }
        });

        $this->info(sprintf(
            'Edge usage sync completed. Processed: %d. Skipped: %d.',
            $processed,
            $skipped
        ));

        return self::SUCCESS;
    }

    private function syncTenant(
        Tenant $tenant,
        CarbonImmutable $now,
        BillingCycleService $billingCycles,
        PlanLimitsService $planLimits,
        D1DatabaseClient $d1,
        CloudflareAnalyticsService $cloudflareAnalytics,
        TenantOwnerNotificationService $ownerNotifications
    ): bool {
        $cycle = $billingCycles->getOrCreateCurrentCycle($tenant, $now);
        $hostnames = $this->tenantHostnames($tenant);

        $protectedSessions = $this->fetchProtectedSessionsUsage(
            $d1,
            $hostnames,
            CarbonImmutable::parse((string) $cycle->cycle_start_at, 'UTC')->utc(),
            CarbonImmutable::parse((string) $cycle->cycle_end_at, 'UTC')->utc()
        );
        if (! $protectedSessions['ok']) {
            Log::warning('Skipping tenant usage sync after protected session query failure.', [
                'tenant_id' => $tenant->getKey(),
                'error' => $protectedSessions['error'] ?? 'Unknown error',
            ]);

            return false;
        }

        $botRequests = $cloudflareAnalytics->getBotRequestsUsage(
            $hostnames,
            CarbonImmutable::parse((string) $cycle->cycle_start_at, 'UTC')->utc(),
            CarbonImmutable::parse((string) $cycle->cycle_end_at, 'UTC')->utc()
        );
        if (! $botRequests['ok']) {
            Log::warning('Skipping tenant usage sync after Cloudflare analytics failure.', [
                'tenant_id' => $tenant->getKey(),
                'error' => $botRequests['error'] ?? 'Unknown error',
            ]);

            return false;
        }

        $limits = $planLimits->getBillingUsageLimits($tenant);
        $transitionedToPassThrough = false;
        $usageWarningCycleId = null;

        DB::transaction(function () use (
            $cycle,
            $protectedSessions,
            $botRequests,
            $limits,
            $now,
            &$transitionedToPassThrough,
            &$usageWarningCycleId
        ): void {
            $usage = TenantUsage::query()->lockForUpdate()->findOrFail($cycle->getKey());
            $exceeded = (int) $protectedSessions['total'] > (int) $limits['protected_sessions']
                || (int) $botRequests['total'] > (int) $limits['bot_fair_use'];
            $shouldSendWarning = $usage->usage_warning_sent_at === null
                && $this->hasReachedWarningThreshold((int) $protectedSessions['total'], (int) $botRequests['total'], $limits);

            $nextStatus = (string) $usage->quota_status;
            if ($exceeded && $nextStatus === TenantUsage::STATUS_ACTIVE) {
                $nextStatus = TenantUsage::STATUS_PASS_THROUGH;
                $transitionedToPassThrough = true;
            }

            $usage->forceFill([
                'protected_sessions_used' => (int) $protectedSessions['total'],
                'bot_requests_used' => (int) $botRequests['total'],
                'quota_status' => $nextStatus,
                'last_reconciled_at' => $now->toDateTimeString(),
                'usage_warning_sent_at' => $shouldSendWarning
                    ? $now->toDateTimeString()
                    : ($usage->usage_warning_sent_at !== null
                        ? CarbonImmutable::parse((string) $usage->usage_warning_sent_at, 'UTC')->toDateTimeString()
                        : null),
            ])->save();

            if ($shouldSendWarning) {
                $usageWarningCycleId = (int) $usage->getKey();
            }
        });

        if ($transitionedToPassThrough) {
            foreach ($hostnames as $hostname) {
                PurgeRuntimeBundleCache::dispatch($hostname);
            }
        }

        if ($usageWarningCycleId !== null) {
            SendUsageThresholdWarningMailJob::dispatch(
                (int) $tenant->getKey(),
                $usageWarningCycleId,
                $ownerNotifications->ownerEmailsForTenant($tenant)
            );
        }

        return true;
    }

    /**
     * @param  array<int, string>  $hostnames
     * @return array{ok:bool,error:?string,total:int}
     */
    private function fetchProtectedSessionsUsage(
        D1DatabaseClient $d1,
        array $hostnames,
        CarbonImmutable $cycleStartAt,
        CarbonImmutable $cycleEndAt
    ): array {
        if ($hostnames === []) {
            return ['ok' => true, 'error' => null, 'total' => 0];
        }

        $quotedHostnames = implode(', ', array_map(
            fn (string $hostname): string => $this->quoteSqlString($hostname),
            $hostnames
        ));

        $sql = sprintf(
            "SELECT COUNT(*) AS total
             FROM security_logs
             WHERE event_type = 'session_created'
               AND domain_name IN (%s)
               AND datetime(created_at) >= datetime(%s)
               AND datetime(created_at) < datetime(%s)",
            $quotedHostnames,
            $this->quoteSqlString($cycleStartAt->format('Y-m-d H:i:s')),
            $this->quoteSqlString($cycleEndAt->format('Y-m-d H:i:s'))
        );

        $result = $d1->query($sql);
        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => $result['error'] ?? 'Failed to query protected sessions usage.',
                'total' => 0,
            ];
        }

        $rows = $d1->parseWranglerJson((string) ($result['output'] ?? ''))[0]['results'] ?? [];

        return [
            'ok' => true,
            'error' => null,
            'total' => (int) ($rows[0]['total'] ?? 0),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function tenantHostnames(Tenant $tenant): array
    {
        return $tenant->domains()
            ->orderBy('id')
            ->pluck('hostname')
            ->map(static fn (string $hostname): string => strtolower(trim($hostname)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function tenantQuery(): Builder
    {
        $query = Tenant::query()->orderBy('id');
        $tenantIds = array_values(array_filter(array_map(
            static fn (mixed $tenantId): int => (int) $tenantId,
            (array) $this->option('tenant')
        ), static fn (int $tenantId): bool => $tenantId > 0));

        if ($tenantIds !== []) {
            $query->whereIn('id', $tenantIds);
        }

        return $query;
    }

    private function resolveNow(): ?CarbonImmutable
    {
        $override = trim((string) $this->option('now'));
        if ($override === '') {
            return CarbonImmutable::now('UTC');
        }

        try {
            return CarbonImmutable::parse($override, 'UTC')->utc();
        } catch (\Throwable) {
            $this->error('The --now option must be a valid datetime string.');

            return null;
        }
    }

    private function quoteSqlString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * @param  array{protected_sessions:int,bot_fair_use:int,plan_key:string,plan_name:string}  $limits
     */
    private function hasReachedWarningThreshold(int $protectedSessionsUsed, int $botRequestsUsed, array $limits): bool
    {
        return $this->percentageForWarning($protectedSessionsUsed, (int) $limits['protected_sessions']) >= 80
            || $this->percentageForWarning($botRequestsUsed, (int) $limits['bot_fair_use']) >= 80;
    }

    private function percentageForWarning(int $used, int $limit): int
    {
        if ($limit <= 0) {
            return 0;
        }

        return (int) floor(($used / $limit) * 100);
    }
}
