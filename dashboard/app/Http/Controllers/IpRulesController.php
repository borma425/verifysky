<?php

namespace App\Http\Controllers;

use App\Services\EdgeShieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IpRulesController extends Controller
{
    public function __construct(private readonly EdgeShieldService $edgeShield)
    {
    }

    public function index(): View
    {
        // Ensure table exists on first visit
        $this->edgeShield->ensureIpAccessRulesTable();

        // Fetch all domains for the dropdown
        $domainsRes = $this->edgeShield->listDomains();
        $domains = $domainsRes['ok'] ? ($domainsRes['domains'] ?? []) : [];

        // Fetch all IP rules
        $ipRulesRes = $this->edgeShield->listAllIpAccessRules();
        $ipRules = $ipRulesRes['ok'] ? ($ipRulesRes['rules'] ?? []) : [];

        $loadErrors = [];
        if (!$domainsRes['ok']) $loadErrors[] = 'Failed to load domains: ' . ($domainsRes['error'] ?? 'Unknown error');
        if (!$ipRulesRes['ok']) $loadErrors[] = 'Failed to load IP rules: ' . ($ipRulesRes['error'] ?? 'Unknown error');

        return view('ip_rules', [
            'domains' => $domains,
            'ipRules' => $ipRules,
            'loadErrors' => $loadErrors,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'domain_name' => ['required', 'string'],
            'ip_or_cidr' => ['required', 'string', 'max:50'],
            'action' => ['required', 'in:allow,block'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $ipOrCidr = trim($validated['ip_or_cidr']);
        // Normalize ASN format: if they just type "12345" and want ASN, they should type "AS12345".
        // But let's auto-fix if it's purely digits and they meant ASN? No, let's rely on 'AS' prefix.
        if (preg_match('/^as\d+$/i', $ipOrCidr)) {
            $ipOrCidr = strtoupper($ipOrCidr);
        }

        $create = $this->edgeShield->createIpAccessRule(
            $validated['domain_name'],
            $ipOrCidr,
            $validated['action'],
            $validated['note'] ?? null
        );

        return back()->with(
            $create['ok'] ? 'status' : 'error',
            $create['ok'] ? 'IP rule created successfully.' : ($create['error'] ?? 'Failed to create IP rule.')
        );
    }

    public function destroy(int $ruleId, Request $request): RedirectResponse
    {
        $domainName = $request->input('domain_name');
        if (!$domainName) {
            return back()->with('error', 'Domain name is required to delete the rule.');
        }

        $delete = $this->edgeShield->deleteIpAccessRule($domainName, $ruleId);

        return back()->with(
            $delete['ok'] ? 'status' : 'error',
            $delete['ok'] ? 'IP rule deleted.' : ($delete['error'] ?? 'Failed to delete IP rule.')
        );
    }
}
