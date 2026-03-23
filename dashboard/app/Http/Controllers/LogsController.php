<?php

namespace App\Http\Controllers;

use App\Services\EdgeShieldService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class LogsController extends Controller
{
    public function __construct(private readonly EdgeShieldService $edgeShield)
    {
    }

    public function index(Request $request): View
    {
        $this->edgeShield->ensureSecurityLogsDomainColumn();

        $event = trim((string) $request->query('event_type', ''));
        $domain = trim((string) $request->query('domain_name', ''));
        $ipAddress = trim((string) $request->query('ip_address', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;
        $filters = [];
        if ($event !== '') {
            $filters[] = "event_type = '".str_replace("'", "''", $event)."'";
        }
        if ($domain !== '') {
            $filters[] = "domain_name = '".str_replace("'", "''", strtolower($domain))."'";
        }
        if ($ipAddress !== '') {
            $filters[] = "ip_address = '".str_replace("'", "''", $ipAddress)."'";
        }
        $where = count($filters) > 0 ? 'WHERE '.implode(' AND ', $filters) : '';

        $filterOptions = Cache::remember('logs_filter_options_v1', 120, function (): array {
            $result = $this->edgeShield->queryD1(
                "SELECT 'domain' AS bucket, domain_name AS value
                 FROM (
                     SELECT DISTINCT domain_name
                     FROM domain_configs
                     WHERE domain_name IS NOT NULL AND TRIM(domain_name) != ''
                     UNION
                     SELECT DISTINCT domain_name
                     FROM security_logs
                     WHERE domain_name IS NOT NULL AND TRIM(domain_name) != ''
                 )
                 UNION ALL
                 SELECT 'event' AS bucket, event_type AS value
                 FROM (
                     SELECT DISTINCT event_type
                     FROM security_logs
                     WHERE event_type IS NOT NULL AND TRIM(event_type) != ''
                     ORDER BY event_type ASC
                     LIMIT 200
                 )"
            );

            if (!($result['ok'] ?? false)) {
                return ['domains' => [], 'events' => []];
            }

            $rows = $this->edgeShield->parseWranglerJson((string) ($result['output'] ?? ''))[0]['results'] ?? [];
            $domains = [];
            $events = [];

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $bucket = trim((string) ($row['bucket'] ?? ''));
                $value = trim((string) ($row['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                if ($bucket === 'domain') {
                    $domains[] = strtolower($value);
                } elseif ($bucket === 'event') {
                    $events[] = $value;
                }
            }

            sort($domains);
            sort($events);

            return [
                'domains' => array_values(array_unique($domains)),
                'events' => array_values(array_unique($events)),
            ];
        });

        $countResult = $this->edgeShield->queryD1(
            "SELECT COUNT(*) AS total_rows
             FROM (
               SELECT ip_address
               FROM security_logs
               {$where}
               GROUP BY ip_address
             ) grouped_ips"
        );
        $countOk = $countResult['ok'] ?? false;
        $countRows = $countOk
            ? ($this->edgeShield->parseWranglerJson((string) ($countResult['output'] ?? ''))[0]['results'] ?? [])
            : [];
        $total = isset($countRows[0]['total_rows']) ? (int) $countRows[0]['total_rows'] : 0;

        $result = $this->edgeShield->queryD1(
            "WITH filtered AS (
               SELECT *
               FROM security_logs
               {$where}
             ),
             grouped AS (
               SELECT
                 ip_address,
                 COUNT(*) AS requests,
                 SUM(
                   CASE
                     WHEN datetime(created_at) >= datetime('now', 'start of day') THEN 1
                     ELSE 0
                   END
                 ) AS requests_today,
                 SUM(
                   CASE
                     WHEN datetime(created_at) >= datetime('now', 'start of day', '-1 day')
                       AND datetime(created_at) < datetime('now', 'start of day') THEN 1
                     ELSE 0
                   END
                 ) AS requests_yesterday,
                 SUM(
                   CASE
                     WHEN datetime(created_at) >= datetime('now', 'start of month') THEN 1
                     ELSE 0
                   END
                 ) AS requests_month,
                 SUM(
                   CASE
                     WHEN event_type IN ('challenge_solved', 'session_created') THEN 1
                     ELSE 0
                   END
                 ) AS solved_or_passed_events,
                 SUM(
                   CASE
                     WHEN event_type IN ('challenge_failed', 'hard_block', 'turnstile_failed', 'replay_detected', 'session_rejected') THEN 1
                     ELSE 0
                   END
                 ) AS flagged_events,
                 MAX(id) AS latest_id,
                 MAX(
                   CASE
                     WHEN domain_name IS NOT NULL AND TRIM(domain_name) != '' THEN id
                     ELSE 0
                   END
                 ) AS latest_domain_id
               FROM filtered
               GROUP BY ip_address
             )
             SELECT
               COALESCE(fd.domain_name, f.domain_name) AS domain_name,
               f.event_type,
               f.risk_score,
               f.ip_address,
               f.asn,
               f.country,
               f.target_path,
               (
                 SELECT json_group_array(path_row.target_path)
                 FROM (
                   SELECT COALESCE(NULLIF(TRIM(fp.target_path), ''), '-') AS target_path
                   FROM filtered fp
                   WHERE fp.ip_address = g.ip_address
                   ORDER BY fp.id DESC
                   LIMIT 50
                 ) path_row
               ) AS recent_paths_json,
               f.details,
               f.created_at,
               g.requests,
               g.requests_today,
               g.requests_yesterday,
               g.requests_month,
               g.solved_or_passed_events,
               g.flagged_events
             FROM grouped g
             JOIN filtered f ON f.id = g.latest_id
             LEFT JOIN filtered fd ON fd.id = g.latest_domain_id
             ORDER BY g.requests_today DESC, g.requests_yesterday DESC, g.requests_month DESC, g.latest_id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $rowsOk = ($result['ok'] ?? false) && $countOk;
        $rawRows = ($result['ok'] ?? false)
            ? ($this->edgeShield->parseWranglerJson((string) ($result['output'] ?? ''))[0]['results'] ?? [])
            : [];

        $rows = array_map(function ($row): array {
            $safeRow = is_array($row) ? $row : [];
            $safeRow['domain'] = $this->resolveLogDomain($safeRow);
            $safeRow['requests'] = (int) ($safeRow['requests'] ?? 0);
            $safeRow['risk_score'] = isset($safeRow['risk_score']) ? (int) $safeRow['risk_score'] : null;
            $safeRow['requests_today'] = (int) ($safeRow['requests_today'] ?? 0);
            $safeRow['requests_yesterday'] = (int) ($safeRow['requests_yesterday'] ?? 0);
            $safeRow['requests_month'] = (int) ($safeRow['requests_month'] ?? 0);
            $safeRow['solved_or_passed_events'] = (int) ($safeRow['solved_or_passed_events'] ?? 0);
            $safeRow['flagged_events'] = (int) ($safeRow['flagged_events'] ?? 0);
            $safeRow['prefer_block_action'] = $safeRow['solved_or_passed_events'] > 0 && $safeRow['flagged_events'] === 0;

            $decodedPaths = json_decode((string) ($safeRow['recent_paths_json'] ?? '[]'), true);
            $recentPaths = [];
            if (is_array($decodedPaths)) {
                foreach ($decodedPaths as $pathItem) {
                    $pathText = trim((string) $pathItem);
                    $recentPaths[] = $pathText !== '' ? $pathText : '-';
                }
            }
            if (count($recentPaths) === 0) {
                $fallbackPath = trim((string) ($safeRow['target_path'] ?? ''));
                if ($fallbackPath !== '') {
                    $recentPaths[] = $fallbackPath;
                }
            }

            $safeRow['recent_paths'] = array_slice($recentPaths, 0, 50);
            $safeRow['top_paths'] = array_slice($safeRow['recent_paths'], 0, 2);

            return $safeRow;
        }, $rawRows);

        $paginator = new LengthAwarePaginator(
            $rows,
            $total,
            $perPage,
            $page,
            [
                'path' => route('logs.index'),
                'query' => $request->query(),
            ]
        );

        return view('logs.index', [
            'logs' => $paginator,
            'error' => $rowsOk
                ? null
                : (($result['error'] ?? '') ?: (($countResult['error'] ?? '') ?: 'Failed to load logs')),
            'eventType' => $event,
            'domainName' => $domain,
            'ipAddress' => $ipAddress,
            'domainOptions' => $filterOptions['domains'] ?? [],
            'eventTypeOptions' => $filterOptions['events'] ?? [],
        ]);
    }

    public function allowIp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ip' => ['required', 'ip'],
            'domain' => ['required', 'string', 'max:255'],
        ]);

        $ip = trim((string) $validated['ip']);
        $domain = trim((string) $validated['domain']);
        if ($domain === '' || $domain === '-') {
            return back()->with('error', 'Cannot allow this IP because domain is missing for this log row.');
        }

        $result = $this->edgeShield->allowIpViaWorkerAdmin(
            $domain,
            $ip,
            24,
            'dashboard security logs allow'
        );

        if (!($result['ok'] ?? false)) {
            return back()->with('error', (string) ($result['error'] ?? 'Failed to allow IP via worker admin.'));
        }

        $status = $this->edgeShield->getIpAdminStatusViaWorkerAdmin($domain, $ip);
        if (!($status['ok'] ?? false)) {
            return back()->with('error', 'IP was allow-listed, but status verification failed: '.((string) ($status['error'] ?? 'unknown error')));
        }
        $isAllowed = (bool) (($status['status']['allowed'] ?? false));
        $isBanned = (bool) (($status['status']['banned'] ?? false));
        if (!$isAllowed || $isBanned) {
            return back()->with('error', 'Allow action did not stabilize as expected (allowed=true, banned=false).');
        }

        $deleteResult = $this->edgeShield->queryD1(
            "DELETE FROM security_logs
             WHERE ip_address = '".str_replace("'", "''", $ip)."'"
        );

        if (!($deleteResult['ok'] ?? false)) {
            return back()->with('error', 'IP was allow-listed, but failed to reset visit counters: '.((string) ($deleteResult['error'] ?? 'unknown error')));
        }

        return back()->with('status', 'IP '.$ip.' was allow-listed, unbanned, and its visit counters were reset.');
    }

    public function blockIp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ip' => ['required', 'ip'],
            'domain' => ['required', 'string', 'max:255'],
        ]);

        $ip = trim((string) $validated['ip']);
        $domain = trim((string) $validated['domain']);
        if ($domain === '' || $domain === '-') {
            return back()->with('error', 'Cannot block this IP because domain is missing for this log row.');
        }

        $result = $this->edgeShield->blockIpViaWorkerAdmin(
            $domain,
            $ip,
            24,
            'dashboard security logs block'
        );

        if (!($result['ok'] ?? false)) {
            return back()->with('error', (string) ($result['error'] ?? 'Failed to block IP via worker admin.'));
        }

        $status = $this->edgeShield->getIpAdminStatusViaWorkerAdmin($domain, $ip);
        if (!($status['ok'] ?? false)) {
            return back()->with('error', 'IP was blocked, but status verification failed: '.((string) ($status['error'] ?? 'unknown error')));
        }
        $isAllowed = (bool) (($status['status']['allowed'] ?? false));
        $isBanned = (bool) (($status['status']['banned'] ?? false));
        if ($isAllowed || !$isBanned) {
            return back()->with('error', 'Block action did not stabilize as expected (allowed=false, banned=true).');
        }

        return back()->with('status', 'IP '.$ip.' was blocked on '.$domain.' for up to 24 hours.');
    }

    private function resolveLogDomain(array $row): string
    {
        $stored = trim((string) ($row['domain_name'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }

        $details = (string) ($row['details'] ?? '');
        if ($details !== '') {
            $decoded = json_decode($details, true);
            if (is_array($decoded)) {
                foreach (['domain', 'domain_name', 'host', 'hostname'] as $key) {
                    $value = trim((string) ($decoded[$key] ?? ''));
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        $targetPath = trim((string) ($row['target_path'] ?? ''));
        if ($targetPath !== '' && preg_match('#^https?://#i', $targetPath) === 1) {
            $host = parse_url($targetPath, PHP_URL_HOST);
            if (is_string($host) && trim($host) !== '') {
                return trim($host);
            }
        }

        return '-';
    }
}
