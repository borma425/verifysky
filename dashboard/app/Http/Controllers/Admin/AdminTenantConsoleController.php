<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Firewall\CreateFirewallRuleAction;
use App\Actions\Firewall\DeleteFirewallRuleAction;
use App\Actions\Firewall\ToggleFirewallRuleAction;
use App\Actions\Firewall\UpdateFirewallRuleAction;
use App\Http\Controllers\Controller;
use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Domains\DomainAssetPolicyService;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use App\ViewData\FirewallIndexViewData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminTenantConsoleController extends Controller
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly CreateFirewallRuleAction $createFirewallRule,
        private readonly UpdateFirewallRuleAction $updateFirewallRule,
        private readonly ToggleFirewallRuleAction $toggleFirewallRule,
        private readonly DeleteFirewallRuleAction $deleteFirewallRule,
        private readonly PlanLimitsService $planLimits,
        private readonly DomainAssetPolicyService $domainAssets
    ) {}

    public function firewall(Request $request, Tenant $tenant, ?string $domain = null): View
    {
        $selectedDomain = $this->selectedDomain($tenant, $domain ?: (string) $request->query('domain', ''));
        $rulesRes = $this->edgeShield->listTenantCustomFirewallRules((string) $tenant->getKey());
        $rules = ($rulesRes['ok'] ?? false) ? ($rulesRes['rules'] ?? []) : [];
        if ($selectedDomain !== '') {
            $rules = array_values(array_filter($rules, function (array $rule) use ($selectedDomain): bool {
                $scope = $this->ruleScope($rule);

                return $scope === 'tenant'
                    || strtolower((string) ($rule['domain_name'] ?? '')) === strtolower($selectedDomain);
            }));
        }

        $viewData = new FirewallIndexViewData(
            $this->domainOptions($tenant),
            $rules,
            ($rulesRes['ok'] ?? false) ? [] : [($rulesRes['error'] ?? 'Failed to load firewall rules.')],
            1,
            1,
            count($rules),
            $this->planLimits->getFirewallRulesUsage((string) $tenant->getKey(), false),
            true,
            false
        );

        return view('admin.tenants.console.firewall', array_merge($viewData->toArray(), [
            'tenant' => $tenant,
            'selectedDomain' => $selectedDomain,
            'domainRecords' => $tenant->domains()->orderBy('hostname')->get(),
        ]));
    }

    public function storeFirewall(Request $request, Tenant $tenant, ?string $domain = null): RedirectResponse
    {
        $validated = $this->validateFirewallRule($request, true);
        $scope = (string) $validated['scope'];
        $domainName = $scope === 'tenant'
            ? 'global'
            : $this->domainForTenant($tenant, (string) (($validated['domain_name'] ?? '') ?: $domain))->hostname;
        $this->planLimits->getFirewallRulesUsage((string) $tenant->getKey(), false);

        $result = $this->createFirewallRule->execute(array_merge($validated, [
            'domain_name' => (string) $domainName,
            'tenant_id' => (string) $tenant->getKey(),
            'scope' => $scope,
        ]));
        $this->purgeTenantScope($tenant, $scope, (string) $domainName);

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Firewall rule created for '.$this->scopeLabel($scope, (string) $domainName).'.'.($result['message'] ?? '') : ($result['error'] ?? 'Failed to create firewall rule.')
        );
    }

    public function updateFirewall(Request $request, Tenant $tenant, string $domain, int $ruleId): RedirectResponse
    {
        $rule = $this->tenantRuleOrFail($tenant, $ruleId);
        $validated = $this->validateFirewallRule($request, false);
        $scope = $this->ruleScope($rule);
        $domainName = (string) ($rule['domain_name'] ?? $domain);

        $result = $this->updateFirewallRule->execute($domainName, $ruleId, array_merge($validated, [
            'tenant_id' => (string) $tenant->getKey(),
            'scope' => $scope,
        ]));
        $this->purgeTenantScope($tenant, $scope, (string) $domainName);

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Firewall rule updated.' : ($result['error'] ?? 'Failed to update firewall rule.'));
    }

    public function toggleFirewall(Request $request, Tenant $tenant, string $domain, int $ruleId): RedirectResponse
    {
        $rule = $this->tenantRuleOrFail($tenant, $ruleId);
        $validated = $request->validate(['paused' => ['required', 'in:0,1']]);
        $result = $this->toggleFirewallRule->execute(
            (string) ($rule['domain_name'] ?? $domain),
            $ruleId,
            ((int) $validated['paused']) === 1
        );
        $this->purgeTenantScope($tenant, $this->ruleScope($rule), (string) ($rule['domain_name'] ?? $domain));

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Firewall rule status updated.' : ($result['error'] ?? 'Failed to toggle firewall rule.'));
    }

    public function destroyFirewall(Tenant $tenant, string $domain, int $ruleId): RedirectResponse
    {
        $rule = $this->tenantRuleOrFail($tenant, $ruleId);
        $result = $this->deleteFirewallRule->execute((string) ($rule['domain_name'] ?? $domain), $ruleId);
        $this->purgeTenantScope($tenant, $this->ruleScope($rule), (string) ($rule['domain_name'] ?? $domain));

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Firewall rule deleted.' : ($result['error'] ?? 'Failed to delete firewall rule.'));
    }

    public function sensitivePaths(Tenant $tenant): View
    {
        $pathsRes = $this->edgeShield->listTenantSensitivePaths((string) $tenant->getKey());
        $paths = ($pathsRes['ok'] ?? false) ? ($pathsRes['paths'] ?? []) : [];

        return view('admin.tenants.console.sensitive-paths', [
            'tenant' => $tenant,
            'domainRecords' => $tenant->domains()->orderBy('hostname')->get(),
            'paths' => $paths,
            'loadErrors' => ($pathsRes['ok'] ?? false) ? [] : [($pathsRes['error'] ?? 'Failed to load protected paths.')],
        ]);
    }

    public function storeSensitivePath(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'in:tenant,domain'],
            'domain_name' => ['nullable', 'string', 'max:255'],
            'path_pattern' => ['required', 'string', 'max:500'],
            'match_type' => ['required', 'in:exact,contains,ends_with'],
            'action' => ['required', 'in:block,challenge'],
        ]);
        $scope = (string) $validated['scope'];
        $domainName = $scope === 'tenant'
            ? 'global'
            : $this->domainForTenant($tenant, (string) $validated['domain_name'])->hostname;

        $result = $this->edgeShield->createSensitivePath(
            (string) $domainName,
            (string) $validated['path_pattern'],
            (string) $validated['match_type'],
            (string) $validated['action'],
            false,
            (string) $tenant->getKey(),
            $scope
        );
        $this->purgeTenantScope($tenant, $scope, (string) $domainName);

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Protected path saved.' : ($result['error'] ?? 'Failed to save protected path.'));
    }

    public function destroySensitivePath(Tenant $tenant, int $pathId): RedirectResponse
    {
        $path = $this->tenantSensitivePathOrFail($tenant, $pathId);
        $result = $this->edgeShield->deleteSensitivePath($pathId);
        $this->purgeTenantScope($tenant, $this->ruleScope($path), (string) ($path['domain_name'] ?? 'global'));

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Protected path deleted.' : ($result['error'] ?? 'Failed to delete protected path.'));
    }

    public function ipFarm(Tenant $tenant): View
    {
        $farmResult = $this->edgeShield->listIpFarmRules((string) $tenant->getKey());
        $farmRules = ($farmResult['ok'] ?? false) ? ($farmResult['rules'] ?? []) : [];

        return view('admin.tenants.console.ip-farm', [
            'tenant' => $tenant,
            'domainRecords' => $tenant->domains()->orderBy('hostname')->get(),
            'farmRules' => $this->parseFarmRules($farmRules),
            'stats' => $this->edgeShield->getIpFarmStats((string) $tenant->getKey()),
            'loadErrors' => ($farmResult['ok'] ?? false) ? [] : [($farmResult['error'] ?? 'We could not load blocked IP rules.')],
        ]);
    }

    public function storeIpFarm(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $this->validateIpFarmPayload($request);
        [$scope, $domainName] = $this->resolveTenantScopeAndDomain($tenant, $validated);
        $result = $this->edgeShield->createIpFarmRule(
            $domainName,
            (string) ($validated['description'] ?? ''),
            $this->splitIpFarmTargets((string) $validated['ips']),
            ((int) ($validated['paused'] ?? 0)) === 1,
            (string) $tenant->getKey(),
            $scope
        );
        $this->purgeTenantScope($tenant, $scope, $domainName);

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Blocked IP list saved with '.(int) ($result['added'] ?? 0).' IP(s).' : ($result['error'] ?? 'We could not create the blocked IP list.')
        );
    }

    public function appendIpFarm(Request $request, Tenant $tenant, int $ruleId): RedirectResponse
    {
        $validated = $request->validate(['ips' => ['required', 'string', 'max:20000']]);
        $result = $this->edgeShield->appendIpsToFarmRule($ruleId, $this->splitIpFarmTargets((string) $validated['ips']), (string) $tenant->getKey());
        $this->purgeTenantScope($tenant, 'tenant', 'global');

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Added '.(int) ($result['added'] ?? 0).' IP(s) to the blocked list.' : ($result['error'] ?? 'We could not update the blocked list.')
        );
    }

    public function updateIpFarm(Request $request, Tenant $tenant, int $ruleId): RedirectResponse
    {
        $validated = $this->validateIpFarmPayload($request);
        [$scope, $domainName] = $this->resolveTenantScopeAndDomain($tenant, $validated);
        $result = $this->edgeShield->updateIpFarmRule(
            $ruleId,
            $domainName,
            (string) ($validated['description'] ?? ''),
            $this->splitIpFarmTargets((string) $validated['ips']),
            ((int) ($validated['paused'] ?? 0)) === 1,
            (string) $tenant->getKey(),
            $scope
        );
        $this->purgeTenantScope($tenant, $scope, $domainName);

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Blocked IP rule updated.' : ($result['error'] ?? 'We could not update the blocked list.'));
    }

    public function toggleIpFarm(Request $request, Tenant $tenant, int $ruleId): RedirectResponse
    {
        $validated = $request->validate(['paused' => ['required', 'in:0,1']]);
        $result = $this->edgeShield->toggleIpFarmRule($ruleId, ((int) $validated['paused']) === 1, (string) $tenant->getKey());
        $this->purgeTenantScope($tenant, 'tenant', 'global');

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Blocked IP rule status updated.' : ($result['error'] ?? 'We could not update the blocked list.'));
    }

    public function removeIpFarmIps(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'ips' => ['required', 'string', 'max:10000'],
        ]);
        $ips = $this->splitIpFarmTargets((string) $validated['ips']);
        $result = $this->edgeShield->removeIpsFromFarm($ips, (string) $tenant->getKey());
        $this->purgeTenantScope($tenant, 'tenant', 'global');

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Removed '.(int) ($result['removed'] ?? 0).' IP(s) from this user blocked list.' : ($result['error'] ?? 'We could not update the blocked list.'));
    }

    public function removeIpFarmIpsFromRule(Request $request, Tenant $tenant, int $ruleId): RedirectResponse
    {
        $validated = $request->validate(['ips' => ['required', 'string', 'max:20000']]);
        $result = $this->edgeShield->removeIpsFromFarmRule($ruleId, $this->splitIpFarmTargets((string) $validated['ips']), (string) $tenant->getKey());
        $this->purgeTenantScope($tenant, 'tenant', 'global');

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Removed '.(int) ($result['removed'] ?? 0).' IP(s) from the blocked list.' : ($result['error'] ?? 'We could not update the blocked list.')
        );
    }

    public function destroyIpFarm(Tenant $tenant, int $ruleId): RedirectResponse
    {
        $result = $this->edgeShield->deleteIpFarmRule($ruleId, (string) $tenant->getKey());
        $this->purgeTenantScope($tenant, 'tenant', 'global');

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Blocked IP rule deleted.' : ($result['error'] ?? 'We could not delete the blocked IP rule.'));
    }

    public function bulkDestroyIpFarm(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'rule_ids' => ['required', 'array'],
            'rule_ids.*' => ['integer'],
        ]);
        $result = $this->edgeShield->deleteBulkIpFarmRules($validated['rule_ids'], (string) $tenant->getKey());
        $this->purgeTenantScope($tenant, 'tenant', 'global');

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Selected blocked IP rules deleted.' : ($result['error'] ?? 'We could not delete blocked IP rules.'));
    }

    public function suspend(Tenant $tenant): RedirectResponse
    {
        $tenant->forceFill(['status' => 'suspended'])->save();
        $this->edgeShield->queryD1(sprintf(
            "UPDATE domain_configs SET status = 'paused', updated_at = CURRENT_TIMESTAMP WHERE tenant_id = '%s'",
            str_replace("'", "''", (string) $tenant->getKey())
        ));
        $this->purgeTenantScope($tenant, 'tenant', 'global');

        return back()->with('status', 'User account suspended.');
    }

    public function resume(Tenant $tenant): RedirectResponse
    {
        $tenant->forceFill(['status' => 'active'])->save();
        $this->edgeShield->queryD1(sprintf(
            "UPDATE domain_configs SET status = 'active', updated_at = CURRENT_TIMESTAMP WHERE tenant_id = '%s'",
            str_replace("'", "''", (string) $tenant->getKey())
        ));
        $this->purgeTenantScope($tenant, 'tenant', 'global');

        return back()->with('status', 'User account resumed.');
    }

    public function delete(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'confirm_tenant' => ['required', 'string'],
        ]);
        if ((string) $validated['confirm_tenant'] !== (string) $tenant->slug) {
            return back()->with('error', 'Type the user slug exactly before deleting the account.');
        }

        $tenantId = (string) $tenant->getKey();
        DB::transaction(function () use ($tenant, $tenantId): void {
            $this->domainAssets->quarantineRemovedHostnames(
                $tenant->domains()->pluck('hostname')->all(),
                $tenantId,
                'tenant_deleted'
            );
            $this->edgeShield->queryD1("DELETE FROM custom_firewall_rules WHERE tenant_id = '".str_replace("'", "''", $tenantId)."'");
            $this->edgeShield->queryD1("DELETE FROM sensitive_paths WHERE tenant_id = '".str_replace("'", "''", $tenantId)."'");
            $this->edgeShield->queryD1("DELETE FROM domain_configs WHERE tenant_id = '".str_replace("'", "''", $tenantId)."'");
            $tenant->domains()->delete();
            $tenant->memberships()->delete();
            $tenant->planGrants()->delete();
            $tenant->subscriptions()->delete();
            $tenant->usageCycles()->delete();
            $tenant->delete();
        });

        return redirect()->route('admin.tenants.index')->with('status', 'User account deleted.');
    }

    private function validateFirewallRule(Request $request, bool $creating): array
    {
        if (! $request->has('scope')) {
            $request->merge(['scope' => 'domain']);
        }

        return $request->validate([
            'scope' => ['required', 'in:tenant,domain'],
            'domain_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'action' => ['required', 'in:block,challenge,managed_challenge,js_challenge,allow,block_ip_farm'],
            'field' => ['required', 'string', 'in:ip.src,ip.src.country,ip.src.asnum,http.request.uri.path,http.request.method,http.user_agent'],
            'operator' => ['required', 'string', 'in:eq,ne,in,not_in,contains,not_contains,starts_with'],
            'value' => ['required', 'string', 'max:3000'],
            'duration' => ['nullable', 'string', 'in:forever,1h,6h,24h,7d,30d'],
            'paused' => ['nullable', 'in:0,1'],
            'preserve_expiry' => $creating ? ['exclude'] : ['nullable', 'in:1'],
        ]);
    }

    private function validateIpFarmPayload(Request $request): array
    {
        return $request->validate([
            'scope' => ['nullable', 'in:tenant,domain'],
            'domain_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'ips' => ['required', 'string', 'max:50000'],
            'paused' => ['nullable', 'in:0,1'],
        ]);
    }

    private function resolveTenantScopeAndDomain(Tenant $tenant, array $validated): array
    {
        $domainName = strtolower(trim((string) ($validated['domain_name'] ?? '')));
        $scope = (string) ($validated['scope'] ?? ($domainName === 'global' ? 'tenant' : 'domain'));
        if ($scope === 'tenant' || $domainName === 'global') {
            return ['tenant', 'global'];
        }

        return ['domain', (string) $this->domainForTenant($tenant, $domainName)->hostname];
    }

    private function splitIpFarmTargets(string $targets): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $targets) ?: [])));
    }

    private function tenantRuleOrFail(Tenant $tenant, int $ruleId): array
    {
        $result = $this->edgeShield->getCustomFirewallRuleByIdGlobal($ruleId);
        abort_unless(($result['ok'] ?? false) && is_array($result['rule'] ?? null), 404);
        $rule = $result['rule'];
        abort_unless((string) ($rule['tenant_id'] ?? '') === (string) $tenant->getKey(), 404);

        return $rule;
    }

    private function tenantSensitivePathOrFail(Tenant $tenant, int $pathId): array
    {
        $paths = $this->edgeShield->listTenantSensitivePaths((string) $tenant->getKey())['paths'] ?? [];
        foreach ($paths as $path) {
            if ((int) ($path['id'] ?? 0) === $pathId) {
                return $path;
            }
        }

        abort(404);
    }

    private function selectedDomain(Tenant $tenant, string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }

        return (string) $this->domainForTenant($tenant, $domain)->hostname;
    }

    private function domainForTenant(Tenant $tenant, string $domain): TenantDomain
    {
        return TenantDomain::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('hostname', strtolower(trim($domain)))
            ->firstOrFail();
    }

    private function domainOptions(Tenant $tenant): array
    {
        return $tenant->domains()
            ->orderBy('hostname')
            ->pluck('hostname')
            ->map(fn (string $hostname): array => ['domain_name' => $hostname])
            ->all();
    }

    private function purgeTenantScope(Tenant $tenant, string $scope, string $domainName): void
    {
        if ($scope === 'tenant' || strtolower(trim($domainName)) === 'global') {
            foreach ($tenant->domains()->pluck('hostname') as $hostname) {
                PurgeRuntimeBundleCache::dispatch((string) $hostname);
            }

            return;
        }

        PurgeRuntimeBundleCache::dispatch($domainName);
    }

    private function ruleScope(array $rule): string
    {
        $scope = strtolower(trim((string) ($rule['scope'] ?? '')));
        if (in_array($scope, ['tenant', 'platform', 'domain'], true)) {
            return $scope;
        }

        return strtolower((string) ($rule['domain_name'] ?? '')) === 'global' ? 'tenant' : 'domain';
    }

    private function scopeLabel(string $scope, string $domainName): string
    {
        return $scope === 'tenant' ? 'all domains' : $domainName;
    }

    /**
     * @param  array<int, array<string, mixed>>  $farmRules
     * @return array<int, array<string, mixed>>
     */
    private function parseFarmRules(array $farmRules): array
    {
        return array_map(function (array $rule): array {
            $expr = json_decode((string) ($rule['expression_json'] ?? '{}'), true) ?: [];
            $ips = array_values(array_filter(array_map('trim', explode(',', (string) ($expr['value'] ?? '')))));

            return [
                'id' => (int) ($rule['id'] ?? 0),
                'domain_name' => (string) ($rule['domain_name'] ?? 'global'),
                'tenant_id' => $rule['tenant_id'] ?? null,
                'scope' => $rule['scope'] ?? (((string) ($rule['domain_name'] ?? '')) === 'global' ? 'tenant' : 'domain'),
                'description' => (string) ($rule['description'] ?? ''),
                'ip_count' => count($ips),
                'ips' => $ips,
                'ips_text' => implode("\n", $ips),
                'paused' => (bool) ($rule['paused'] ?? false),
                'updated_at' => $rule['updated_at'] ?? $rule['created_at'] ?? null,
            ];
        }, $farmRules);
    }
}
