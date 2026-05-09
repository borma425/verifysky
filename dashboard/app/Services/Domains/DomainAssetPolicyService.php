<?php

namespace App\Services\Domains;

use App\Actions\Billing\ForceResetTenantBillingCycleAction;
use App\Models\DomainAssetHistory;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantPlanGrant;
use App\Services\Billing\EffectiveTenantPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pdp\Rules;
use Throwable;

class DomainAssetPolicyService
{
    private static ?Rules $rules = null;

    public function __construct(
        private readonly EffectiveTenantPlanService $effectivePlans,
        private readonly ForceResetTenantBillingCycleAction $forceResetTenantBillingCycle
    ) {}

    /**
     * @return array{hostname:string,asset_key:string,asset_type:string,registrable_domain:?string,trial_eligible:bool}
     */
    public function describe(string $hostname): array
    {
        $normalized = $this->normalizeHostname($hostname);
        $shared = $this->isSharedHostname($normalized);
        $registrableDomain = $shared ? null : $this->registrableDomain($normalized);

        return [
            'hostname' => $normalized,
            'asset_key' => $shared ? $normalized : $registrableDomain,
            'asset_type' => $shared ? DomainAssetHistory::TYPE_SHARED_HOSTNAME : DomainAssetHistory::TYPE_REGISTRABLE_DOMAIN,
            'registrable_domain' => $registrableDomain,
            'trial_eligible' => ! $shared,
        ];
    }

    /**
     * @return array{blocked:bool,message:?string,quarantined_until:?CarbonImmutable,asset_key:string}
     */
    public function quarantineStatusForTenant(string $hostname, Tenant $tenant, bool $isAdmin = false): array
    {
        $asset = $this->describe($hostname);
        $until = null;
        $blocked = false;

        if ($this->storageReady()) {
            $history = DomainAssetHistory::query()
                ->where('asset_key', $asset['asset_key'])
                ->first();
            $until = $history?->quarantined_until
                ? CarbonImmutable::parse((string) $history->quarantined_until, 'UTC')->utc()
                : null;
            $blocked = $until instanceof CarbonImmutable
                && $until->isFuture()
                && ! $this->canBypassQuarantine($tenant, $isAdmin);
        }

        return [
            'blocked' => $blocked,
            'message' => $blocked ? 'This domain was recently removed from VerifySky. Upgrade to a paid plan to reactivate it now, or contact support.' : null,
            'quarantined_until' => $until,
            'asset_key' => $asset['asset_key'],
        ];
    }

    /**
     * @param  array<int, string>  $hostnames
     */
    public function quarantineRemovedHostnames(array $hostnames, ?string $tenantId, string $reason): void
    {
        if (! $this->storageReady()) {
            return;
        }

        $tenant = is_numeric($tenantId) ? (int) $tenantId : null;
        $now = CarbonImmutable::now('UTC');
        $until = $now->addDays(max(1, (int) config('domain_assets.quarantine_days', 30)));

        foreach (array_values(array_unique(array_filter($hostnames))) as $hostname) {
            $asset = $this->describe($hostname);
            $this->ensureHistoryRow($asset);

            DomainAssetHistory::query()
                ->where('asset_key', $asset['asset_key'])
                ->update([
                    'asset_type' => $asset['asset_type'],
                    'registrable_domain' => $asset['registrable_domain'],
                    'hostname' => $asset['hostname'],
                    'quarantined_until' => $until->toDateTimeString(),
                    'last_removed_at' => $now->toDateTimeString(),
                    'last_removed_tenant_id' => $tenant,
                    'last_removal_reason' => $reason,
                    'updated_at' => $now->toDateTimeString(),
                ]);
        }
    }

