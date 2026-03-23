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
            'domains' => 0,
            'events_last_24h' => 0,
            'challenges_issued' => 0,
            'challenges_solved' => 0,
            'hard_blocks' => 0,
        ];
        $stats = Cache::get('dashboard:stats:last_ok', $defaultStats);
        $recent = Cache::get('dashboard:recent:last_ok', []);

        $summary = $this->edgeShield->queryD1(
            "SELECT
                (SELECT COUNT(*) FROM domain_configs WHERE status = 'active') AS domains,
                (SELECT COUNT(*) FROM security_logs WHERE datetime(created_at) >= datetime('now', '-24 hours')) AS events_last_24h,
                (SELECT COUNT(*) FROM security_logs WHERE event_type = 'challenge_issued' AND datetime(created_at) >= datetime('now', '-24 hours')) AS challenges_issued,
                (SELECT COUNT(*) FROM security_logs WHERE event_type = 'challenge_solved' AND datetime(created_at) >= datetime('now', '-24 hours')) AS challenges_solved,
                (SELECT COUNT(*) FROM security_logs WHERE event_type = 'hard_block' AND datetime(created_at) >= datetime('now', '-24 hours')) AS hard_blocks"
            ,
            18
        );
        if ($summary['ok']) {
            $rows = $this->edgeShield->parseWranglerJson($summary['output']);
            $stats = $rows[0]['results'][0] ?? $stats;
            Cache::put('dashboard:stats:last_ok', $stats, now()->addMinutes(5));
        }

        $logs = $this->edgeShield->queryD1(
            "SELECT id,event_type,ip_address,details,created_at FROM security_logs ORDER BY id DESC LIMIT 15",
            14
        );
        if ($logs['ok']) {
            $rows = $this->edgeShield->parseWranglerJson($logs['output']);
            $recent = $rows[0]['results'] ?? [];
            Cache::put('dashboard:recent:last_ok', $recent, now()->addMinutes(5));
        }

        return view('dashboard.index', [
            'stats' => $stats,
            'recent' => $recent,
            'projectRoot' => $this->edgeShield->projectRoot(),
        ]);
    }
}
