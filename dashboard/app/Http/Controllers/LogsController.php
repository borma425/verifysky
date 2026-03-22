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
               f.ip_address,
               f.asn,
               f.country,
               f.target_path,
               f.details,
               f.created_at,
               g.requests
             FROM grouped g
             JOIN filtered f ON f.id = g.latest_id
             LEFT JOIN filtered fd ON fd.id = g.latest_domain_id
             ORDER BY g.requests DESC, g.latest_id DESC
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

        return back()->with('status', 'IP '.$ip.' was allow-listed and unbanned on '.$domain.'.');
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
