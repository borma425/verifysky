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
use App\Repositories\DomainConfigRepository;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use App\ViewData\DomainIndexViewData;
use App\ViewData\DomainTuningViewData;
use Illuminate\Http\RedirectResponse;
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
        $result = $this->domainConfigs->listForTenant($tenantId !== '' ? $tenantId : null, $isAdmin);
        $viewData = new DomainIndexViewData(
            $result,
            (string) config('edgeshield.saas_cname_target', 'customers.verifysky.com'),
            $isAdmin,
            $this->domainUsageForView($tenant, $isAdmin)
        );

        return view('domains.index', $viewData->toArray());
    }

    public function store(StoreDomainRequest $request): RedirectResponse
    {
        $result = $this->provisionTenantDomain->execute($request->validated(), session('current_tenant_id'));
        if (! $result['ok']) {
            $response = back()->withInput()->with('error', $result['error']);
            if (($result['origin_detection_failed'] ?? false) === true) {
                $response->with('domain_origin_detection_failed', true);
            }

            return $response;
        }

        $message = 'Route creation started for '.implode(', ', $result['created']).'. Add the DNS record shown in the setup panel to continue verification.';
        if (($result['origin_mode'] ?? 'manual') === 'auto') {
            $message .= ' Backend origin was detected automatically.';
        }

        return back()->with(
            'status',
            $message
        )->with('domain_setup', [
            'domains' => $result['created'],
            'cname_target' => $this->edgeShield->saasCnameTarget(),
        ]);
    }

    public function updateStatus(string $domain, UpdateDomainStatusRequest $request): RedirectResponse
    {
        $result = $this->updateDomainStatus->execute(
            $domain,
            $request->validated()['status'],
            (bool) session('is_admin'),
            session('current_tenant_id')
        );

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Domain status updated.' : ($result['error'] ?: 'Failed to update status')
        );
    }

    public function updateOrigin(string $domain, UpdateDomainOriginRequest $request): RedirectResponse
    {
        $result = $this->updateDomainOrigin->execute(
            $domain,
            $request->validated()['origin_server'],
            session('current_tenant_id'),
            (bool) session('is_admin')
        );

        if (! $result['ok']) {
            return back()->with('error', $result['error']);
        }

        return back()->with('status', 'Target Origin Server successfully updated. Traffic is now routing to '.$request->validated()['origin_server'].'.');
    }

    public function destroy(string $domain): RedirectResponse
    {
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
        $result = $this->toggleDomainForceCaptcha->execute(
            $domain,
            (int) $request->validated()['force_captcha'],
            (bool) session('is_admin'),
            session('current_tenant_id')
        );

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Forced CAPTCHA mode updated.' : ($result['error'] ?: 'Failed to update forced CAPTCHA mode')
        );
    }

    public function updateSecurityMode(string $domain, UpdateDomainSecurityModeRequest $request): RedirectResponse
    {
        $result = $this->updateDomainSecurityMode->execute(
            $domain,
            $request->validated()['security_mode'],
            (bool) session('is_admin'),
            session('current_tenant_id')
        );

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Security mode updated.' : ($result['error'] ?: 'Failed to update security mode')
        );
    }

    public function syncRoute(string $domain): RedirectResponse
    {
        $sync = $this->refreshDomainVerification->execute($domain);

        return back()->with(
            $sync['ok'] ? 'status' : 'error',
            $sync['ok']
                ? 'Domain verification status refreshed.'
                : 'We could not refresh this domain yet. Please try again in a few minutes.'
        );
    }

    public function syncGroup(string $domain): RedirectResponse
    {
        $sync = $this->refreshDomainGroupVerification->execute($domain);
        if (! $sync['ok']) {
            return back()->with('error', $sync['error']);
        }

        return back()->with('status', 'Domain verification status refreshed for '.implode(', ', $sync['refreshed']).'.');
    }

    public function tuning(string $domain): View|RedirectResponse
    {
        $result = $this->edgeShield->getDomainConfig($domain, session('current_tenant_id'), (bool) session('is_admin'));
        if (! $result['ok']) {
            return redirect()->route('domains.index')->with('error', $result['error']);
        }

        $viewData = new DomainTuningViewData($domain, $result['config']);

        return view('domains.tuning', $viewData->toArray());
    }

    public function updateTuning(string $domain, UpdateDomainTuningRequest $request): RedirectResponse
    {
        $result = $this->updateDomainThresholds->execute(
            $domain,
            $request->validated(),
            $request->boolean('ad_traffic_strict_mode'),
            (bool) session('is_admin'),
            session('current_tenant_id')
        );

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Domain thresholds updated successfully (caches cleared).' : ($result['error'] ?: 'Failed to update thresholds.')
        );
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
}
