<?php

namespace App\Http\Controllers;

use App\Services\EdgeShieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DomainRulesController extends Controller
{
    public function __construct(private readonly EdgeShieldService $edgeShield)
    {
    }

    public function index(string $domain): View|RedirectResponse
    {
        $domainConfig = $this->edgeShield->getDomainConfig($domain);
        if (!$domainConfig['ok']) {
            return redirect()->route('domains.index')->with('error', $domainConfig['error'] ?? 'Domain not found.');
        }

        $domainRow = is_array($domainConfig['domain']) ? $domainConfig['domain'] : [];
        $zoneId = (string) ($domainRow['zone_id'] ?? '');

        $routes = $this->edgeShield->listZoneWorkerRoutes($zoneId);
        $firewallRules = $this->edgeShield->listZoneFirewallRules($zoneId);

        return view('domains.rules', [
            'domain' => $domainRow,
            'workerRoutes' => $routes['ok'] ? ($routes['routes'] ?? []) : [],
            'firewallRules' => $firewallRules['ok'] ? ($firewallRules['rules'] ?? []) : [],
            'loadErrors' => array_values(array_filter([
                $routes['ok'] ? null : ($routes['error'] ?? 'Failed to load worker routes'),
                $firewallRules['ok'] ? null : ($firewallRules['error'] ?? 'Failed to load firewall rules'),
            ])),
        ]);
    }

    public function storeFirewallRule(string $domain, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'action' => ['required', 'in:block,challenge,managed_challenge,js_challenge,log,allow,bypass'],
            'expression' => ['required', 'string', 'max:3000'],
            'paused' => ['nullable', 'in:0,1'],
        ]);

        $domainConfig = $this->edgeShield->getDomainConfig($domain);
        if (!$domainConfig['ok']) {
            return back()->with('error', $domainConfig['error'] ?? 'Domain not found.');
        }

        $zoneId = (string) (($domainConfig['domain']['zone_id'] ?? '') ?: '');
        $create = $this->edgeShield->createZoneFirewallRule(
            $zoneId,
            $validated['expression'],
            $validated['action'],
            $validated['description'] ?? null,
            ((int) ($validated['paused'] ?? 0)) === 1
        );

        return back()->with(
            $create['ok'] ? 'status' : 'error',
            $create['ok'] ? 'Firewall rule created successfully.' : ($create['error'] ?? 'Failed to create firewall rule.')
        );
    }

    public function toggleFirewallRule(string $domain, string $ruleId, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'paused' => ['required', 'in:0,1'],
        ]);

        $domainConfig = $this->edgeShield->getDomainConfig($domain);
        if (!$domainConfig['ok']) {
            return back()->with('error', $domainConfig['error'] ?? 'Domain not found.');
        }

        $zoneId = (string) (($domainConfig['domain']['zone_id'] ?? '') ?: '');
        $toggle = $this->edgeShield->setZoneFirewallRulePaused(
            $zoneId,
            $ruleId,
            ((int) $validated['paused']) === 1
        );

        return back()->with(
            $toggle['ok'] ? 'status' : 'error',
            $toggle['ok'] ? 'Firewall rule updated.' : ($toggle['error'] ?? 'Failed to update firewall rule.')
        );
    }

    public function destroyFirewallRule(string $domain, string $ruleId): RedirectResponse
    {
        $domainConfig = $this->edgeShield->getDomainConfig($domain);
        if (!$domainConfig['ok']) {
            return back()->with('error', $domainConfig['error'] ?? 'Domain not found.');
        }

        $zoneId = (string) (($domainConfig['domain']['zone_id'] ?? '') ?: '');
        $delete = $this->edgeShield->deleteZoneFirewallRule($zoneId, $ruleId);

        return back()->with(
            $delete['ok'] ? 'status' : 'error',
            $delete['ok'] ? 'Firewall rule deleted.' : ($delete['error'] ?? 'Failed to delete firewall rule.')
        );
    }
}
