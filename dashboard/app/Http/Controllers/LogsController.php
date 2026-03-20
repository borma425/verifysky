<?php

namespace App\Http\Controllers;

use App\Services\EdgeShieldService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LogsController extends Controller
{
    public function __construct(private readonly EdgeShieldService $edgeShield)
    {
    }

    public function index(Request $request): View
    {
        $event = trim((string) $request->query('event_type', ''));
        $where = $event !== '' ? "WHERE event_type = '".str_replace("'", "''", $event)."'" : '';

        $result = $this->edgeShield->queryD1(
            "SELECT id,event_type,ip_address,asn,country,target_path,details,created_at
             FROM security_logs
             {$where}
             ORDER BY id DESC
             LIMIT 200"
        );

        return view('logs.index', [
            'logs' => $result['ok']
                ? ($this->edgeShield->parseWranglerJson($result['output'])[0]['results'] ?? [])
                : [],
            'error' => $result['ok'] ? null : ($result['error'] ?: 'Failed to load logs'),
            'eventType' => $event,
        ]);
    }
}