    /**
     * @return array{granted:bool,reason:string,grant_id:?int,asset_key:string}
     */
    public function grantTrialIfEligible(TenantDomain $domain): array
    {
        if (! $this->storageReady() || ! Schema::hasTable('tenant_plan_grants')) {
            return ['granted' => false, 'reason' => 'storage_unavailable', 'grant_id' => null, 'asset_key' => ''];
        }

        $tenant = $domain->tenant;
        if (! $tenant instanceof Tenant) {
            return ['granted' => false, 'reason' => 'missing_tenant', 'grant_id' => null, 'asset_key' => ''];
        }

        $asset = $this->describe((string) $domain->hostname);
        if (! $asset['trial_eligible']) {
            $this->ensureHistoryRow($asset);

            return ['granted' => false, 'reason' => 'shared_hostname', 'grant_id' => null, 'asset_key' => $asset['asset_key']];
        }

        if ($this->tenantAlreadyHasPaidAccess($tenant)) {
            $this->ensureHistoryRow($asset);

            return ['granted' => false, 'reason' => 'tenant_already_paid', 'grant_id' => null, 'asset_key' => $asset['asset_key']];
        }

        $now = CarbonImmutable::now('UTC');
        $grant = DB::transaction(function () use ($tenant, $domain, $asset, $now): ?TenantPlanGrant {
            $this->ensureHistoryRow($asset);

            $history = DomainAssetHistory::query()
                ->where('asset_key', $asset['asset_key'])
                ->lockForUpdate()
                ->first();

            if (! $history instanceof DomainAssetHistory || $history->pro_trial_granted_at !== null) {
                return null;
            }

            $hasActiveGrant = $tenant->planGrants()
                ->where('status', TenantPlanGrant::STATUS_ACTIVE)
                ->where('ends_at', '>', $now->toDateTimeString())
                ->exists();
            if ($hasActiveGrant) {
                return null;
            }

            $trialDays = max(1, (int) config('domain_assets.trial_days', 14));
            $trialPlan = strtolower(trim((string) config('domain_assets.trial_plan', 'pro'))) ?: 'pro';

            $grant = $tenant->planGrants()->create([
                'granted_plan_key' => $trialPlan,
                'source' => 'trial',
                'status' => TenantPlanGrant::STATUS_ACTIVE,
                'starts_at' => $now->toDateTimeString(),
                'ends_at' => $now->addDays($trialDays)->toDateTimeString(),
                'granted_by_user_id' => null,
                'reason' => 'Automatic Pro trial for verified domain '.$asset['asset_key'],
                'metadata_json' => [
                    'duration_days' => $trialDays,
                    'domain_asset_key' => $asset['asset_key'],
                    'domain_asset_type' => $asset['asset_type'],
                    'tenant_domain_id' => (int) $domain->getKey(),
                    'hostname' => (string) $domain->hostname,
                ],
            ]);

            if (! $grant instanceof TenantPlanGrant) {
                throw new \RuntimeException('Failed to create domain trial grant.');
            }

            $history->forceFill([
                'asset_type' => $asset['asset_type'],
                'registrable_domain' => $asset['registrable_domain'],
                'hostname' => $asset['hostname'],
                'pro_trial_granted_at' => $now->toDateTimeString(),
                'pro_trial_tenant_id' => (int) $tenant->getKey(),
                'pro_trial_grant_id' => (int) $grant->getKey(),
                'metadata_json' => array_merge($history->metadata_json ?? [], [
                    'trial_hostname' => (string) $domain->hostname,
                ]),
            ])->save();

            return $grant;
        });

        if ($grant instanceof TenantPlanGrant) {
            $this->forceResetTenantBillingCycle->execute($tenant, $now);

            return ['granted' => true, 'reason' => 'granted', 'grant_id' => (int) $grant->getKey(), 'asset_key' => $asset['asset_key']];
        }

        return ['granted' => false, 'reason' => 'already_granted_or_active_grant', 'grant_id' => null, 'asset_key' => $asset['asset_key']];
    }

