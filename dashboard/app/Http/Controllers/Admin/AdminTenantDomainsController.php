<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Domains\DeleteDomainGroupAction;
use App\Actions\Domains\RefreshDomainGroupVerificationAction;
use App\Actions\Domains\RefreshDomainVerificationAction;
use App\Actions\Domains\ToggleDomainForceCaptchaAction;
use App\Actions\Domains\UpdateDomainOriginAction;
use App\Actions\Domains\UpdateDomainSecurityModeAction;
use App\Actions\Domains\UpdateDomainStatusAction;
use App\Actions\Domains\UpdateDomainThresholdsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Domains\ToggleDomainForceCaptchaRequest;
use App\Http\Requests\Domains\UpdateDomainOriginRequest;
use App\Http\Requests\Domains\UpdateDomainSecurityModeRequest;
use App\Http\Requests\Domains\UpdateDomainStatusRequest;
use App\Http\Requests\Domains\UpdateDomainTuningRequest;
use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Repositories\DomainConfigRepository;
use App\Services\Domains\DnsVerificationService;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use App\ViewData\DomainIndexViewData;
use App\ViewData\DomainTuningViewData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AdminTenantDomainsController extends Controller
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly DomainConfigRepository $domainConfigs,
        private readonly UpdateDomainOriginAction $updateDomainOrigin,
        private readonly UpdateDomainSecurityModeAction $updateDomainSecurityMode,
        private readonly UpdateDomainStatusAction $updateDomainStatus,
        private readonly ToggleDomainForceCaptchaAction $toggleDomainForceCaptcha,
        private readonly UpdateDomainThresholdsAction $updateDomainThresholds,
        private readonly RefreshDomainVerificationAction $refreshDomainVerification,
        private readonly RefreshDomainGroupVerificationAction $refreshDomainGroupVerification,
        private readonly DeleteDomainGroupAction $deleteDomainGroup,
        private readonly PlanLimitsService $planLimits
    ) {}

    public function index(Tenant $tenant): View
    {
        $result = $this->domainConfigs->listForTenant((string) $tenant->getKey(), false);
        $viewData = new DomainIndexViewData(
            $result,
            (string) config('edgeshield.saas_cname_target', 'customers.verifysky.com'),
            true,
            $this->planLimits->getDomainsUsage($tenant)
        );

        return view('admin.tenants.domains.index', array_merge($viewData->toArray(), [
            'tenant' => $tenant,
            'title' => 'Manage Domains | '.$tenant->name,
            'domainTenant' => $tenant,
        ]));
    }

    public function statuses(Tenant $tenant): JsonResponse
    {
        $result = $this->domainConfigs->listForTenant((string) $tenant->getKey(), false);

        return response()->json($this->domainStatusPayload($result));
    }

    public function show(Tenant $tenant, string $domain): View
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);
        $domainName = (string) $domainRecord->hostname;
        $config = $this->domainConfig($tenant, $domainName);
        $rules = $this->firewallRulesFor($domainName);
        $tuning = (new DomainTuningViewData($domainName, $config))->toArray();

        return view('admin.tenants.domains.show', array_merge($tuning, [
            'tenant' => $tenant,
            'domainRecord' => $domainRecord,
            'config' => $config,
            'rules' => $rules,
        ]));
    }

    public function updateOrigin(UpdateDomainOriginRequest $request, Tenant $tenant, string $domain): RedirectResponse
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);
        $result = $this->updateDomainOrigin->execute(
            (string) $domainRecord->hostname,
            (string) $request->validated()['origin_server'],
            (string) $tenant->getKey(),
            false
        );

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Server updated and cache cleared.' : ($result['error'] ?? 'Failed to update server.'));
    }

    public function updateSecurityMode(UpdateDomainSecurityModeRequest $request, Tenant $tenant, string $domain): RedirectResponse
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);
        $result = $this->updateDomainSecurityMode->execute(
            (string) $domainRecord->hostname,
            (string) $request->validated()['security_mode'],
            true,
            (string) $tenant->getKey()
        );

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Security mode updated and runtime cache purged.' : ($result['error'] ?? 'Failed to update security mode.'));
    }

    public function updateStatus(UpdateDomainStatusRequest $request, Tenant $tenant, string $domain): RedirectResponse
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);
        $result = $this->updateDomainStatus->execute(
            (string) $domainRecord->hostname,
            (string) $request->validated()['status'],
            false,
            (string) $tenant->getKey()
        );

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Runtime protection updated.' : ($result['error'] ?? 'Failed to update runtime protection.'));
    }

    public function toggleForceCaptcha(ToggleDomainForceCaptchaRequest $request, Tenant $tenant, string $domain): RedirectResponse
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);
        $result = $this->toggleDomainForceCaptcha->execute(
            (string) $domainRecord->hostname,
            (int) $request->validated()['force_captcha'],
            false,
            (string) $tenant->getKey()
        );

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Force CAPTCHA setting updated and runtime cache purged.' : ($result['error'] ?? 'Failed to update force CAPTCHA.'));
    }

    public function updateTuning(UpdateDomainTuningRequest $request, Tenant $tenant, string $domain): RedirectResponse
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);
        $result = $this->updateDomainThresholds->execute(
            (string) $domainRecord->hostname,
            $request->validated(),
            $request->boolean('ad_traffic_strict_mode'),
            false,
            (string) $tenant->getKey()
        );

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Tuning updated and runtime cache purged.' : ($result['error'] ?? 'Failed to update tuning.'));
    }

    public function syncRoute(Tenant $tenant, string $domain): RedirectResponse
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);
        $result = $this->refreshDomainVerification->execute((string) $domainRecord->hostname, (string) $tenant->getKey(), false);

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Route and hostname status refreshed.' : ($result['error'] ?? 'Failed to refresh route status.'));
    }

    public function syncGroup(Tenant $tenant, string $domain): RedirectResponse
    {
        abort_unless($this->domainGroupBelongsToTenant($tenant, $domain), 404);
        $result = $this->refreshDomainGroupVerification->execute($domain, (string) $tenant->getKey(), false);
        if (! $result['ok']) {
            return back()->with('error', $result['error'] ?? 'Failed to refresh route status.');
        }

        return back()->with('status', 'Domain verification status refreshed for '.implode(', ', $result['refreshed'] ?? []).'.');
    }

    public function destroyGroup(Tenant $tenant, string $domain): RedirectResponse
    {
        abort_unless($this->domainGroupBelongsToTenant($tenant, $domain), 404);
        $result = $this->deleteDomainGroup->execute($domain, false, (string) $tenant->getKey());
        if (! $result['ok']) {
            return back()->with('error', $result['error'] ?? 'Failed to remove domain from VerifySky.');
        }

        return back()->with(! empty($result['warning']) ? 'warning' : 'status', $result['warning'] ?? 'Domain removed from VerifySky.');
    }

    public function purgeRuntimeCache(Tenant $tenant, string $domain): RedirectResponse
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);
        PurgeRuntimeBundleCache::dispatch((string) $domainRecord->hostname);

        return back()->with('status', 'Runtime bundle cache purge queued for '.$domainRecord->hostname.'.');
    }

    private function domainForTenant(Tenant $tenant, string $domain): TenantDomain
    {
        $hostname = app(DnsVerificationService::class)->normalizeDomain($domain);
        $baseQuery = TenantDomain::query()->where('tenant_id', $tenant->getKey());

        $exact = (clone $baseQuery)->where('hostname', $hostname)->first();
        if ($exact instanceof TenantDomain) {
            return $exact;
        }

        $bySetupIntent = (clone $baseQuery)
            ->where(function ($query) use ($hostname): void {
                $query
                    ->where('requested_domain', $hostname)
                    ->orWhere('canonical_hostname', $hostname);
            })
            ->first();
        if ($bySetupIntent instanceof TenantDomain) {
            return $bySetupIntent;
        }

        if (app(DnsVerificationService::class)->looksLikeApexDomain($hostname)) {
            $wwwRecord = (clone $baseQuery)->where('hostname', 'www.'.$hostname)->first();
            if ($wwwRecord instanceof TenantDomain) {
                return $wwwRecord;
            }
        }

        abort(404);
    }

    private function domainConfig(Tenant $tenant, string $domain): array
    {
        $result = $this->edgeShield->getDomainConfig($domain, (string) $tenant->getKey(), false);

        if (($result['ok'] ?? false) && is_array($result['config'] ?? null)) {
            return $result['config'];
        }

        return [];
    }

    private function firewallRulesFor(string $domain): array
    {
        $result = $this->edgeShield->listAllCustomFirewallRules();
        if (! ($result['ok'] ?? false)) {
            return [];
        }

        return array_values(array_filter(
            $result['rules'] ?? [],
            fn (array $rule): bool => strtolower((string) ($rule['domain_name'] ?? '')) === strtolower($domain)
        ));
    }

    private function domainGroupBelongsToTenant(Tenant $tenant, string $domain): bool
    {
        $hostnames = $this->edgeShield->saasHostnamesForInput($domain);
        $hostnames[] = strtolower(trim($domain));

        return TenantDomain::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereIn('hostname', array_values(array_unique(array_filter($hostnames))))
            ->exists();
    }

    private function domainStatusPayload(array $result): array
    {
        $viewData = (new DomainIndexViewData(
            $result,
            (string) config('edgeshield.saas_cname_target', 'customers.verifysky.com'),
            true,
            []
        ))->toArray();

        $groups = array_map(function (array $group): array {
            return [
                'display_domain' => (string) ($group['display_domain'] ?? ''),
                'primary_domain' => (string) ($group['primary_domain'] ?? ''),
                'overall_status' => (string) ($group['overall_status'] ?? ''),
                'provisioning_status' => (string) ($group['provisioning_status'] ?? ''),
                'primary_hostname_status' => (string) ($group['primary_hostname_status'] ?? ''),
                'primary_ssl_status' => (string) ($group['primary_ssl_status'] ?? ''),
                'primary_verified' => (bool) ($group['primary_verified'] ?? false),
                'is_active' => strtolower((string) ($group['status'] ?? 'active')) === 'active',
                'health_score' => (int) ($group['health_score'] ?? 0),
                'dns_active_count' => (int) ($group['dns_active_count'] ?? 0),
                'ssl_active_count' => (int) ($group['ssl_active_count'] ?? 0),
                'total_checks' => (int) ($group['total_checks'] ?? 1),
                'live_status' => is_array($group['live_status'] ?? null) ? $group['live_status'] : [],
            ];
        }, $viewData['preparedDomainGroups'] ?? []);

        return [
            'ok' => $viewData['error'] === null,
            'error' => $viewData['error'],
            'polling' => (bool) ($viewData['domains_needs_polling'] ?? false),
            'groups' => $groups,
        ];
    }
}
