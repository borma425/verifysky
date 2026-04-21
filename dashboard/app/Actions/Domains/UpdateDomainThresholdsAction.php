<?php

namespace App\Actions\Domains;

use App\Services\EdgeShieldService;

class UpdateDomainThresholdsAction
{
    public function __construct(private readonly EdgeShieldService $edgeShield) {}

    public function execute(string $domain, array $validated, bool $strictMode, bool $isAdmin, ?string $tenantId): array
    {
        $thresholds = [];
        $thresholds['visit_captcha_threshold'] = (int) $validated['visit_captcha_threshold'];
        $thresholds['daily_visit_limit'] = (int) $validated['daily_visit_limit'];
        $thresholds['asn_hourly_visit_limit'] = (int) $validated['asn_hourly_visit_limit'];
        $thresholds['flood_burst_challenge'] = (int) $validated['flood_burst_challenge'];
        $thresholds['flood_burst_block'] = (int) $validated['flood_burst_block'];
        $thresholds['flood_sustained_challenge'] = (int) $validated['flood_sustained_challenge'];
        $thresholds['flood_sustained_block'] = (int) $validated['flood_sustained_block'];
        $thresholds['ip_hard_ban_rate'] = (int) $validated['ip_hard_ban_rate'];
        $thresholds['max_challenge_failures'] = (int) $validated['max_challenge_failures'];
        $thresholds['auto_aggr_trigger_subnets'] = (int) $validated['auto_aggr_trigger_subnets'];
        $thresholds['temp_ban_ttl_seconds'] = (int) ($validated['temp_ban_ttl_hours'] * 3600);
        $thresholds['ai_rule_ttl_seconds'] = (int) ($validated['ai_rule_ttl_days'] * 86400);
        $thresholds['session_ttl_seconds'] = (int) ($validated['session_ttl_hours'] * 3600);
        $thresholds['auto_aggr_pressure_seconds'] = (int) ($validated['auto_aggr_pressure_minutes'] * 60);
        $thresholds['auto_aggr_active_seconds'] = (int) ($validated['auto_aggr_active_minutes'] * 60);
        $thresholds['ad_traffic_strict_mode'] = $strictMode;
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

        if (isset($validated['api_count'])) {
            $thresholds['api_count'] = (int) $validated['api_count'];
        }

        return $this->edgeShield->updateDomainThresholds($domain, (string) json_encode($thresholds), $tenantId, $isAdmin);
    }
}
