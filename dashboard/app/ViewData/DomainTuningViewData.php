<?php

namespace App\ViewData;

class DomainTuningViewData
{
    private const DEFAULT_CHALLENGE_PROFILES = [
        'balanced' => ['solve' => 150, 'points' => 3, 'tolerance' => 24],
        'aggressive' => ['solve' => 200, 'points' => 4, 'tolerance' => 24],
    ];

    public function __construct(
        private readonly string $domain,
        private readonly array $config
    ) {}

    public function toArray(): array
    {
        $thresholds = $this->thresholdsForForm();
        $challengeProfiles = $this->challengeProfiles($thresholds);

        return [
            'domain' => $this->domain,
            'thresholds' => $thresholds,
            'challengeProfiles' => $challengeProfiles,
            'activeChallengeMode' => $this->activeChallengeMode(),
            'originServer' => (string) ($this->config['origin_server'] ?? ''),
            'clientState' => [
                'thresholds' => $this->clientThresholds($thresholds),
                'challengeProfiles' => $challengeProfiles,
                'activeChallengeMode' => $this->activeChallengeMode(),
            ],
        ];
    }

    private function thresholdsForForm(): array
    {
        $thresholds = [];
        if (! empty($this->config['thresholds_json'])) {
            $decoded = json_decode((string) $this->config['thresholds_json'], true);
            $thresholds = is_array($decoded) ? $decoded : [];
        }

        foreach ($this->timeConversions() as $secondsKey => [$displayKey, $divisor, $precision]) {
            if (isset($thresholds[$secondsKey])) {
                $thresholds[$displayKey] = round((float) $thresholds[$secondsKey] / $divisor, $precision);
            }
        }

        return $thresholds;
    }

    private function timeConversions(): array
    {
        return [
            'session_ttl_seconds' => ['session_ttl_hours', 3600, 2],
            'temp_ban_ttl_seconds' => ['temp_ban_ttl_hours', 3600, 2],
            'ai_rule_ttl_seconds' => ['ai_rule_ttl_days', 86400, 2],
            'auto_aggr_pressure_seconds' => ['auto_aggr_pressure_minutes', 60, 1],
            'auto_aggr_active_seconds' => ['auto_aggr_active_minutes', 60, 1],
        ];
    }

    private function challengeProfiles(array $thresholds): array
    {
        $profiles = self::DEFAULT_CHALLENGE_PROFILES;
        $solveRaw = $thresholds['challenge_min_solve_ms'] ?? null;
        $pointsRaw = $thresholds['challenge_min_telemetry_points'] ?? null;
        $toleranceRaw = $thresholds['challenge_x_tolerance'] ?? null;

        foreach (['balanced', 'aggressive'] as $mode) {
            $profiles[$mode]['solve'] = $this->resolveProfileValue($solveRaw, $mode, $profiles[$mode]['solve']);
            $profiles[$mode]['points'] = $this->resolveProfileValue($pointsRaw, $mode, $profiles[$mode]['points']);
            $profiles[$mode]['tolerance'] = $this->resolveProfileValue($toleranceRaw, $mode, $profiles[$mode]['tolerance']);
        }

        return $profiles;
    }

    private function resolveProfileValue(mixed $raw, string $mode, int $default): int
    {
        if (is_array($raw) && isset($raw[$mode]) && is_numeric($raw[$mode])) {
            return (int) $raw[$mode];
        }

        return is_numeric($raw) ? (int) $raw : $default;
    }

    private function activeChallengeMode(): string
    {
        return strtolower((string) ($this->config['security_mode'] ?? 'balanced')) === 'aggressive'
            ? 'aggressive'
            : 'balanced';
    }

    private function clientThresholds(array $thresholds): array
    {
        $allowedKeys = [
            'api_count',
            'visit_captcha_threshold',
            'daily_visit_limit',
            'ip_hard_ban_rate',
            'asn_hourly_visit_limit',
            'flood_burst_challenge',
            'flood_burst_block',
            'flood_sustained_challenge',
            'flood_sustained_block',
        ];

        return array_intersect_key($thresholds, array_flip($allowedKeys));
    }
}
