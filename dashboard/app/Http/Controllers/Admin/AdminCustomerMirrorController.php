<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logs\FilterSecurityLogsRequest;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Repositories\DomainConfigRepository;
use App\Repositories\SecurityLogRepository;
use App\Services\Billing\BillingPlanCatalogService;
use App\Services\Billing\EffectiveTenantPlanService;
use App\Services\Billing\TenantBillingStatusService;
use App\Services\Billing\TenantSubscriptionService;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use App\ViewData\DomainIndexViewData;
use App\ViewData\DomainTuningViewData;
use App\ViewData\FirewallIndexViewData;
use App\ViewData\LogsIndexViewData;
use Illuminate\Http\Response;
use Illuminate\View\View;

class AdminCustomerMirrorController extends Controller
{
    public function __construct(
        private readonly DomainConfigRepository $domainConfigs,
        private readonly SecurityLogRepository $logs,
        private readonly EdgeShieldService $edgeShield,
        private readonly TenantBillingStatusService $tenantBillingStatus,
        private readonly TenantSubscriptionService $subscriptions,
        private readonly PlanLimitsService $planLimits,
        private readonly EffectiveTenantPlanService $effectivePlans,
        private readonly BillingPlanCatalogService $planCatalog
    ) {}

    public function overview(Tenant $tenant): View
    {
        $domainsView = $this->domainIndexViewData($tenant);
        $billingStatus = $this->tenantBillingStatus->forTenant($tenant);
        $logsView = $this->logsViewData($tenant, []);

        return view('admin.customer.overview', array_merge(
            $this->layoutData($tenant, 'Overview'),
            [
                'billingStatus' => $billingStatus,
                'domainsCount' => count($domainsView['preparedDomainGroups'] ?? []),
                'recentLogs' => array_slice($logsView['logs']->items(), 0, 5),
                'generalStats' => $logsView['generalStats'] ?? [],
                'currentPlan' => $this->planLimits->planDefinitionForTenant($tenant),
            ]
        ));
    }

    public function billing(Tenant $tenant): View
    {
        return view('admin.customer.billing.index', array_merge(
            $this->layoutData($tenant, 'Billing Summary'),
            [
                'tenant' => $tenant,
                'currentPlan' => $this->planLimits->planDefinitionForTenant($tenant),
                'billingStatus' => $this->tenantBillingStatus->forTenant($tenant),
                'subscription' => $this->subscriptions->currentSubscriptionForTenant($tenant),
                'billingStorageReady' => $this->subscriptions->storageReady(),
                'activeGrant' => $this->effectivePlans->activeGrantForTenant($tenant),
                'planCards' => $this->planCatalog->displayPlans(),
            ]
        ));
    }

    public function domains(Tenant $tenant): View
    {
        return view('admin.customer.domains.index', array_merge(
            $this->layoutData($tenant, 'Domains'),
            $this->domainIndexViewData($tenant)
        ));
    }

    public function tuning(Tenant $tenant, string $domain): View
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);
        $result = $this->edgeShield->getDomainConfig((string) $domainRecord->hostname, (string) $tenant->getKey(), false);
        abort_unless(($result['ok'] ?? false) && is_array($result['config'] ?? null), 404);

        return view('admin.customer.domains.tuning', array_merge(
            $this->layoutData($tenant, 'Domain Tuning'),
            (new DomainTuningViewData((string) $domainRecord->hostname, $result['config']))->toArray(),
            [
                'domainRecord' => $domainRecord,
                'tenant' => $tenant,
            ]
        ));
    }

    public function firewall(Tenant $tenant): View
    {
        $tenantId = (string) $tenant->getKey();
        $domainsRes = $this->edgeShield->listDomains($tenantId, false);
        $domains = $domainsRes['ok'] ? ($domainsRes['domains'] ?? []) : [];

        $allRulesRes = $this->edgeShield->listAllCustomFirewallRules();
        $allowedDomains = $this->planLimits->managedDomainNames($tenantId, false);
        $rules = ($allRulesRes['ok'] ?? false)
            ? array_values(array_filter(
                $allRulesRes['rules'] ?? [],
                fn (array $rule): bool => in_array((string) ($rule['domain_name'] ?? ''), $allowedDomains, true)
            ))
            : [];

        $viewData = new FirewallIndexViewData(
            $domains,
            $rules,
            array_values(array_filter([
                ! ($domainsRes['ok'] ?? false) ? 'Failed to load domains.' : null,
                ! ($allRulesRes['ok'] ?? false) ? ($allRulesRes['error'] ?? 'Failed to load firewall rules.') : null,
            ])),
            1,
            1,
            count($rules),
            $this->planLimits->getFirewallRulesUsage($tenantId, false),
            false,
            false
        );

        return view('admin.customer.firewall.index', array_merge(
            $this->layoutData($tenant, 'Firewall Rules'),
            $viewData->toArray()
        ));
    }

    public function logs(FilterSecurityLogsRequest $request, Tenant $tenant): View
    {
        return view('admin.customer.logs.index', array_merge(
            $this->layoutData($tenant, 'Security Logs'),
            $this->logsViewData($tenant, $request->validated())
        ));
    }

    public function rejectMutation(): Response
    {
        abort(403, 'The admin customer mirror is read-only.');
    }

    /**
     * @return array<string, mixed>
     */
    private function domainIndexViewData(Tenant $tenant): array
    {
        $result = $this->domainConfigs->listForTenant((string) $tenant->getKey(), false);

        return (new DomainIndexViewData(
            $result,
            (string) config('edgeshield.saas_cname_target', 'customers.verifysky.com'),
            false,
            $this->planLimits->getDomainsUsage($tenant)
        ))->toArray();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function logsViewData(Tenant $tenant, array $filters): array
    {
        $payload = $this->logs->fetchIndexPayload($filters, (string) $tenant->getKey(), false);

        return (new LogsIndexViewData(
            $payload,
            $filters,
            route('admin.tenants.customer.logs.index', $tenant),
            false
        ))->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function layoutData(Tenant $tenant, string $title): array
    {
        return [
            'title' => sprintf('%s | %s', $title, $tenant->name),
            'tenant' => $tenant,
            'mirrorPageTitle' => $title,
        ];
    }

    private function domainForTenant(Tenant $tenant, string $domain): TenantDomain
    {
        return TenantDomain::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('hostname', strtolower(trim($domain)))
            ->firstOrFail();
    }
}
