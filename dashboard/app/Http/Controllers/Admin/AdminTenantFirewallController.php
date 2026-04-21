<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Firewall\CreateFirewallRuleAction;
use App\Actions\Firewall\DeleteFirewallRuleAction;
use App\Actions\Firewall\ToggleFirewallRuleAction;
use App\Actions\Firewall\UpdateFirewallRuleAction;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminTenantFirewallController extends Controller
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly CreateFirewallRuleAction $createFirewallRule,
        private readonly UpdateFirewallRuleAction $updateFirewallRule,
        private readonly ToggleFirewallRuleAction $toggleFirewallRule,
        private readonly DeleteFirewallRuleAction $deleteFirewallRule,
        private readonly PlanLimitsService $planLimits
    ) {}

    public function index(Tenant $tenant, string $domain): View
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);

        return view('admin.tenants.domains.firewall', [
            'tenant' => $tenant,
            'domainRecord' => $domainRecord,
            'rules' => $this->firewallRulesFor((string) $domainRecord->hostname),
            'usage' => $this->planLimits->getFirewallRulesUsage((string) $tenant->getKey(), false),
        ]);
    }

    public function store(Request $request, Tenant $tenant, string $domain): RedirectResponse
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);
        $usage = $this->planLimits->getFirewallRulesUsage((string) $tenant->getKey(), false);
        if (! ($usage['can_add'] ?? false)) {
            return back()->with('error', (string) ($usage['message'] ?? 'Firewall rule limit reached for this tenant.'));
        }

        $validated = array_merge($request->validate($this->firewallRuleRules(true)), [
            'domain_name' => (string) $domainRecord->hostname,
        ]);
        $result = $this->createFirewallRule->execute($validated);

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Firewall rule created and runtime cache purged.' : ($result['error'] ?? 'Failed to create firewall rule.'));
    }

    public function update(Request $request, Tenant $tenant, string $domain, int $ruleId): RedirectResponse
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);
        $validated = $request->validate($this->firewallRuleRules(false));
        $result = $this->updateFirewallRule->execute((string) $domainRecord->hostname, $ruleId, $validated);

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Firewall rule updated and runtime cache purged.' : ($result['error'] ?? 'Failed to update firewall rule.'));
    }

    public function toggle(Request $request, Tenant $tenant, string $domain, int $ruleId): RedirectResponse
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);
        $validated = $request->validate(['paused' => ['required', 'in:0,1']]);
        $result = $this->toggleFirewallRule->execute(
            (string) $domainRecord->hostname,
            $ruleId,
            ((int) $validated['paused']) === 1
        );

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Firewall rule status updated and runtime cache purged.' : ($result['error'] ?? 'Failed to toggle firewall rule.'));
    }

    public function destroy(Tenant $tenant, string $domain, int $ruleId): RedirectResponse
    {
        $domainRecord = $this->domainForTenant($tenant, $domain);
        $result = $this->deleteFirewallRule->execute((string) $domainRecord->hostname, $ruleId);

        return back()->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Firewall rule deleted and runtime cache purged.' : ($result['error'] ?? 'Failed to delete firewall rule.'));
    }

    private function domainForTenant(Tenant $tenant, string $domain): TenantDomain
    {
        return TenantDomain::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('hostname', strtolower(trim($domain)))
            ->firstOrFail();
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

    private function firewallRuleRules(bool $creating): array
    {
        return [
            'description' => ['nullable', 'string', 'max:255'],
            'action' => ['required', 'in:block,challenge,managed_challenge,js_challenge,allow,block_ip_farm'],
            'field' => ['required', 'string', 'in:ip.src,ip.src.country,ip.src.asnum,http.request.uri.path,http.request.method,http.user_agent'],
            'operator' => ['required', 'string', 'in:eq,ne,in,not_in,contains,not_contains,starts_with'],
            'value' => ['required', 'string', 'max:3000'],
            'duration' => ['nullable', 'string', 'in:forever,1h,6h,24h,7d,30d'],
            'paused' => ['nullable', 'in:0,1'],
            'preserve_expiry' => $creating ? ['exclude'] : ['nullable', 'in:1'],
        ];
    }
}