    /**
     * @return array<int, array{hostname:string,asset_key:string,asset_type:string,registrable_domain:?string,trial_used:bool,quarantined_until:?CarbonImmutable,last_removed_at:?CarbonImmutable}>
     */
    public function summariesForTenant(Tenant $tenant): array
    {
        if (! $this->storageReady()) {
            return [];
        }

        $assetsByHostname = [];
        foreach ($tenant->domains()->orderBy('hostname')->pluck('hostname') as $hostname) {
            $hostname = (string) $hostname;
            $assetsByHostname[$hostname] = $this->describe($hostname);
        }

        if ($assetsByHostname === []) {
            return [];
        }

        $histories = DomainAssetHistory::query()
            ->whereIn('asset_key', array_values(array_unique(array_column($assetsByHostname, 'asset_key'))))
            ->get()
            ->keyBy('asset_key');

        $summaries = [];
        foreach ($assetsByHostname as $hostname => $asset) {
            $history = $histories->get($asset['asset_key']);
            $summaries[] = [
                'hostname' => $hostname,
                'asset_key' => $asset['asset_key'],
                'asset_type' => $asset['asset_type'],
                'registrable_domain' => $asset['registrable_domain'],
                'trial_used' => $history instanceof DomainAssetHistory && $history->pro_trial_granted_at !== null,
                'quarantined_until' => $history instanceof DomainAssetHistory && $history->quarantined_until !== null
                    ? CarbonImmutable::parse((string) $history->quarantined_until, 'UTC')->utc()
                    : null,
                'last_removed_at' => $history instanceof DomainAssetHistory && $history->last_removed_at !== null
                    ? CarbonImmutable::parse((string) $history->last_removed_at, 'UTC')->utc()
                    : null,
            ];
        }

        return $summaries;
    }

    private function ensureHistoryRow(array $asset): void
    {
        $now = now();
        DomainAssetHistory::query()->insertOrIgnore([
            'asset_key' => $asset['asset_key'],
            'asset_type' => $asset['asset_type'],
            'registrable_domain' => $asset['registrable_domain'],
            'hostname' => $asset['hostname'],
            'metadata_json' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function canBypassQuarantine(Tenant $tenant, bool $isAdmin): bool
    {
        return $isAdmin || $this->tenantAlreadyHasPaidAccess($tenant);
    }

    private function tenantAlreadyHasPaidAccess(Tenant $tenant): bool
    {
        return $this->effectivePlans->isPaidPlan(
            $this->effectivePlans->effectivePlanKeyForTenant($tenant)
        );
    }

    private function storageReady(): bool
    {
        return Schema::hasTable('domain_asset_histories');
    }

    private function registrableDomain(string $hostname): string
    {
        try {
            $registrableDomain = self::rules()->resolve($hostname)->registrableDomain()->toString();
            if (trim($registrableDomain) !== '') {
                return strtolower(trim($registrableDomain));
            }
        } catch (Throwable) {
            //
        }

        return $hostname;
    }

    private function isSharedHostname(string $hostname): bool
    {
        foreach ((array) config('domain_assets.shared_suffixes', []) as $suffix) {
            $suffix = $this->normalizeHostname((string) $suffix);
            if ($suffix === '') {
                continue;
            }

            if ($hostname === $suffix || str_ends_with($hostname, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeHostname(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        $hostname = preg_replace('#^https?://#', '', $hostname) ?? $hostname;
        $hostname = explode('/', $hostname, 2)[0];
        $hostname = rtrim(trim($hostname), '.');

        if (! str_contains($hostname, '[') && str_contains($hostname, ':')) {
            $hostname = explode(':', $hostname, 2)[0];
        }

        return $hostname;
    }

    private static function rules(): Rules
    {
        if (self::$rules instanceof Rules) {
            return self::$rules;
        }

        $path = (string) config('domain_assets.public_suffix_list_path');

        return self::$rules = Rules::fromPath($path);
    }
}
