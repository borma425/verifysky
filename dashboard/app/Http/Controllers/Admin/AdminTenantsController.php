<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Billing\ForceResetTenantBillingCycleAction;
use App\Actions\Billing\GrantTenantPlanAction;
use App\Actions\Billing\RevokeTenantPlanGrantAction;
use App\Actions\Domains\ProvisionTenantDomainAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Domains\AdminStoreTenantDomainRequest;
use App\Models\Tenant;
use App\Models\TenantPlanGrant;
use App\Models\User;
use App\Services\Billing\BillingPlanCatalogService;
use App\Services\Billing\TenantBillingStatusService;
use App\Services\Domains\DomainAssetPolicyService;
use App\Services\Plans\PlanLimitsService;
use App\ViewData\Admin\AdminTenantRowViewData;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AdminTenantsController extends Controller
{
    public function __construct(
        private readonly TenantBillingStatusService $tenantBillingStatus,
        private readonly ForceResetTenantBillingCycleAction $forceResetTenantBillingCycle,
        private readonly GrantTenantPlanAction $grantTenantPlan,
        private readonly RevokeTenantPlanGrantAction $revokeTenantPlanGrant,
        private readonly ProvisionTenantDomainAction $provisionTenantDomain,
        private readonly BillingPlanCatalogService $planCatalog,
        private readonly PlanLimitsService $planLimits,
        private readonly AdminTenantRowViewData $rowViewData,
        private readonly DomainAssetPolicyService $domainAssets
    ) {}

    public function index(): View
    {
        $billingAvailable = $this->billingTablesAvailable();
        $query = Tenant::query()
            ->withCount('domains')
            ->with([
                'domains:id,tenant_id,hostname,hostname_status,ssl_status,security_mode,force_captcha,updated_at',
                'memberships.user:id,name,email,role',
            ])
            ->orderBy('name');

        if ($billingAvailable) {
            $query->with([
                'latestUsageCycle',
                'planGrants' => fn ($relation) => $relation->orderByDesc('starts_at'),
                'subscriptions' => fn ($relation) => $relation->orderByDesc('updated_at'),
            ]);
        }

        $tenants = $query->paginate(25);
        $tenantRows = $tenants
            ->getCollection()
            ->map(fn (Tenant $tenant): array => $this->rowViewData->fromTenant($tenant, $billingAvailable))
            ->all();

        return view('admin.tenants.index', [
            'tenants' => $tenants,
            'tenantRows' => $tenantRows,
            'billingAvailable' => $billingAvailable,
            'grantablePlans' => $this->planCatalog->paidPlans(),
        ]);
    }

    public function show(Tenant $tenant): View
    {
        $billingAvailable = $this->billingTablesAvailable();
        $tenant->load([
            'domains' => fn ($relation) => $relation->orderBy('hostname'),
            'memberships.user:id,name,email,role',
        ]);

        if ($billingAvailable) {
            $tenant->load([
                'latestUsageCycle',
                'usageCycles' => fn ($relation) => $relation->orderByDesc('cycle_start_at'),
                'planGrants.grantedBy:id,name,email',
                'planGrants.revokedBy:id,name,email',
                'subscriptions' => fn ($relation) => $relation->orderByDesc('updated_at'),
            ]);
        }

        return view('admin.tenants.show', [
            'tenant' => $tenant,
            'row' => $this->rowViewData->fromTenant($tenant, $billingAvailable),
            'domainsUsage' => $this->planLimits->getDomainsUsage($tenant),
            'domainAssetSummaries' => $this->domainAssets->summariesForTenant($tenant),
            'billingAvailable' => $billingAvailable,
            'grantablePlans' => $this->planCatalog->paidPlans(),
        ]);
    }

    public function storeDomain(AdminStoreTenantDomainRequest $request, Tenant $tenant): RedirectResponse
    {
        $result = $this->provisionTenantDomain->execute($request->validated(), (string) $tenant->getKey());
        if (! $result['ok']) {
            $response = back()->withInput()->with('error', $result['error']);
            if (($result['origin_detection_failed'] ?? false) === true) {
                $response->with('domain_origin_detection_failed', true);
            }
            if (($result['quarantine_blocked'] ?? false) === true) {
                $response->with('domain_quarantine', [
                    'asset_key' => (string) ($result['asset_key'] ?? ''),
                    'quarantined_until' => $result['quarantined_until'] ?? null,
                ]);
            }

            return $response;
        }

        $message = (string) ($result['message'] ?? ('Domain setup started for '.implode(', ', $result['created']).'. VerifySky is turning on protection for this user.'));

        $response = back()->with('status', $message);
        if (is_array($result['domain_setup'] ?? null)) {
            $response->with('domain_setup', $result['domain_setup']);
        }

        return $response;
    }

    public function forceCycleReset(Tenant $tenant): RedirectResponse
    {
        if ($this->tenantBillingStatus->forTenant($tenant) === null) {
            return back()->with('error', 'Billing setup is pending. Run billing migrations before resetting a billing cycle.');
        }

        $result = $this->forceResetTenantBillingCycle->execute($tenant);

        return back()->with(
            'status',
            sprintf(
                'Billing cycle reset for %s. New cycle started at %s UTC.',
                $result['tenant']->name,
                $result['reset_at']->format('Y-m-d H:i:s')
            )
        );
    }

    public function grantPlan(Request $request, Tenant $tenant): RedirectResponse
    {
        if ($this->tenantBillingStatus->forTenant($tenant) === null) {
            return back()->with('error', 'Billing setup is pending. Run billing migrations before adding a bonus.');
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
                '%s bonus activated for %s until %s UTC%s',
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
            return back()->with('error', 'Only active bonuses can be removed.');
        }

        $result = $this->revokeTenantPlanGrant->execute($grant, $this->adminUserFromSession());

        return back()->with(
            'status',
            sprintf(
                '%s bonus removed for %s%s',
                strtoupper((string) $grant->granted_plan_key),
                $tenant->name,
                $result['reset_performed'] ? '. Billing cycle was reset.' : '.'
            )
        );
    }

    private function billingTablesAvailable(): bool
    {
        return Schema::hasTable('tenant_usage')
            && Schema::hasTable('tenant_plan_grants')
            && Schema::hasTable('tenant_subscriptions');
    }

    private function adminUserFromSession(): ?User
    {
        $userId = session('user_id');

        return is_numeric($userId) ? User::query()->find((int) $userId) : null;
    }
}
