<?php

namespace App\Http\Controllers;

use App\Services\EdgeShieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FirewallRulesController extends Controller
{
    public function __construct(private readonly EdgeShieldService $edgeShield)
    {
    }

    public function index(Request $request): View
    {
        // Ensure table exists
        $this->edgeShield->ensureCustomFirewallRulesTable();

        // Fetch all domains for the dropdown
        $domainsRes = $this->edgeShield->listDomains();
        $domains = $domainsRes['ok'] ? ($domainsRes['domains'] ?? []) : [];

        // Pagination calculations
        $page = max(1, (int) $request->get('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        // Fetch paginated firewall rules globally
        $rulesRes = $this->edgeShield->listPaginatedCustomFirewallRules($perPage, $offset);
        $rules = $rulesRes['ok'] ? ($rulesRes['rules'] ?? []) : [];
        $totalRules = $rulesRes['ok'] ? ($rulesRes['total'] ?? 0) : 0;
        $totalPages = ceil($totalRules / $perPage);

        $loadErrors = [];
        if (!$domainsRes['ok']) $loadErrors[] = 'Failed to load domains: ' . ($domainsRes['error'] ?? 'Unknown error');
        if (!$rulesRes['ok']) $loadErrors[] = 'Failed to load firewall rules: ' . ($rulesRes['error'] ?? 'Unknown error');

        return view('firewall', [
            'domains' => $domains,
            'firewallRules' => $rules,
            'loadErrors' => $loadErrors,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalRules' => $totalRules,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'domain_name' => ['required', 'string'],
            'description' => ['nullable', 'string', 'max:255'],
            'action' => ['required', 'in:block,challenge,managed_challenge,js_challenge,allow,block_ip_farm'],
            'field' => ['required', 'string', 'in:ip.src,ip.src.country,ip.src.asnum,http.request.uri.path,http.request.method,http.user_agent'],
            'operator' => ['required', 'string', 'in:eq,ne,in,contains,not_contains,starts_with'],
            'value' => ['required', 'string', 'max:3000'],
            'duration' => ['nullable', 'string', 'in:forever,1h,6h,24h,7d,30d'],
            'paused' => ['nullable', 'in:0,1'],
        ]);

        $domain = $validated['domain_name'];

        $expressionJson = json_encode([
            'field' => $validated['field'],
            'operator' => $validated['operator'],
            'value' => $validated['value'],
        ]);

        $expiresAt = null;
        if (!empty($validated['duration']) && $validated['duration'] !== 'forever') {
            $seconds = match ($validated['duration']) {
                '1h' => 3600,
                '6h' => 21600,
                '24h' => 86400,
                '7d' => 604800,
                '30d' => 2592000,
                default => 0,
            };
            if ($seconds > 0) {
                $expiresAt = time() + $seconds;
            }
        }

        // --- IP Farm Sync: Allow rule → auto-remove IPs from farm ---
        if ($validated['action'] === 'allow' && $validated['field'] === 'ip.src') {
            $ipRaw = trim(strtolower($validated['value']));
            
            // If it's a single exact IP, purge its security logs and any manual blocks instantly
            if (!str_contains($ipRaw, ',') && !str_contains($ipRaw, '/')) {
                $this->edgeShield->queryD1("DELETE FROM security_logs WHERE ip_address = '" . str_replace("'", "''", $ipRaw) . "'");
                $this->edgeShield->queryD1("DELETE FROM ip_access_rules WHERE ip_or_cidr = '" . str_replace("'", "''", $ipRaw) . "'");
            }

            $farmIps = $this->edgeShield->findIpsInFarm($validated['value']);
            if (!empty($farmIps)) {
                $removal = $this->edgeShield->removeIpsFromFarm($farmIps);
                $removedCount = $removal['removed'] ?? 0;
                // Continue creating the allow rule, but add context to success message
                $farmMessage = $removedCount > 0
                    ? " Also removed {$removedCount} IP(s) from the IP Farm graveyard."
                    : '';

                $create = $this->edgeShield->createCustomFirewallRule(
                    $domain,
                    $validated['description'] ?? '',
                    $validated['action'],
                    $expressionJson,
                    ((int) ($validated['paused'] ?? 0)) === 1,
                    $expiresAt
                );

                return back()->with(
                    $create['ok'] ? 'status' : 'error',
                    $create['ok'] ? 'Firewall rule created successfully.' . $farmMessage : ($create['error'] ?? 'Failed to create firewall rule.')
                );
            }
        }

        // --- IP Farm Sync: Block rule → reject if IP already in farm ---
        if ($validated['action'] === 'block' && $validated['field'] === 'ip.src') {
            $farmIps = $this->edgeShield->findIpsInFarm($validated['value']);
            if (!empty($farmIps)) {
                $ipList = implode(', ', array_slice($farmIps, 0, 5));
                $extra = count($farmIps) > 5 ? ' (+' . (count($farmIps) - 5) . ' more)' : '';
                return back()->with(
                    'error',
                    "These IPs are already permanently banned in the IP Farm: {$ipList}{$extra}. No need to create a duplicate block rule."
                );
            }
        }

        $finalAction = $validated['action'];
        $finalDescription = $validated['description'] ?? '';

        if ($finalAction === 'block_ip_farm') {
            if ($validated['field'] !== 'ip.src') {
                return back()->with('error', 'The "block to ip farm" action can only be used when Field is set to "IP Address / CIDR".');
            }
            $finalAction = 'block';
            $expiresAt = null; // IP Farm rules are always forever
            if (!str_starts_with($finalDescription, '[IP-FARM]')) {
                $finalDescription = trim('[IP-FARM] ' . $finalDescription);
            }
        }

        $create = $this->edgeShield->createCustomFirewallRule(
            $domain,
            $finalDescription,
            $finalAction,
            $expressionJson,
            ((int) ($validated['paused'] ?? 0)) === 1,
            $expiresAt
        );

        return back()->with(
            $create['ok'] ? 'status' : 'error',
            $create['ok'] ? 'Firewall rule created successfully.' : ($create['error'] ?? 'Failed to create firewall rule.')
        );
    }

    public function toggle(string $domain, int $ruleId, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'paused' => ['required', 'in:0,1'],
        ]);

        $isPausing = ((int) $validated['paused']) === 1;

        // When pausing a rule, revoke the corresponding KV admin entry
        if ($isPausing) {
            $ruleRes = $this->edgeShield->getCustomFirewallRuleById($domain, $ruleId);
            if ($ruleRes['ok'] && !empty($ruleRes['rule'])) {
                $rule = $ruleRes['rule'];
                $this->edgeShield->syncKvForFirewallRuleAction(
                    $domain,
                    (string) ($rule['expression_json'] ?? ''),
                    (string) ($rule['action'] ?? '')
                );
            }
        }

        $toggle = $this->edgeShield->toggleCustomFirewallRule($domain, $ruleId, $isPausing);

        return back()->with(
            $toggle['ok'] ? 'status' : 'error',
            $toggle['ok'] ? 'Firewall rule status updated.' : ($toggle['error'] ?? 'Failed to update firewall rule.')
        );
    }

    public function destroy(string $domain, string $ruleId): RedirectResponse
    {
        // Revoke KV entry before deleting the D1 rule
        $ruleRes = $this->edgeShield->getCustomFirewallRuleById($domain, (int) $ruleId);
        if ($ruleRes['ok'] && !empty($ruleRes['rule'])) {
            $rule = $ruleRes['rule'];
            $this->edgeShield->syncKvForFirewallRuleAction(
                $domain,
                (string) ($rule['expression_json'] ?? ''),
                (string) ($rule['action'] ?? '')
            );
        }

        $delete = $this->edgeShield->deleteCustomFirewallRule($domain, (int) $ruleId);

        return back()->with(
            $delete['ok'] ? 'status' : 'error',
            $delete['ok'] ? 'Firewall rule deleted.' : ($delete['error'] ?? 'Failed to delete firewall rule.')
        );
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rule_ids' => ['required', 'array'],
            'rule_ids.*' => ['integer'],
        ]);

        // Revoke KV entries for all rules before bulk-deleting from D1
        foreach ($validated['rule_ids'] as $id) {
            $ruleRes = $this->edgeShield->getCustomFirewallRuleByIdGlobal((int) $id);
            if ($ruleRes['ok'] && !empty($ruleRes['rule'])) {
                $rule = $ruleRes['rule'];
                $this->edgeShield->syncKvForFirewallRuleAction(
                    (string) ($rule['domain_name'] ?? ''),
                    (string) ($rule['expression_json'] ?? ''),
                    (string) ($rule['action'] ?? '')
                );
            }
        }

        $delete = $this->edgeShield->deleteBulkCustomFirewallRules($validated['rule_ids']);

        return back()->with(
            $delete['ok'] ? 'status' : 'error',
            $delete['ok'] ? 'Selected rules deleted successfully.' : ($delete['error'] ?? 'Failed to delete bulk rules.')
        );
    }

    public function edit(string $domain, int $ruleId): View|RedirectResponse
    {
        $domainsRes = $this->edgeShield->listDomains();
        $domains = $domainsRes['ok'] ? ($domainsRes['domains'] ?? []) : [];

        $ruleRes = $this->edgeShield->getCustomFirewallRuleById($domain, $ruleId);
        if (!$ruleRes['ok'] || !$ruleRes['rule']) {
            return redirect()->route('firewall.index')->with('error', $ruleRes['error'] ?? 'Rule not found.');
        }

        return view('firewall_edit', [
            'domains' => $domains,
            'rule' => $ruleRes['rule'],
        ]);
    }

    public function update(string $domain, int $ruleId, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'action' => ['required', 'in:block,challenge,managed_challenge,js_challenge,allow,block_ip_farm'],
            'field' => ['required', 'string', 'in:ip.src,ip.src.country,ip.src.asnum,http.request.uri.path,http.request.method,http.user_agent'],
            'operator' => ['required', 'string', 'in:eq,ne,in,contains,not_contains,starts_with'],
            'value' => ['required', 'string', 'max:3000'],
            'duration' => ['nullable', 'string', 'in:forever,1h,6h,24h,7d,30d'],
            'paused' => ['nullable', 'in:0,1'],
            'preserve_expiry' => ['nullable', 'in:1'],
        ]);

        $expressionJson = json_encode([
            'field' => $validated['field'],
            'operator' => $validated['operator'],
            'value' => $validated['value'],
        ]);

        $isPaused = ((int) ($validated['paused'] ?? 0)) === 1;

        // Sync KV: revoke old action before applying updates
        $oldRuleRes = $this->edgeShield->getCustomFirewallRuleById($domain, $ruleId);
        if ($oldRuleRes['ok'] && !empty($oldRuleRes['rule'])) {
            $oldRule = $oldRuleRes['rule'];
            $oldAction = (string) ($oldRule['action'] ?? '');
            $newAction = $validated['action'];

            // Revoke old KV if: action changed, or rule is being paused
            if ($isPaused || $oldAction !== $newAction) {
                $this->edgeShield->syncKvForFirewallRuleAction(
                    $domain,
                    (string) ($oldRule['expression_json'] ?? ''),
                    $oldAction
                );
            }
        }

        $expiresAt = null;
        if (!empty($validated['duration']) && $validated['duration'] !== 'forever') {
            $seconds = match ($validated['duration']) {
                '1m' => 60,
                '1h' => 3600,
                '6h' => 21600,
                '24h' => 86400,
                '7d' => 604800,
                '30d' => 2592000,
                default => 0,
            };
            if ($seconds > 0) {
                $expiresAt = time() + $seconds;
            }
        } elseif (!empty($validated['preserve_expiry'])) {
            if (isset($oldRuleRes) && ($oldRuleRes['ok'] ?? false) && !empty($oldRuleRes['rule']['expires_at'])) {
                $expiresAt = $oldRuleRes['rule']['expires_at'];
            } else {
                $ruleRes = $this->edgeShield->getCustomFirewallRuleById($domain, $ruleId);
                if ($ruleRes['ok'] && !empty($ruleRes['rule']['expires_at'])) {
                    $expiresAt = $ruleRes['rule']['expires_at'];
                }
            }
        }

        $finalAction = $validated['action'];
        $finalDescription = $validated['description'] ?? '';

        if ($finalAction === 'allow' && $validated['field'] === 'ip.src') {
            $ipRaw = trim(strtolower($validated['value']));
            
            // If it's a single exact IP, purge its security logs and any manual blocks instantly
            if (!str_contains($ipRaw, ',') && !str_contains($ipRaw, '/')) {
                $this->edgeShield->queryD1("DELETE FROM security_logs WHERE ip_address = '" . str_replace("'", "''", $ipRaw) . "'");
                $this->edgeShield->queryD1("DELETE FROM ip_access_rules WHERE ip_or_cidr = '" . str_replace("'", "''", $ipRaw) . "'");
            }

            $farmIps = $this->edgeShield->findIpsInFarm($validated['value']);
            if (!empty($farmIps)) {
                $this->edgeShield->removeIpsFromFarm($farmIps);
            }
        }

        if ($finalAction === 'block_ip_farm') {
            if ($validated['field'] !== 'ip.src') {
                return back()->with('error', 'The "block to ip farm" action can only be used when Field is set to "IP Address / CIDR".');
            }
            $finalAction = 'block';
            $expiresAt = null; // IP Farm rules are always forever
            if (!str_starts_with($finalDescription, '[IP-FARM]')) {
                $finalDescription = trim('[IP-FARM] ' . $finalDescription);
            }
        }

        $update = $this->edgeShield->updateCustomFirewallRule(
            $domain,
            $ruleId,
            $finalDescription,
            $finalAction,
            $expressionJson,
            $isPaused,
            $expiresAt
        );

        return redirect()->route('firewall.index')->with(
            $update['ok'] ? 'status' : 'error',
            $update['ok'] ? 'Firewall rule updated successfully.' : ($update['error'] ?? 'Failed to update firewall rule.')
        );
    }
}
