<?php

namespace App\Http\Controllers;

use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IpFarmController extends Controller
{
    public function __construct(
        private readonly EdgeShieldService $service,
        private readonly PlanLimitsService $planLimits
    ) {}

    /**
     * Display the IP Farm — Permanent Ban Graveyard page.
     * Shows all [IP-FARM] rules from Global Firewall with timeline view.
     */
    public function index(): View
    {
        $loadErrors = [];
        $tenantId = $this->tenantId();

        // Fetch IP Farm rules
        $farmResult = $this->service->listIpFarmRules($tenantId);
        if (! $farmResult['ok']) {
            $loadErrors[] = $farmResult['error'] ?? 'Failed to load IP Farm rules.';
        }
        $farmRules = $farmResult['rules'] ?? [];

        // Parse each rule to extract IPs and metadata
        $parsedRules = [];
        $totalIps = 0;
        foreach ($farmRules as $rule) {
            $expr = json_decode($rule['expression_json'] ?? '{}', true);
            $ips = [];
            if (isset($expr['value']) && is_string($expr['value'])) {
                $ips = array_values(array_filter(array_map('trim', explode(',', $expr['value']))));
            }
            $totalIps += count($ips);

            $parsedRules[] = [
                'id' => $rule['id'] ?? null,
                'domain_name' => $rule['domain_name'] ?? 'global',
                'tenant_id' => $rule['tenant_id'] ?? null,
                'scope' => $rule['scope'] ?? (((string) ($rule['domain_name'] ?? '')) === 'global' ? 'tenant' : 'domain'),
                'description' => $rule['description'] ?? '',
                'action' => $rule['action'] ?? 'block',
                'paused' => (bool) ($rule['paused'] ?? false),
                'ip_count' => count($ips),
                'ips' => $ips,
                'ips_text' => implode("\n", $ips),
                'created_at' => $rule['created_at'] ?? null,
                'updated_at' => $rule['updated_at'] ?? null,
            ];
        }

        $domainsRes = $this->service->listDomains($tenantId, false);
        $domains = ($domainsRes['ok'] ?? false) ? ($domainsRes['domains'] ?? []) : [];
        if (! ($domainsRes['ok'] ?? false)) {
            $loadErrors[] = $domainsRes['error'] ?? 'Failed to load domains.';
        }
        $usage = $this->planLimits->getFirewallRulesUsage($tenantId, false);
        $stats = $this->service->getIpFarmStats($tenantId);

        return view('ip_farm', [
            'title' => 'IP Farm — Permanent Ban Graveyard',
            'loadErrors' => $loadErrors,
            'farmRules' => $parsedRules,
            'totalIps' => $totalIps,
            'totalRules' => count($parsedRules),
            'lastUpdated' => $stats['lastUpdated'] ?? null,
            'domains' => $domains,
            'firewallUsage' => $usage,
            'canAddFarmRule' => (bool) ($usage['can_add'] ?? false),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantId = $this->tenantId();
        $usage = $this->planLimits->getFirewallRulesUsage($tenantId, false);
        if (! ($usage['can_add'] ?? false)) {
            return back()->with('error', (string) ($usage['message'] ?? 'IP Farm rule limit reached for this plan.'));
        }

        $validated = $this->validateFarmPayload($request);
        [$scope, $domainName] = $this->resolveScopeAndDomain($validated);
        $result = $this->service->createIpFarmRule(
            $domainName,
            (string) ($validated['description'] ?? ''),
            $this->splitTargets((string) $validated['ips']),
            ((int) ($validated['paused'] ?? 0)) === 1,
            $tenantId,
            $scope
        );
        $this->purgeScope($scope, $domainName);

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok']
                ? 'IP Farm saved with '.(int) ($result['added'] ?? 0).' target(s).'
                : ($result['error'] ?? 'Failed to create IP Farm.')
        );
    }

    public function append(Request $request, int $ruleId): RedirectResponse
    {
        $tenantId = $this->tenantId();
        $usage = $this->planLimits->getFirewallRulesUsage($tenantId, false);
        if (! ($usage['can_add'] ?? false)) {
            return back()->with('error', (string) ($usage['message'] ?? 'IP Farm rule limit reached for this plan.'));
        }

        $validated = $request->validate(['ips' => ['required', 'string', 'max:20000']]);
        $result = $this->service->appendIpsToFarmRule($ruleId, $this->splitTargets((string) $validated['ips']), $tenantId);
        $this->purgeAllTenantDomains();

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Added '.(int) ($result['added'] ?? 0).' target(s) to IP Farm.' : ($result['error'] ?? 'Failed to update IP Farm.')
        );
    }

    public function update(Request $request, int $ruleId): RedirectResponse
    {
        $validated = $this->validateFarmPayload($request);
        [$scope, $domainName] = $this->resolveScopeAndDomain($validated);
        $result = $this->service->updateIpFarmRule(
            $ruleId,
            $domainName,
            (string) ($validated['description'] ?? ''),
            $this->splitTargets((string) $validated['ips']),
            ((int) ($validated['paused'] ?? 0)) === 1,
            $this->tenantId(),
            $scope
        );
        $this->purgeScope($scope, $domainName);

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'IP Farm rule updated.' : ($result['error'] ?? 'Failed to update IP Farm.'));
    }

    public function toggle(Request $request, int $ruleId): RedirectResponse
    {
        $validated = $request->validate(['paused' => ['required', 'in:0,1']]);
        $result = $this->service->toggleIpFarmRule($ruleId, ((int) $validated['paused']) === 1, $this->tenantId());
        $this->purgeAllTenantDomains();

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'IP Farm status updated.' : ($result['error'] ?? 'Failed to update IP Farm.'));
    }

    public function removeIps(Request $request, int $ruleId): RedirectResponse
    {
        $validated = $request->validate(['ips' => ['required', 'string', 'max:20000']]);
        $result = $this->service->removeIpsFromFarmRule($ruleId, $this->splitTargets((string) $validated['ips']), $this->tenantId());
        $this->purgeAllTenantDomains();

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Removed '.(int) ($result['removed'] ?? 0).' target(s) from IP Farm.' : ($result['error'] ?? 'Failed to update IP Farm.')
        );
    }

    public function destroy(int $ruleId): RedirectResponse
    {
        $result = $this->service->deleteIpFarmRule($ruleId, $this->tenantId());
        $this->purgeAllTenantDomains();

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'IP Farm rule deleted.' : ($result['error'] ?? 'Failed to delete IP Farm.'));
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rule_ids' => ['required', 'array'],
            'rule_ids.*' => ['integer'],
        ]);
        $result = $this->service->deleteBulkIpFarmRules($validated['rule_ids'], $this->tenantId());
        $this->purgeAllTenantDomains();

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Selected IP Farm rules deleted.' : ($result['error'] ?? 'Failed to delete IP Farm rules.'));
    }

    private function validateFarmPayload(Request $request): array
    {
        return $request->validate([
            'scope' => ['nullable', 'in:tenant,domain'],
            'domain_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'ips' => ['required', 'string', 'max:50000'],
            'paused' => ['nullable', 'in:0,1'],
        ]);
    }

    private function resolveScopeAndDomain(array $validated): array
    {
        $domainName = strtolower(trim((string) ($validated['domain_name'] ?? '')));
        $scope = (string) ($validated['scope'] ?? ($domainName === 'global' ? 'tenant' : 'domain'));
        if ($scope === 'tenant' || $domainName === 'global') {
            return ['tenant', 'global'];
        }

        abort_if($domainName === '', 422, 'Domain is required for domain-scoped IP Farm rules.');

        $domain = TenantDomain::query()
            ->where('tenant_id', $this->tenantId())
            ->where('hostname', $domainName)
            ->firstOrFail();

        return ['domain', (string) $domain->hostname];
    }

    private function splitTargets(string $input): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $input) ?: [])));
    }

    private function tenantId(): string
    {
        return trim((string) session('current_tenant_id', ''));
    }

    private function tenant(): ?Tenant
    {
        $tenantId = $this->tenantId();

        return $tenantId !== '' ? Tenant::query()->find($tenantId) : null;
    }

    private function purgeScope(string $scope, string $domainName): void
    {
        if ($scope === 'tenant' || strtolower(trim($domainName)) === 'global') {
            $this->purgeAllTenantDomains();

            return;
        }

        PurgeRuntimeBundleCache::dispatch($domainName);
    }

    private function purgeAllTenantDomains(): void
    {
        $tenant = $this->tenant();
        if (! $tenant) {
            return;
        }

        foreach ($tenant->domains()->pluck('hostname') as $hostname) {
            PurgeRuntimeBundleCache::dispatch((string) $hostname);
        }
    }
}
