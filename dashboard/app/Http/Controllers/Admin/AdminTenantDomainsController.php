<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Domains\RefreshDomainVerificationAction;
use App\Actions\Domains\ToggleDomainForceCaptchaAction;
use App\Actions\Domains\UpdateDomainOriginAction;
use App\Actions\Domains\UpdateDomainSecurityModeAction;
use App\Actions\Domains\UpdateDomainThresholdsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Domains\ToggleDomainForceCaptchaRequest;
use App\Http\Requests\Domains\UpdateDomainOriginRequest;
use App\Http\Requests\Domains\UpdateDomainSecurityModeRequest;
use App\Http\Requests\Domains\UpdateDomainTuningRequest;
use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use App\ViewData\DomainTuningViewData;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AdminTenantDomainsController extends Controller
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly UpdateDomainOriginAction $updateDomainOrigin,
        private readonly UpdateDomainSecurityModeAction $updateDomainSecurityMode,
        private readonly ToggleDomainForceCaptchaAction $toggleDomainForceCaptcha,
        private readonly UpdateDomainThresholdsAction $updateDomainThresholds,
        private readonly RefreshDomainVerificationAction $refreshDomainVerification
    ) {}

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

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Origin updated and runtime cache purged.' : ($result['error'] ?? 'Failed to update origin.'));
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
        $result = $this->refreshDomainVerification->execute((string) $domainRecord->hostname);

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Route and hostname status refreshed.' : ($result['error'] ?? 'Failed to refresh route status.'));
    }

    public function purgeRuntimeCache(Tenant $tenant, string $domain): RedirectResponse
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);
        PurgeRuntimeBundleCache::dispatch((string) $domainRecord->hostname);

        return back()->with('status', 'Runtime bundle cache purge queued for '.$domainRecord->hostname.'.');
    }

    private function domainForTenant(Tenant $tenant, string $domain): TenantDomain
    {
        return TenantDomain::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('hostname', strtolower(trim($domain)))
            ->firstOrFail();
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
}
