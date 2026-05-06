<?php

namespace App\Actions\Billing;

use App\Models\Tenant;
use App\Models\TenantPlanGrant;
use App\Models\User;
use App\Services\Billing\EffectiveTenantPlanService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class RevokeTenantPlanGrantAction
{
    public function __construct(
        private readonly EffectiveTenantPlanService $effectivePlans,
        private readonly ForceResetTenantBillingCycleAction $forceResetTenantBillingCycle
    ) {}

    /**
     * @return array{grant:TenantPlanGrant,reset_performed:bool,reset_at:CarbonImmutable|null,effective_plan_key:string}
     */
    public function execute(TenantPlanGrant $grant, ?User $revokedBy = null, ?CarbonInterface $revokedAt = null): array
    {
        $tenant = $grant->tenant;
        if (! $tenant instanceof Tenant) {
            throw new \RuntimeException('Plan bonus is not attached to a user.');
        }
        $timestamp = $revokedAt
            ? CarbonImmutable::instance($revokedAt)->utc()
            : CarbonImmutable::now('UTC');

        $previousEffectivePlanKey = $this->effectivePlans->effectivePlanKeyForTenant($tenant, $timestamp);

        DB::transaction(function () use ($grant, $revokedBy, $timestamp): void {
            $grant->forceFill([
                'status' => TenantPlanGrant::STATUS_REVOKED,
                'revoked_at' => $timestamp->toDateTimeString(),
                'revoked_by_user_id' => $revokedBy?->getKey(),
            ])->save();
        });

        $currentEffectivePlanKey = $this->effectivePlans->effectivePlanKeyForTenant($tenant, $timestamp);
        $resetResult = null;
        if ($currentEffectivePlanKey !== $previousEffectivePlanKey) {
            $resetResult = $this->forceResetTenantBillingCycle->execute($tenant, $timestamp);
        }

        return [
            'grant' => $grant->fresh() ?? $grant,
            'reset_performed' => $resetResult !== null,
            'reset_at' => $resetResult['reset_at'] ?? null,
            'effective_plan_key' => $currentEffectivePlanKey,
        ];
    }
}
