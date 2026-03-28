<?php

namespace App\Http\Controllers;

use App\Services\EdgeShieldService;
use Illuminate\View\View;

class IpFarmController extends Controller
{
    /**
     * Display the IP Farm — Permanent Ban Graveyard page.
     * Shows all [IP-FARM] rules from Global Firewall with timeline view.
     */
    public function index(): View
    {
        $service = new EdgeShieldService();
        $loadErrors = [];

        // Fetch IP Farm rules
        $farmResult = $service->listIpFarmRules();
        if (!$farmResult['ok']) {
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
                'description' => $rule['description'] ?? '',
                'action' => $rule['action'] ?? 'block',
                'paused' => (bool)($rule['paused'] ?? false),
                'ip_count' => count($ips),
                'ips' => $ips,
                'created_at' => $rule['created_at'] ?? null,
                'updated_at' => $rule['updated_at'] ?? null,
            ];
        }

        // Get stats
        $stats = $service->getIpFarmStats();

        return view('ip_farm', [
            'title' => 'IP Farm — Permanent Ban Graveyard',
            'loadErrors' => $loadErrors,
            'farmRules' => $parsedRules,
            'totalIps' => $totalIps,
            'totalRules' => count($parsedRules),
            'lastUpdated' => $stats['lastUpdated'] ?? null,
        ]);
    }
}
