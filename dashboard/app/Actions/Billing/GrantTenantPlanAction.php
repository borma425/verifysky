<?php

namespace App\Actions\Billing;

use App\Jobs\SendManualGrantActivatedMailJob;
use App\Models\Tenant;
use App\Models\TenantPlanGrant;
use App\Models\User;
use App\Services\Billing\EffectiveTenantPlanService;
use App\Services\Mail\TenantOwnerNotificationService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class GrantTenantPlanAction
{
    public function __construct(
        private readonly EffectiveTenantPlanService $effectivePlans,
        private readonly ForceResetTenantBillingCycleAction $forceResetTenantBillingCycle,
        private readonly TenantOwnerNotificationService $ownerNotifications
    ) {}

    /**
     * @return array{grant:TenantPlanGrant,reset_performed:bool,reset_at:CarbonImmutable|null,effective_plan_key:string}
     */
    public function execute(
        Tenant $tenant,
        string $planKey,
        int $durationDays,
        ?User $grantedBy = null,
        ?string $reason = null,
        ?CarbonInterface $startsAt = null
    ): array {
        $normalizedPlanKey = strtolower(trim($planKey));
        $plan = $this->effectivePlans->planDefinitionForKey($normalizedPlanKey);
        if (! $this->effectivePlans->isPaidPlan($plan['key'])) {
            throw new \InvalidArgumentException('Manual grants must target a paid plan.');
        }

        $start = $startsAt
            ? CarbonImmutable::instance($startsAt)->utc()
            : CarbonImmutable::now('UTC');
        $end = $start->addDays(max(1, $durationDays));
        $previousEffectivePlanKey = $this->effectivePlans->effectivePlanKeyForTenant($tenant, $start);

        $grant = DB::transaction(function () use ($tenant, $plan, $start, $end, $grantedBy, $reason): TenantPlanGrant {
            $tenant->planGrants()
                ->where('status', TenantPlanGrant::STATUS_ACTIVE)
                ->update([
                    'status' => TenantPlanGrant::STATUS_REVOKED,
                    'revoked_at' => $start->toDateTimeString(),
                    'revoked_by_user_id' => $grantedBy?->getKey(),
                ]);

            $grant = $tenant->planGrants()->create([
                'granted_plan_key' => $plan['key'],
                'source' => 'manual',
                'status' => TenantPlanGrant::STATUS_ACTIVE,
                'starts_at' => $start->toDateTimeString(),
                'ends_at' => $end->toDateTimeString(),
                'granted_by_user_id' => $grantedBy?->getKey(),
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                'metadata_json' => [
                    'duration_days' => max(1, $end->diffInDays($start)),
                ],
            ]);

            if (! $grant instanceof TenantPlanGrant) {
                throw new \RuntimeException('Failed to create plan bonus.');
            }

            return $grant;
        });

        $currentEffectivePlanKey = $this->effectivePlans->effectivePlanKeyForTenant($tenant, $start);
        $resetResult = null;
        if ($currentEffectivePlanKey !== $previousEffectivePlanKey) {
            $resetResult = $this->forceResetTenantBillingCycle->execute($tenant, $start);
        }

        SendManualGrantActivatedMailJob::dispatch(
            (int) $tenant->getKey(),
            (int) $grant->getKey(),
            $this->ownerNotifications->ownerEmailsForTenant($tenant)
        );

        return [
            'grant' => $grant->fresh() ?? $grant,
            'reset_performed' => $resetResult !== null,
            'reset_at' => $resetResult['reset_at'] ?? null,
            'effective_plan_key' => $currentEffectivePlanKey,
        ];
    }
}
