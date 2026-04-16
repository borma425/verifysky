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
            'security_mode' => ['nullable', 'in:monitor,balanced,aggressive'],
        ]);
        $securityMode = $validated['security_mode'] ?? 'balanced';

        $provisioned = $this->edgeShield->provisionSaasCustomHostname($validated['domain_name']);

        if (!$provisioned['ok']) {
            return back()->withInput()->with('error', $provisioned['error'] ?? 'Failed to create Cloudflare Custom Hostname.');
        }

        $sql = sprintf(
            "INSERT INTO domain_configs (
                domain_name, zone_id, turnstile_sitekey, turnstile_secret, status, force_captcha, security_mode,
                custom_hostname_id, cname_target, hostname_status, ssl_status, ownership_verification_json, updated_at
             )
             VALUES ('%s', '%s', '%s', '%s', 'active', 0, '%s', '%s', '%s', '%s', '%s', '%s', CURRENT_TIMESTAMP)
             ON CONFLICT(domain_name) DO UPDATE SET
               zone_id = excluded.zone_id,
               turnstile_sitekey = excluded.turnstile_sitekey,
               turnstile_secret = excluded.turnstile_secret,
               security_mode = excluded.security_mode,
               custom_hostname_id = excluded.custom_hostname_id,
               cname_target = excluded.cname_target,
               hostname_status = excluded.hostname_status,
               ssl_status = excluded.ssl_status,
               ownership_verification_json = excluded.ownership_verification_json,
               updated_at = CURRENT_TIMESTAMP,
               status = 'active'",
            str_replace("'", "''", (string) $provisioned['domain_name']),
            str_replace("'", "''", (string) $provisioned['zone_id']),
            str_replace("'", "''", (string) $provisioned['turnstile_sitekey']),
            str_replace("'", "''", (string) $provisioned['turnstile_secret']),
            str_replace("'", "''", (string) $securityMode),
            str_replace("'", "''", (string) $provisioned['custom_hostname_id']),
            str_replace("'", "''", (string) $provisioned['cname_target']),
            str_replace("'", "''", (string) $provisioned['hostname_status']),
            str_replace("'", "''", (string) $provisioned['ssl_status']),
            str_replace("'", "''", (string) $provisioned['ownership_verification_json'])
        );

        $result = $this->edgeShield->queryD1($sql);
        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok']
                ? 'Custom hostname added. Ask the customer to CNAME their domain to '.$this->edgeShield->saasCnameTarget().'.'
                : ($result['error'] ?: 'Failed to add domain')
        );
    }

    public function updateStatus(string $domain, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,paused,revoked'],
        ]);

        $sql = sprintf(
            "UPDATE domain_configs SET status = '%s', updated_at = CURRENT_TIMESTAMP WHERE domain_name = '%s'",
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
            "SELECT domain_name, custom_hostname_id FROM domain_configs WHERE domain_name = '%s' LIMIT 1",
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

        $cleanup = $this->edgeShield->deleteSaasCustomHostname((string) ($row['custom_hostname_id'] ?? ''));

        $deleteSql = sprintf(
            "DELETE FROM domain_configs WHERE domain_name = '%s'",
            $escapedDomain
        );
        $result = $this->edgeShield->queryD1($deleteSql);
        if (!$result['ok']) {
            return back()->with('error', $result['error'] ?: 'Failed to remove domain');
        }

        if (!$cleanup['ok']) {
            return back()->with('status', 'Domain removed from configuration, but Cloudflare cleanup reported: '.($cleanup['error'] ?? 'unknown warning'));
        }

        return back()->with('status', 'Domain removed completely.');
    }

    public function toggleForceCaptcha(string $domain, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'force_captcha' => ['required', 'in:0,1'],
        ]);

        $sql = sprintf(
            "UPDATE domain_configs SET force_captcha = %d, updated_at = CURRENT_TIMESTAMP WHERE domain_name = '%s'",
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
            "UPDATE domain_configs SET security_mode = '%s', updated_at = CURRENT_TIMESTAMP WHERE domain_name = '%s'",
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
        $sync = $this->edgeShield->refreshSaasCustomHostname($domain);
        return back()->with(
            $sync['ok'] ? 'status' : 'error',
            $sync['ok']
                ? 'Cloudflare hostname status refreshed.'
                : ($sync['error'] ?? 'Failed to refresh hostname status.')
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

        // Normalize challenge thresholds for per-mode editing in UI.
        // Backward compatible with legacy scalar values.
        $defaults = [
            'balanced' => ['solve' => 150, 'points' => 3, 'tolerance' => 24],
            'aggressive' => ['solve' => 200, 'points' => 4, 'tolerance' => 24],
        ];
        $challengeProfiles = [
            'balanced' => [
                'solve' => $defaults['balanced']['solve'],
                'points' => $defaults['balanced']['points'],
                'tolerance' => $defaults['balanced']['tolerance'],
            ],
            'aggressive' => [
                'solve' => $defaults['aggressive']['solve'],
                'points' => $defaults['aggressive']['points'],
                'tolerance' => $defaults['aggressive']['tolerance'],
            ],
        ];

        $solveRaw = $thresholds['challenge_min_solve_ms'] ?? null;
        $pointsRaw = $thresholds['challenge_min_telemetry_points'] ?? null;
        $tolRaw = $thresholds['challenge_x_tolerance'] ?? null;

        foreach (['balanced', 'aggressive'] as $mode) {
            if (is_array($solveRaw) && isset($solveRaw[$mode]) && is_numeric($solveRaw[$mode])) {
                $challengeProfiles[$mode]['solve'] = (int) $solveRaw[$mode];
            } elseif (is_numeric($solveRaw)) {
                $challengeProfiles[$mode]['solve'] = (int) $solveRaw;
            }

            if (is_array($pointsRaw) && isset($pointsRaw[$mode]) && is_numeric($pointsRaw[$mode])) {
                $challengeProfiles[$mode]['points'] = (int) $pointsRaw[$mode];
            } elseif (is_numeric($pointsRaw)) {
                $challengeProfiles[$mode]['points'] = (int) $pointsRaw;
            }

            if (is_array($tolRaw) && isset($tolRaw[$mode]) && is_numeric($tolRaw[$mode])) {
                $challengeProfiles[$mode]['tolerance'] = (int) $tolRaw[$mode];
            } elseif (is_numeric($tolRaw)) {
                $challengeProfiles[$mode]['tolerance'] = (int) $tolRaw;
            }
        }

        return view('domains.tuning', [
            'domain' => $domain,
            'config' => $config,
            'thresholds' => $thresholds,
            'challengeProfiles' => $challengeProfiles,
        ]);
    }

    public function updateTuning(string $domain, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'visit_captcha_threshold' => 'required|integer|min:1|max:5000',
            'daily_visit_limit' => 'required|integer|min:1|max:1000000',
            'asn_hourly_visit_limit' => 'required|integer|min:50|max:1000000',
            'flood_burst_challenge' => 'required|integer|min:1|max:50000',
            'flood_burst_block' => 'required|integer|min:1|max:50000',
            'flood_sustained_challenge' => 'required|integer|min:1|max:50000',
            'flood_sustained_block' => 'required|integer|min:1|max:50000',
            'ip_hard_ban_rate' => 'required|integer|min:1|max:50000',
            'max_challenge_failures' => 'required|integer|min:1|max:50',
            'temp_ban_ttl_hours' => 'required|numeric|min:0.01|max:720',
            'ai_rule_ttl_days' => 'required|numeric|min:0.1|max:365',
            'session_ttl_hours' => 'required|numeric|min:0.01|max:168',
            'auto_aggr_pressure_minutes' => 'required|numeric|min:1|max:30',
            'auto_aggr_active_minutes' => 'required|numeric|min:1|max:120',
            'auto_aggr_trigger_subnets' => 'required|integer|min:2|max:50',
            'challenge_min_solve_ms_balanced' => 'required|integer|min:50|max:1000',
            'challenge_min_telemetry_points_balanced' => 'required|integer|min:2|max:20',
            'challenge_x_tolerance_balanced' => 'required|integer|min:5|max:50',
            'challenge_min_solve_ms_aggressive' => 'required|integer|min:50|max:1000',
            'challenge_min_telemetry_points_aggressive' => 'required|integer|min:2|max:20',
            'challenge_x_tolerance_aggressive' => 'required|integer|min:5|max:50',
            'api_count' => 'nullable|integer|min:0|max:5000',
        ]);

        // Convert back to seconds for the Worker
        // IMPORTANT: Cast ALL numeric values to (int) so json_encode() writes
        // JSON numbers (400000) instead of JSON strings ("400000").
        // Number.isFinite() in the Worker returns false for strings!
        $thresholds = [];
        $thresholds['visit_captcha_threshold']    = (int) $validated['visit_captcha_threshold'];
        $thresholds['daily_visit_limit']          = (int) $validated['daily_visit_limit'];
        $thresholds['asn_hourly_visit_limit']     = (int) $validated['asn_hourly_visit_limit'];
        $thresholds['flood_burst_challenge']      = (int) $validated['flood_burst_challenge'];
        $thresholds['flood_burst_block']          = (int) $validated['flood_burst_block'];
        $thresholds['flood_sustained_challenge']  = (int) $validated['flood_sustained_challenge'];
        $thresholds['flood_sustained_block']      = (int) $validated['flood_sustained_block'];
        $thresholds['ip_hard_ban_rate']           = (int) $validated['ip_hard_ban_rate'];
        $thresholds['max_challenge_failures']     = (int) $validated['max_challenge_failures'];
        $thresholds['auto_aggr_trigger_subnets']  = (int) $validated['auto_aggr_trigger_subnets'];
        $thresholds['temp_ban_ttl_seconds']       = (int) ($validated['temp_ban_ttl_hours'] * 3600);
        $thresholds['ai_rule_ttl_seconds']        = (int) ($validated['ai_rule_ttl_days'] * 86400);
        $thresholds['session_ttl_seconds']        = (int) ($validated['session_ttl_hours'] * 3600);
        $thresholds['auto_aggr_pressure_seconds'] = (int) ($validated['auto_aggr_pressure_minutes'] * 60);
        $thresholds['auto_aggr_active_seconds']   = (int) ($validated['auto_aggr_active_minutes'] * 60);
        $thresholds['ad_traffic_strict_mode']     = $request->boolean('ad_traffic_strict_mode');

        // Challenge sensitivity thresholds per security mode.
        // Stored in the same keys for backward compatibility, as mode maps.
        $thresholds['challenge_min_solve_ms'] = [
            'balanced' => (int) $validated['challenge_min_solve_ms_balanced'],
            'aggressive' => (int) $validated['challenge_min_solve_ms_aggressive'],
        ];
        $thresholds['challenge_min_telemetry_points'] = [
            'balanced' => (int) $validated['challenge_min_telemetry_points_balanced'],
            'aggressive' => (int) $validated['challenge_min_telemetry_points_aggressive'],
        ];
        $thresholds['challenge_x_tolerance'] = [
            'balanced' => (int) $validated['challenge_x_tolerance_balanced'],
            'aggressive' => (int) $validated['challenge_x_tolerance_aggressive'],
        ];

        // Include api_count if provided
        if (isset($validated['api_count'])) {
            $thresholds['api_count'] = (int) $validated['api_count'];
        }

        $json = json_encode($thresholds);
        $result = $this->edgeShield->updateDomainThresholds($domain, $json);

        return back()->with(
            $result['ok'] ? 'status' : 'error',
            $result['ok'] ? 'Domain thresholds updated successfully (caches cleared).' : ($result['error'] ?: 'Failed to update thresholds.')
        );
    }
}
