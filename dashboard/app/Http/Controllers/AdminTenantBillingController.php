<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ForceResetTenantBillingCycleAction;
use App\Actions\Billing\GrantTenantPlanAction;
use App\Actions\Billing\RevokeTenantPlanGrantAction;
use App\Models\Tenant;
use App\Models\TenantPlanGrant;
use App\Models\User;
use App\Services\Billing\BillingPlanCatalogService;
use App\Services\Billing\EffectiveTenantPlanService;
use App\Services\Billing\TenantBillingStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminTenantBillingController extends Controller
{
    public function __construct(
        private readonly TenantBillingStatusService $tenantBillingStatus,
        private readonly ForceResetTenantBillingCycleAction $forceResetTenantBillingCycle,
        private readonly GrantTenantPlanAction $grantTenantPlan,
        private readonly RevokeTenantPlanGrantAction $revokeTenantPlanGrant,
        private readonly BillingPlanCatalogService $planCatalog,
        private readonly EffectiveTenantPlanService $effectivePlans
    ) {}

    public function index(): View
    {
        $tenants = Tenant::query()
            ->withCount('domains')
            ->orderBy('name')
            ->get();

        $rows = $tenants->map(function (Tenant $tenant): array {
            $billingStatus = $this->tenantBillingStatus->forTenant($tenant);

            return [
                'tenant' => $tenant,
                'billing' => $billingStatus,
                'billing_available' => $billingStatus !== null,
                'domains_count' => (int) $tenant->domains_count,
                'baseline_plan' => $this->effectivePlans->planDefinitionForKey((string) $tenant->plan),
                'effective_plan' => $this->effectivePlans->effectivePlanForTenant($tenant),
            ];
        })->all();

        return view('admin.tenants.index', [
            'tenantRows' => $rows,
            'grantablePlans' => $this->planCatalog->paidPlans(),
        ]);
    }

    public function forceCycleReset(Tenant $tenant): RedirectResponse
    {
        if ($this->tenantBillingStatus->forTenant($tenant) === null) {
            return back()->with('error', 'Billing migrations are pending. Run the billing migrations before forcing a cycle reset.');
        }

        $result = $this->forceResetTenantBillingCycle->execute($tenant);

        return back()->with(
            'status',
            sprintf(
                'Billing cycle reset for %s. New active cycle started at %s UTC.',
                $result['tenant']->name,
                $result['reset_at']->format('Y-m-d H:i:s')
            )
        );
    }

    public function grantPlan(Request $request, Tenant $tenant): RedirectResponse
    {
        if ($this->tenantBillingStatus->forTenant($tenant) === null) {
            return back()->with('error', 'Billing migrations are pending. Run the billing migrations before granting a plan.');
        }

        $validated = $request->validate([
            'plan_key' => ['required', 'string', 'in:growth,pro,business,scale'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $this->grantTenantPlan->execute(
            $tenant,
            (string) $validated['plan_key'],
            (int) $validated['duration_days'],
            $this->adminUserFromSession(),
            isset($validated['reason']) ? (string) $validated['reason'] : null
        );

        $grant = $result['grant'];

        return back()->with(
            'status',
            sprintf(
                'Manual %s grant activated for %s until %s UTC%s',
                strtoupper((string) $grant->granted_plan_key),
                $tenant->name,
                CarbonImmutable::parse((string) $grant->ends_at, 'UTC')->utc()->format('Y-m-d H:i:s'),
                $result['reset_performed'] ? '. Billing cycle was reset.' : '.'
            )
        );
    }

    public function revokePlanGrant(Tenant $tenant, TenantPlanGrant $grant): RedirectResponse
    {
        if ((int) $grant->tenant_id !== (int) $tenant->getKey()) {
            abort(404);
        }

        if ($grant->status !== TenantPlanGrant::STATUS_ACTIVE) {
            return back()->with('error', 'Only active plan grants can be revoked.');
        }

        $result = $this->revokeTenantPlanGrant->execute($grant, $this->adminUserFromSession());

        return back()->with(
            'status',
            sprintf(
                'Manual %s grant revoked for %s%s',
                strtoupper((string) $grant->granted_plan_key),
                $tenant->name,
                $result['reset_performed'] ? '. Billing cycle was reset.' : '.'
            )
        );
    }

    private function adminUserFromSession(): ?User
    {
        $userId = session('user_id');

        return is_numeric($userId) ? User::query()->find((int) $userId) : null;
    }
}
