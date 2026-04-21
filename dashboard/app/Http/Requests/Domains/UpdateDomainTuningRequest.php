<?php

namespace App\Http\Requests\Domains;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDomainTuningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
        ];
    }
}
