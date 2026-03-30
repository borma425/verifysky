<?php

namespace App\Http\Controllers;

use App\Services\EdgeShieldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DomainsController extends Controller
{
    public function __construct(private readonly EdgeShieldService $edgeShield)
    {
    }

    public function index(): View
    {
        $this->edgeShield->ensureSecurityModeColumn();
        $this->edgeShield->ensureThresholdsColumn();
        $result = $this->edgeShield->listDomains();

        return view('domains.index', [
            'domains' => $result['ok'] ? ($result['domains'] ?? []) : [],
            'error' => $result['ok'] ? null : ($result['error'] ?: 'Failed to load domains'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->edgeShield->ensureSecurityModeColumn();
        $this->edgeShield->ensureThresholdsColumn();
        $validated = $request->validate([
            'domain_name' => ['required', 'string', 'max:255'],
            'zone_id' => ['nullable', 'string', 'max:128'],
            'turnstile_sitekey' => ['nullable', 'string', 'max:255'],
            'turnstile_secret' => ['nullable', 'string', 'max:255'],
            'security_mode' => ['nullable', 'in:monitor,balanced,aggressive'],
        ]);
        $securityMode = $validated['security_mode'] ?? 'balanced';

        $provisioned = $this->edgeShield->autoProvisionDomainConfig(
            $validated['domain_name'],
            $validated['zone_id'] ?? null,
            $validated['turnstile_sitekey'] ?? null,
            $validated['turnstile_secret'] ?? null
        );

        if (!$provisioned['ok']) {
            return back()->withInput()->with('error', $provisioned['error'] ?? 'Failed to auto-provision domain settings from Cloudflare.');
        }

        $routeSync = $this->edgeShield->ensureWorkerRoute(
            (string) $provisioned['zone_id'],
            (string) $provisioned['domain_name']
        );
        if (!$routeSync['ok']) {
            return back()->withInput()->with(
                'error',
                ($routeSync['error'] ?? 'Failed to attach worker route for this domain.')
            );
        }

        $sql = sprintf(
            "INSERT INTO domain_configs (domain_name, zone_id, turnstile_sitekey, turnstile_secret, status, force_captcha, security_mode)
             VALUES ('%s', '%s', '%s', '%s', 'active', 0, '%s')
             ON CONFLICT(domain_name) DO UPDATE SET
               zone_id = excluded.zone_id,
               turnstile_sitekey = excluded.turnstile_sitekey,
               turnstile_secret = excluded.turnstile_secret,
               security_mode = excluded.security_mode,
               status = 'active'",
            str_replace("'", "''", (string) $provisioned['domain_name']),
            str_replace("'", "''", (string) $provisioned['zone_id']),
            str_replace("'", "''", (string) $provisioned['turnstile_sitekey']),
            str_replace("'", "''", (string) $provisioned['turnstile_secret']),
            str_replace("'", "''", (string) $securityMode)
        );

        $result = $this->edgeShield->queryD1($sql);
        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Domain added/updated successfully (auto-provisioned + route synced).' : ($result['error'] ?: 'Failed to add domain')
        );
    }

    public function updateStatus(string $domain, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,paused,revoked'],
        ]);

        $sql = sprintf(
            "UPDATE domain_configs SET status = '%s' WHERE domain_name = '%s'",
            $validated['status'],
            str_replace("'", "''", $domain)
        );
        $result = $this->edgeShield->queryD1($sql);

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Domain status updated.' : ($result['error'] ?: 'Failed to update status')
        );
    }

    public function destroy(string $domain): RedirectResponse
    {
        $escapedDomain = str_replace("'", "''", strtolower(trim($domain)));
        $readSql = sprintf(
            "SELECT domain_name, zone_id, turnstile_sitekey FROM domain_configs WHERE domain_name = '%s' LIMIT 1",
            $escapedDomain
        );
        $read = $this->edgeShield->queryD1($readSql);
        if (!$read['ok']) {
            return back()->with('error', $read['error'] ?: 'Failed to read domain config before delete.');
        }

        $rows = $this->edgeShield->parseWranglerJson($read['output'])[0]['results'] ?? [];
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;
        if (!$row) {
            return back()->with('error', 'Domain not found in configuration.');
        }

        $cleanup = $this->edgeShield->removeDomainSecurityArtifacts(
            (string) ($row['zone_id'] ?? ''),
            (string) ($row['domain_name'] ?? ''),
            (string) ($row['turnstile_sitekey'] ?? '')
        );

        $deleteSql = sprintf(
            "DELETE FROM domain_configs WHERE domain_name = '%s'",
            $escapedDomain
        );
        $result = $this->edgeShield->queryD1($deleteSql);
        if (!$result['ok']) {
            return back()->with('error', $result['error'] ?: 'Failed to remove domain');
        }

        if (!$cleanup['ok']) {
            return back()->with(
                'status',
                'Domain removed from configuration, but cleanup reported warnings: '.implode(' | ', $cleanup['details'] ?? [])
            );
        }

        return back()->with('status', 'Domain removed completely (config + route + Turnstile widget).');
    }

    public function toggleForceCaptcha(string $domain, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'force_captcha' => ['required', 'in:0,1'],
        ]);

        $sql = sprintf(
            "UPDATE domain_configs SET force_captcha = %d WHERE domain_name = '%s'",
            (int) $validated['force_captcha'],
            str_replace("'", "''", $domain)
        );
        $result = $this->edgeShield->queryD1($sql);

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Forced CAPTCHA mode updated.' : ($result['error'] ?: 'Failed to update forced CAPTCHA mode')
        );
    }

    public function updateSecurityMode(string $domain, Request $request): RedirectResponse
    {
        $this->edgeShield->ensureSecurityModeColumn();
        $validated = $request->validate([
            'security_mode' => ['required', 'in:monitor,balanced,aggressive'],
        ]);

        $sql = sprintf(
            "UPDATE domain_configs SET security_mode = '%s' WHERE domain_name = '%s'",
            str_replace("'", "''", $validated['security_mode']),
            str_replace("'", "''", $domain)
        );
        $result = $this->edgeShield->queryD1($sql);

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Security mode updated.' : ($result['error'] ?: 'Failed to update security mode')
        );
    }

    public function syncRoute(string $domain): RedirectResponse
    {
        $sql = sprintf(
            "SELECT domain_name, zone_id FROM domain_configs WHERE domain_name = '%s' LIMIT 1",
            str_replace("'", "''", $domain)
        );
        $result = $this->edgeShield->queryD1($sql);
        if (!$result['ok']) {
            return back()->with('error', $result['error'] ?: 'Failed to read domain config.');
        }

        $rows = $this->edgeShield->parseWranglerJson($result['output'])[0]['results'] ?? [];
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;
        if (!$row || !isset($row['zone_id'], $row['domain_name'])) {
            return back()->with('error', 'Domain not found in configuration.');
        }

        $sync = $this->edgeShield->ensureWorkerRoute((string) $row['zone_id'], (string) $row['domain_name']);
        return back()->with(
            $sync['ok'] ? 'status' : 'error',
            $sync['ok']
                ? 'Worker route synced successfully.'
                : ($sync['error'] ?? 'Failed to sync worker route.')
        );
    }

    public function tuning(string $domain): View|RedirectResponse
    {
        $this->edgeShield->ensureThresholdsColumn();
        $result = $this->edgeShield->getDomainConfig($domain);
        if (!$result['ok']) {
            return redirect()->route('domains.index')->with('error', $result['error']);
        }

        $config = $result['config'];
        $thresholds = [];
        if (!empty($config['thresholds_json'])) {
            $thresholds = json_decode($config['thresholds_json'], true) ?: [];
        }

        // Convert seconds to friendly units for the UI
        if (isset($thresholds['session_ttl_seconds'])) {
            $thresholds['session_ttl_hours'] = round($thresholds['session_ttl_seconds'] / 3600, 2);
        }
        if (isset($thresholds['temp_ban_ttl_seconds'])) {
            $thresholds['temp_ban_ttl_hours'] = round($thresholds['temp_ban_ttl_seconds'] / 3600, 2);
        }
        if (isset($thresholds['ai_rule_ttl_seconds'])) {
            $thresholds['ai_rule_ttl_days'] = round($thresholds['ai_rule_ttl_seconds'] / 86400, 2);
        }
        if (isset($thresholds['auto_aggr_pressure_seconds'])) {
            $thresholds['auto_aggr_pressure_minutes'] = round($thresholds['auto_aggr_pressure_seconds'] / 60, 1);
        }
        if (isset($thresholds['auto_aggr_active_seconds'])) {
            $thresholds['auto_aggr_active_minutes'] = round($thresholds['auto_aggr_active_seconds'] / 60, 1);
        }

        return view('domains.tuning', [
            'domain' => $domain,
            'config' => $config,
            'thresholds' => $thresholds,
        ]);
    }

    public function updateTuning(string $domain, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'visit_captcha_threshold' => 'required|integer|min:1|max:5000',
            'daily_visit_limit' => 'required|integer|min:1|max:100000',
            'asn_hourly_visit_limit' => 'required|integer|min:50|max:100000',
            'flood_burst_challenge' => 'required|integer|min:1|max:50000',
            'flood_burst_block' => 'required|integer|min:1|max:50000',
            'flood_sustained_challenge' => 'required|integer|min:1|max:50000',
            'flood_sustained_block' => 'required|integer|min:1|max:50000',
            'ip_hard_ban_rate' => 'required|integer|min:10|max:50000',
            'max_challenge_failures' => 'required|integer|min:1|max:50',
            'temp_ban_ttl_hours' => 'required|numeric|min:0.01|max:720',
            'ai_rule_ttl_days' => 'required|numeric|min:0.1|max:365',
            'session_ttl_hours' => 'required|numeric|min:0.01|max:168',
            'auto_aggr_pressure_minutes' => 'required|numeric|min:1|max:30',
            'auto_aggr_active_minutes' => 'required|numeric|min:1|max:120',
            'auto_aggr_trigger_subnets' => 'required|integer|min:2|max:50',
            'api_count' => 'nullable|integer|min:0|max:5000',
        ]);

        // Convert back to seconds for the Worker
        $thresholds = $validated;
        $thresholds['temp_ban_ttl_seconds'] = (int) ($validated['temp_ban_ttl_hours'] * 3600);
        $thresholds['ai_rule_ttl_seconds'] = (int) ($validated['ai_rule_ttl_days'] * 86400);
        $thresholds['session_ttl_seconds'] = (int) ($validated['session_ttl_hours'] * 3600);
        $thresholds['auto_aggr_pressure_seconds'] = (int) ($validated['auto_aggr_pressure_minutes'] * 60);
        $thresholds['auto_aggr_active_seconds'] = (int) ($validated['auto_aggr_active_minutes'] * 60);
        $thresholds['ad_traffic_strict_mode'] = $request->boolean('ad_traffic_strict_mode');

        // Remove the virtual UI fields before encoding
        unset($thresholds['temp_ban_ttl_hours'], $thresholds['ai_rule_ttl_days'], $thresholds['session_ttl_hours']);
        unset($thresholds['auto_aggr_pressure_minutes'], $thresholds['auto_aggr_active_minutes']);

        $json = json_encode($thresholds);
        $result = $this->edgeShield->updateDomainThresholds($domain, $json);

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Domain thresholds updated successfully (caches cleared).' : ($result['error'] ?: 'Failed to update thresholds.')
        );
    }
}
