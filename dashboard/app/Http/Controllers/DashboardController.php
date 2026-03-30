<?php

namespace App\Http\Controllers;

use App\Services\EdgeShieldService;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly EdgeShieldService $edgeShield)
    {
    }

    public function index(): View
    {
        $defaultStats = [
            'active_domains' => 0,
            'total_attacks_today' => 0,
            'total_visitors_today' => 0,
            'top_countries' => [],
            'top_domains'   => [],
            'recent_critical' => [],
        ];
        
        $statsCacheKey = 'dashboard:overview_stats:v1';
        
        $stats = Cache::remember($statsCacheKey, 300, function() use ($defaultStats) {
            $sql = "
                SELECT COUNT(*) as active_domains FROM domain_configs WHERE status = 'active';
                
                SELECT 
                    SUM(CASE WHEN event_type IN ('challenge_failed', 'hard_block', 'turnstile_failed', 'replay_detected', 'session_rejected') THEN 1 ELSE 0 END) as total_attacks_today,
                    SUM(CASE WHEN event_type IN ('challenge_solved', 'session_created') THEN 1 ELSE 0 END) as total_visitors_today
                FROM security_logs
                WHERE datetime(created_at) >= datetime('now', 'start of day');
                
                SELECT country, COUNT(*) as attack_count 
                FROM security_logs 
                WHERE event_type IN ('challenge_failed', 'hard_block', 'turnstile_failed', 'replay_detected', 'session_rejected')
                AND country IS NOT NULL AND country != '' AND country != 'T1'
                AND datetime(created_at) >= datetime('now', 'start of day')
                GROUP BY country 
                ORDER BY attack_count DESC 
                LIMIT 5;
                
                SELECT domain_name, COUNT(*) as attack_count 
                FROM security_logs 
                WHERE event_type IN ('challenge_failed', 'hard_block', 'turnstile_failed', 'replay_detected', 'session_rejected')
                AND domain_name IS NOT NULL AND TRIM(domain_name) != ''
                AND datetime(created_at) >= datetime('now', 'start of day')
                GROUP BY domain_name 
                ORDER BY attack_count DESC 
                LIMIT 5;
                
                SELECT id, event_type, domain_name, ip_address, country, details, created_at 
                FROM security_logs 
                WHERE event_type IN ('hard_block', 'challenge_failed') 
                ORDER BY id DESC 
                LIMIT 6;
            ";

            $res = $this->edgeShield->queryD1($sql, 25);
            if (!$res['ok']) {
                return $defaultStats;
            }

            $parsed = $this->edgeShield->parseWranglerJson((string)($res['output'] ?? ''));
            if (empty($parsed)) {
                return $defaultStats;
            }
            
            return [
                'active_domains'       => (int)($parsed[0]['results'][0]['active_domains'] ?? 0),
                'total_attacks_today'  => (int)($parsed[1]['results'][0]['total_attacks_today'] ?? 0),
                'total_visitors_today' => (int)($parsed[1]['results'][0]['total_visitors_today'] ?? 0),
                'top_countries'        => $parsed[2]['results'] ?? [],
                'top_domains'          => $parsed[3]['results'] ?? [],
                'recent_critical'      => $parsed[4]['results'] ?? [],
            ];
        });

        return view('dashboard.index', [
            'stats' => $stats,
        ]);
    }
}
