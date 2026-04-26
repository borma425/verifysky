<?php

namespace App\Http\Controllers;

use App\Actions\Firewall\CreateFirewallRuleAction;
use App\Actions\Firewall\DeleteBulkFirewallRulesAction;
use App\Actions\Firewall\DeleteFirewallRuleAction;
use App\Actions\Firewall\ToggleFirewallRuleAction;
use App\Actions\Firewall\UpdateFirewallRuleAction;
use App\Http\Requests\Firewall\BulkDestroyFirewallRulesRequest;
use App\Http\Requests\Firewall\StoreFirewallRuleRequest;
use App\Http\Requests\Firewall\ToggleFirewallRuleRequest;
use App\Http\Requests\Firewall\UpdateFirewallRuleRequest;
use App\Jobs\PurgeRuntimeBundleCache;
use App\Models\Tenant;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use App\ViewData\FirewallIndexViewData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FirewallRulesController extends Controller
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly CreateFirewallRuleAction $createFirewallRule,
        private readonly UpdateFirewallRuleAction $updateFirewallRule,
        private readonly ToggleFirewallRuleAction $toggleFirewallRule,
        private readonly DeleteFirewallRuleAction $deleteFirewallRule,
        private readonly DeleteBulkFirewallRulesAction $deleteBulkFirewallRules,
        private readonly PlanLimitsService $planLimits
    ) {}

    public function index(Request $request): View
    {
        $tenantId = (string) session('current_tenant_id', '');
        $isAdmin = (bool) session('is_admin');
        $domainsRes = $this->edgeShield->listDomains($tenantId, $isAdmin);
        $domains = $domainsRes['ok'] ? ($domainsRes['domains'] ?? []) : [];

        $page = max(1, (int) $request->get('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        if ($isAdmin) {
            $rulesRes = $this->edgeShield->listPaginatedCustomFirewallRules($perPage, $offset);
            $rules = $rulesRes['ok'] ? ($rulesRes['rules'] ?? []) : [];
            $totalRules = $rulesRes['ok'] ? ($rulesRes['total'] ?? 0) : 0;
        } else {
            $allRulesRes = $this->edgeShield->listTenantCustomFirewallRules($tenantId);
            $filteredRules = $allRulesRes['ok'] ? ($allRulesRes['rules'] ?? []) : [];
            $rulesRes = [
                'ok' => (bool) ($allRulesRes['ok'] ?? false),
                'error' => $allRulesRes['error'] ?? null,
            ];
            $totalRules = count($filteredRules);
            $rules = array_slice($filteredRules, $offset, $perPage);
        }
        $totalPages = max(1, (int) ceil(max(1, $totalRules) / $perPage));

        $loadErrors = [];
        if (! $domainsRes['ok']) {
            $loadErrors[] = 'Failed to load domains: '.($domainsRes['error'] ?? 'Unknown error');
        }
        if (! $rulesRes['ok']) {
            $loadErrors[] = 'Failed to load firewall rules: '.($rulesRes['error'] ?? 'Unknown error');
        }

        $viewData = new FirewallIndexViewData(
            $domains,
            $rules,
            $loadErrors,
            $page,
            $totalPages,
            (int) $totalRules,
            $this->planLimits->getFirewallRulesUsage($tenantId !== '' ? $tenantId : null, $isAdmin),
            true,
            $isAdmin
        );

        return view('firewall', $viewData->toArray());
    }

    public function store(StoreFirewallRuleRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $tenantId = trim((string) session('current_tenant_id', ''));
        if ($tenantId !== '' && ! (bool) session('is_admin')) {
            $validated['tenant_id'] = $tenantId;
            $validated['scope'] = strtolower((string) ($validated['domain_name'] ?? '')) === 'global' ? 'tenant' : 'domain';
        }
        $create = $this->createFirewallRule->execute($validated);
        $this->purgeTenantGlobalScope((string) ($validated['domain_name'] ?? ''));

        return back()->with(
            $create['ok'] ? 'status' : 'error',
            $create['ok']
                ? 'Firewall rule created successfully.'.($create['message'] ?? '')
                : ($create['error'] ?? 'Failed to create firewall rule.')
        );
    }

    public function toggle(string $domain, int $ruleId, ToggleFirewallRuleRequest $request): RedirectResponse
    {
        $isPausing = ((int) $request->validated()['paused']) === 1;
        $toggle = $this->toggleFirewallRule->execute($domain, $ruleId, $isPausing);
        $this->purgeTenantGlobalScope($domain);

        return back()->with(
            $toggle['ok'] ? 'status' : 'error',
            $toggle['ok'] ? 'Firewall rule status updated.' : ($toggle['error'] ?? 'Failed to update firewall rule.')
        );
    }

    public function destroy(string $domain, string $ruleId): RedirectResponse
    {
        $tenantId = trim((string) session('current_tenant_id', ''));
        $isAdmin = (bool) session('is_admin');
        $isGlobal = strtolower(trim($domain)) === 'global';
        $allowedDomain = $isGlobal && ! $isAdmin
            ? $tenantId !== ''
            : $this->planLimits->domainBelongsToTenant($domain, $tenantId !== '' ? $tenantId : null, $isAdmin);
        $allowedRule = $this->planLimits->canManageRuleIds([(int) $ruleId], $tenantId !== '' ? $tenantId : null, $isAdmin);
        if (! $allowedDomain || ! $allowedRule) {
            abort(403, 'You do not have access to delete firewall rules for this domain.');
        }

        $delete = $this->deleteFirewallRule->execute($domain, (int) $ruleId);
        $this->purgeTenantGlobalScope($domain);

        return back()->with(
            $delete['ok'] ? 'status' : 'error',
            $delete['ok'] ? 'Firewall rule deleted.' : ($delete['error'] ?? 'Failed to delete firewall rule.')
        );
    }

    public function bulkDestroy(BulkDestroyFirewallRulesRequest $request): RedirectResponse
    {
        $delete = $this->deleteBulkFirewallRules->execute($request->validated()['rule_ids']);

        return back()->with(
            $delete['ok'] ? 'status' : 'error',
            $delete['ok'] ? 'Selected rules deleted successfully.' : ($delete['error'] ?? 'Failed to delete bulk rules.')
        );
    }

    public function edit(string $domain, int $ruleId): View|RedirectResponse
    {
        $tenantId = trim((string) session('current_tenant_id', ''));
        $isAdmin = (bool) session('is_admin');
        $isGlobal = strtolower(trim($domain)) === 'global';
        $allowedDomain = $isGlobal && ! $isAdmin
            ? $tenantId !== ''
            : $this->planLimits->domainBelongsToTenant($domain, $tenantId !== '' ? $tenantId : null, $isAdmin);
        $allowedRule = $this->planLimits->canManageRuleIds([$ruleId], $tenantId !== '' ? $tenantId : null, $isAdmin);
        if (! $allowedDomain || ! $allowedRule) {
            abort(403, 'You do not have access to edit firewall rules for this domain.');
        }

        $domainsRes = $this->edgeShield->listDomains($tenantId, $isAdmin);
        $domains = $domainsRes['ok'] ? ($domainsRes['domains'] ?? []) : [];
        $ruleRes = $this->edgeShield->getCustomFirewallRuleById($domain, $ruleId);
        if (! $ruleRes['ok'] || ! $ruleRes['rule']) {
            return redirect()->route('firewall.index')->with('error', $ruleRes['error'] ?? 'Rule not found.');
        }

        return view('firewall_edit', ['domains' => $domains, 'rule' => $ruleRes['rule']]);
    }

    public function update(string $domain, int $ruleId, UpdateFirewallRuleRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $tenantId = trim((string) session('current_tenant_id', ''));
        if ($tenantId !== '' && ! (bool) session('is_admin')) {
            $validated['tenant_id'] = $tenantId;
            $validated['scope'] = strtolower(trim($domain)) === 'global' ? 'tenant' : 'domain';
        }

        $update = $this->updateFirewallRule->execute($domain, $ruleId, $validated);
        $this->purgeTenantGlobalScope($domain);

        return redirect()->route('firewall.index')->with(
            $update['ok'] ? 'status' : 'error',
            $update['ok'] ? 'Firewall rule updated successfully.' : ($update['error'] ?? 'Failed to update firewall rule.')
        );
    }

    private function purgeTenantGlobalScope(string $domain): void
    {
        if ((bool) session('is_admin') || strtolower(trim($domain)) !== 'global') {
            return;
        }

        $tenantId = trim((string) session('current_tenant_id', ''));
        if ($tenantId === '') {
            return;
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant instanceof Tenant) {
            return;
        }

        foreach ($tenant->domains()->pluck('hostname') as $hostname) {
            PurgeRuntimeBundleCache::dispatch((string) $hostname);
        }
    }
}
