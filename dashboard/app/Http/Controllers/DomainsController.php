<?php

namespace App\Http\Controllers;

use App\Actions\Domains\DeleteDomainAction;
use App\Actions\Domains\DeleteDomainGroupAction;
use App\Actions\Domains\ProvisionTenantDomainAction;
use App\Actions\Domains\RefreshDomainGroupVerificationAction;
use App\Actions\Domains\RefreshDomainVerificationAction;
use App\Actions\Domains\ToggleDomainForceCaptchaAction;
use App\Actions\Domains\UpdateDomainOriginAction;
use App\Actions\Domains\UpdateDomainSecurityModeAction;
use App\Actions\Domains\UpdateDomainStatusAction;
use App\Actions\Domains\UpdateDomainThresholdsAction;
use App\Http\Requests\Domains\StoreDomainRequest;
use App\Http\Requests\Domains\ToggleDomainForceCaptchaRequest;
use App\Http\Requests\Domains\UpdateDomainOriginRequest;
use App\Http\Requests\Domains\UpdateDomainSecurityModeRequest;
use App\Http\Requests\Domains\UpdateDomainStatusRequest;
use App\Http\Requests\Domains\UpdateDomainTuningRequest;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DomainsController extends Controller
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly DomainConfigRepository $domainConfigs,
        private readonly ProvisionTenantDomainAction $provisionTenantDomain,
        private readonly RefreshDomainVerificationAction $refreshDomainVerification,
        private readonly RefreshDomainGroupVerificationAction $refreshDomainGroupVerification,
        private readonly DeleteDomainAction $deleteDomain,
        private readonly DeleteDomainGroupAction $deleteDomainGroup,
        private readonly UpdateDomainOriginAction $updateDomainOrigin,
        private readonly UpdateDomainSecurityModeAction $updateDomainSecurityMode,
        private readonly UpdateDomainStatusAction $updateDomainStatus,
        private readonly ToggleDomainForceCaptchaAction $toggleDomainForceCaptcha,
        private readonly UpdateDomainThresholdsAction $updateDomainThresholds,
        private readonly PlanLimitsService $planLimits
    ) {}

    public function index(): View
    {
        $tenantId = trim((string) session('current_tenant_id'));
        $isAdmin = (bool) session('is_admin');
        $tenant = $tenantId !== '' ? Tenant::query()->find($tenantId) : null;
        $result = $this->domainListResult($tenant, $tenantId, $isAdmin);
        $viewData = new DomainIndexViewData(
            $result,
            (string) config('edgeshield.saas_cname_target', 'customers.verifysky.com'),
            $isAdmin,
            $this->domainUsageForView($tenant, $isAdmin)
        );

        return view('domains.index', $viewData->toArray());
    }

    public function statuses(): JsonResponse
    {
        $tenantId = trim((string) session('current_tenant_id'));
        $isAdmin = (bool) session('is_admin');
        $tenant = $tenantId !== '' ? Tenant::query()->find($tenantId) : null;
        $result = $this->domainListResult($tenant, $tenantId, $isAdmin);

        return response()->json($this->domainStatusPayload($result, $isAdmin));
    }

    public function store(StoreDomainRequest $request): RedirectResponse
    {
        $result = $this->provisionTenantDomain->execute($request->validated(), session('current_tenant_id'));
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

        $message = (string) ($result['message'] ?? '');
        if ($message === '') {
            $message = 'Setup started for '.implode(', ', $result['created'] ?? []).'. Add the DNS record shown on this page to continue.';
            if (($result['origin_mode'] ?? 'manual') === 'auto') {
                $message .= ' VerifySky found your server automatically.';
            }
        }

        $response = back()->with('status', $message);
        if (! empty($result['warning'])) {
            $response->with('warning', (string) $result['warning']);
        }

        $domainSetup = is_array($result['domain_setup'] ?? null) ? $result['domain_setup'] : [];

        return $response->with('domain_setup', array_merge([
            'domains' => $result['created'] ?? [],
            'cname_target' => (string) ($domainSetup['cname_target'] ?? config('edgeshield.saas_cname_target', 'customers.verifysky.com')),
        ], $domainSetup));
    }

    public function updateStatus(string $domain, UpdateDomainStatusRequest $request): RedirectResponse
    {
        $domain = $this->managedHostnameForRequest($domain, session('current_tenant_id'), (bool) session('is_admin'));

        $result = $this->updateDomainStatus->execute(
            $domain,
            $request->validated()['status'],
            (bool) session('is_admin'),
            session('current_tenant_id')
        );

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Domain status updated.' : ($result['error'] ?: 'We could not update the domain status.')
        );
    }

    public function updateOrigin(string $domain, UpdateDomainOriginRequest $request): RedirectResponse
    {
        $domain = $this->managedHostnameForRequest($domain, session('current_tenant_id'), (bool) session('is_admin'));

        $result = $this->updateDomainOrigin->execute(
            $domain,
            $request->validated()['origin_server'],
            session('current_tenant_id'),
            (bool) session('is_admin')
        );

        if (! $result['ok']) {
            return back()->with('error', $result['error']);
        }

        return back()->with('status', 'Server updated. Traffic now goes to '.$request->validated()['origin_server'].'.');
    }

    public function destroy(string $domain): RedirectResponse
    {
        $domain = $this->managedHostnameForRequest($domain, session('current_tenant_id'), (bool) session('is_admin'));

        $result = $this->deleteDomain->execute(
            $domain,
            (bool) session('is_admin'),
            session('current_tenant_id')
        );
        if (! $result['ok']) {
            return back()->with('error', $result['error']);
        }
        if (! empty($result['warning'])) {
            return back()->with('status', $result['warning']);
        }

        return back()->with('status', 'Domain removed completely.');
    }

    public function destroyGroup(string $domain): RedirectResponse
    {
        $result = $this->deleteDomainGroup->execute(
            $domain,
            (bool) session('is_admin'),
            session('current_tenant_id')
        );
        if (! $result['ok']) {
            return back()->with('error', $result['error']);
        }
        if (! empty($result['warning'])) {
            return back()->with('status', $result['warning']);
        }

        return back()->with('status', 'Domain removed from VerifySky.');
    }

    public function toggleForceCaptcha(string $domain, ToggleDomainForceCaptchaRequest $request): RedirectResponse
    {
        $domain = $this->managedHostnameForRequest($domain, session('current_tenant_id'), (bool) session('is_admin'));

        $result = $this->toggleDomainForceCaptcha->execute(
            $domain,
            (int) $request->validated()['force_captcha'],
            (bool) session('is_admin'),
            session('current_tenant_id')
        );

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'CAPTCHA setting updated.' : ($result['error'] ?: 'We could not update CAPTCHA.')
        );
    }

    public function updateSecurityMode(string $domain, UpdateDomainSecurityModeRequest $request): RedirectResponse
    {
        $domain = $this->managedHostnameForRequest($domain, session('current_tenant_id'), (bool) session('is_admin'));

        $result = $this->updateDomainSecurityMode->execute(
            $domain,
            $request->validated()['security_mode'],
            (bool) session('is_admin'),
            session('current_tenant_id')
        );

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Protection level updated.' : ($result['error'] ?: 'We could not update the protection level.')
        );
    }

    public function syncRoute(string $domain): RedirectResponse
    {
        $domain = $this->managedHostnameForRequest($domain, session('current_tenant_id'), (bool) session('is_admin'));

        $sync = $this->refreshDomainVerification->execute(
            $domain,
            session('current_tenant_id'),
            (bool) session('is_admin')
        );

        return back()->with(
            $sync['ok'] ? 'status' : 'error',
            $sync['ok']
                ? 'Domain status refreshed.'
                : 'We could not refresh this domain yet. Please try again in a few minutes.'
        );
    }

    public function syncGroup(string $domain): RedirectResponse
    {
        $sync = $this->refreshDomainGroupVerification->execute(
            $domain,
            session('current_tenant_id'),
            (bool) session('is_admin')
        );
        if (! $sync['ok']) {
            return back()->with('error', $sync['error']);
        }

        return back()->with('status', 'Domain status refreshed for '.implode(', ', $sync['refreshed']).'.');
    }

    public function tuning(string $domain): View|RedirectResponse
    {
        $domain = $this->managedHostnameForRequest($domain, session('current_tenant_id'), (bool) session('is_admin'));

        $result = $this->edgeShield->getDomainConfig($domain, session('current_tenant_id'), (bool) session('is_admin'));
        if (! $result['ok']) {
            return redirect()->route('domains.index')->with('error', $result['error']);
        }

        $viewData = new DomainTuningViewData($domain, $result['config']);

        return view('domains.tuning', $viewData->toArray());
    }

    public function updateTuning(string $domain, UpdateDomainTuningRequest $request): RedirectResponse
    {
        $domain = $this->managedHostnameForRequest($domain, session('current_tenant_id'), (bool) session('is_admin'));

        $result = $this->updateDomainThresholds->execute(
            $domain,
            $request->validated(),
            $request->boolean('ad_traffic_strict_mode'),
            (bool) session('is_admin'),
            session('current_tenant_id')
        );

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Security settings updated.' : ($result['error'] ?: 'We could not update security settings.')
        );
    }

    private function managedHostnameForRequest(string $domain, ?string $tenantId, bool $isAdmin): string
    {
        $hostname = app(DnsVerificationService::class)->normalizeDomain($domain);
        if ($hostname === '' || ! Schema::hasTable('tenant_domains')) {
            return $hostname !== '' ? $hostname : $domain;
        }

        $tenant = trim((string) $tenantId);
        if (! $isAdmin && $tenant === '') {
            return $hostname;
        }

        $query = TenantDomain::query();
        if (! $isAdmin) {
            $query->where('tenant_id', $tenant);
        }

        $exact = (clone $query)->where('hostname', $hostname)->first();
        if ($exact instanceof TenantDomain) {
            return (string) $exact->hostname;
        }

        $bySetupIntent = (clone $query)
            ->where(function ($builder) use ($hostname): void {
                $builder
                    ->where('requested_domain', $hostname)
                    ->orWhere('canonical_hostname', $hostname);
            })
            ->first();
        if ($bySetupIntent instanceof TenantDomain) {
            return (string) $bySetupIntent->hostname;
        }

        if (app(DnsVerificationService::class)->looksLikeApexDomain($hostname)) {
            $www = 'www.'.$hostname;
            $wwwRecord = (clone $query)->where('hostname', $www)->first();
            if ($wwwRecord instanceof TenantDomain) {
                return (string) $wwwRecord->hostname;
            }
        }

        return $hostname;
    }

    private function domainUsageForView(?Tenant $tenant, bool $isAdmin): array
    {
        if ($isAdmin) {
            return [
                'used' => 0,
                'limit' => null,
                'remaining' => null,
                'can_add' => true,
                'plan_key' => $tenant ? $this->planLimits->planDefinitionForTenant($tenant)['key'] : (string) config('plans.default', 'starter'),
                'message' => null,
            ];
        }

        if (! $tenant instanceof Tenant) {
            return [
                'used' => 0,
                'limit' => null,
                'remaining' => null,
                'can_add' => true,
                'plan_key' => (string) config('plans.default', 'starter'),
                'message' => null,
            ];
        }

        return $this->planLimits->getDomainsUsage($tenant);
    }

    private function domainListResult(?Tenant $tenant, string $tenantId, bool $isAdmin): array
    {
        if (! $isAdmin && $tenant instanceof Tenant && ! $tenant->domains()->exists()) {
            return ['ok' => true, 'error' => null, 'domains' => []];
        }

        return $this->domainConfigs->listForTenant($tenantId !== '' ? $tenantId : null, $isAdmin);
    }

    private function domainStatusPayload(array $result, bool $isAdmin): array
    {
        $viewData = (new DomainIndexViewData(
            $result,
            (string) config('edgeshield.saas_cname_target', 'customers.verifysky.com'),
            $isAdmin,
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
