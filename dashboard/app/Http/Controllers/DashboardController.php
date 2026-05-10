<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Billing\CloudflareCostAttributionService;
use App\Services\Billing\TenantBillingStatusService;
use App\Services\EdgeShieldService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly TenantBillingStatusService $tenantBillingStatus,
        private readonly CloudflareCostAttributionService $cloudflareCosts
    ) {}

    public function index(): View
    {
        $stats = Cache::remember($this->statsCacheKey(), 300, fn (): array => $this->fetchOverviewStats());

        $tenant = $this->tenantForDashboard();
        $billingStatus = $tenant instanceof Tenant ? $this->tenantBillingStatus->forTenant($tenant) : null;
        $edgeEfficiency = $tenant instanceof Tenant ? $this->edgeEfficiencyForDashboard($tenant) : null;

        return view('dashboard.index', [
            'stats' => $stats,
            'billingStatus' => $billingStatus,
            'edgeEfficiency' => $edgeEfficiency,
        ]);
    }

    private function fetchOverviewStats(): array
    {
        $defaultStats = $this->defaultStats();
        $domainScope = $this->dashboardDomainScope();
        if ($domainScope === false) {
            return $defaultStats;
        }

        $activeDomainWhere = $domainScope !== ''
            ? " AND domain_name IN ({$domainScope})"
            : '';
        $logsWhere = $domainScope !== ''
            ? " AND domain_name IN ({$domainScope})"
            : '';

        $sql = "
            SELECT COUNT(*) as active_domains FROM domain_configs WHERE status = 'active'{$activeDomainWhere};

            SELECT
                SUM(CASE WHEN event_type IN ('challenge_failed', 'hard_block', 'turnstile_failed', 'replay_detected', 'session_rejected') THEN 1 ELSE 0 END) as total_attacks_today,
                SUM(CASE WHEN event_type IN ('challenge_solved', 'session_created') THEN 1 ELSE 0 END) as total_visitors_today
            FROM security_logs
            WHERE datetime(created_at) >= datetime('now', 'start of day'){$logsWhere};

            SELECT country, COUNT(*) as attack_count
            FROM security_logs
            WHERE event_type IN ('challenge_failed', 'hard_block', 'turnstile_failed', 'replay_detected', 'session_rejected')
            AND country IS NOT NULL AND country != '' AND country != 'T1'
            AND datetime(created_at) >= datetime('now', 'start of day'){$logsWhere}
            GROUP BY country
            ORDER BY attack_count DESC
            LIMIT 5;

            SELECT domain_name, COUNT(*) as attack_count
            FROM security_logs
            WHERE event_type IN ('challenge_failed', 'hard_block', 'turnstile_failed', 'replay_detected', 'session_rejected')
            AND domain_name IS NOT NULL AND TRIM(domain_name) != ''{$logsWhere}
            AND datetime(created_at) >= datetime('now', 'start of day')
            GROUP BY domain_name
            ORDER BY attack_count DESC
            LIMIT 5;

            SELECT id, event_type, domain_name, ip_address, country, details, created_at
            FROM security_logs
            WHERE event_type IN ('hard_block', 'challenge_failed'){$logsWhere}
            ORDER BY id DESC
            LIMIT 6;
        ";

        $res = $this->edgeShield->queryD1($sql, 25);
        if (! $res['ok']) {
            return $defaultStats;
        }

        $parsed = $this->edgeShield->parseWranglerJson((string) ($res['output'] ?? ''));
        if (empty($parsed)) {
            return $defaultStats;
        }

        return [
            'active_domains' => (int) ($parsed[0]['results'][0]['active_domains'] ?? 0),
            'total_attacks_today' => (int) ($parsed[1]['results'][0]['total_attacks_today'] ?? 0),
            'total_visitors_today' => (int) ($parsed[1]['results'][0]['total_visitors_today'] ?? 0),
            'top_countries' => $parsed[2]['results'] ?? [],
            'top_domains' => $parsed[3]['results'] ?? [],
            'recent_critical' => $parsed[4]['results'] ?? [],
        ];
    }

    private function statsCacheKey(): string
    {
        if ((bool) session('is_admin')) {
            return 'dashboard:overview_stats:v2:admin';
        }

        $tenantId = trim((string) session('current_tenant_id'));
        $domains = $tenantId !== '' ? $this->tenantDomains($tenantId) : [];

        return 'dashboard:overview_stats:v2:tenant:'.$tenantId.':'.md5(implode('|', $domains));
    }

    private function dashboardDomainScope(): string|false
    {
        if ((bool) session('is_admin')) {
            return '';
        }

        $tenantId = trim((string) session('current_tenant_id'));
        if ($tenantId === '') {
            return false;
        }

        $domains = $this->tenantDomains($tenantId);
        if ($domains === []) {
            return false;
        }

        return implode(', ', array_map(fn (string $domain): string => "'".str_replace("'", "''", $domain)."'", $domains));
    }

    /**
     * @return array<int, string>
     */
    private function tenantDomains(string $tenantId): array
    {
        return TenantDomain::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->pluck('hostname')
            ->map(static fn (string $hostname): string => strtolower(trim($hostname)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function defaultStats(): array
    {
        return [
            'active_domains' => 0,
            'total_attacks_today' => 0,
            'total_visitors_today' => 0,
            'top_countries' => [],
            'top_domains' => [],
            'recent_critical' => [],
        ];
    }

    private function tenantForDashboard(): ?Tenant
    {
        if ((bool) session('is_admin')) {
            return null;
        }

        $tenantId = trim((string) session('current_tenant_id'));
        if ($tenantId === '') {
            return null;
        }

        $tenant = Tenant::query()->find($tenantId);

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function edgeEfficiencyForDashboard(Tenant $tenant): ?array
    {
        if (! $this->cloudflareCosts->storageReady()) {
            return null;
        }

        $now = CarbonImmutable::now('UTC');
        $summary = $this->cloudflareCosts->summaryForTenant(
            $tenant,
            $now->subDays(7)->startOfDay(),
            $now->addDay()->startOfDay(),
            'production'
        );

        $outcomes = collect($summary['outcomes'] ?? []);
        $totalRequests = (int) ($summary['summary']['requests'] ?? 0);
        if ($totalRequests <= 0) {
            return null;
        }

        $rollup = fn (string $outcome): array => $this->rollupOutcome(
            $outcomes->where('outcome', $outcome)->values()->all()
        );

        $pass = $rollup('pass');
        $challenge = $rollup('challenge_issued');
        $blocked = $rollup('blocked');
        $legacy = $rollup('legacy');
        $all = $this->rollupOutcome($outcomes->all());

        $cacheTotal = $pass['pass_config_cache_hit'] + $pass['pass_config_cache_miss'];

        return [
            'total_requests' => $totalRequests,
            'estimated_cost_usd' => (float) ($summary['summary']['estimated_cost_usd'] ?? 0.0),
            'cost_per_million_requests_usd' => $totalRequests > 0
                ? ((float) ($summary['summary']['estimated_cost_usd'] ?? 0.0) / $totalRequests) * 1_000_000
                : 0.0,
            'pass_kv_reads_per_request' => $pass['requests'] > 0 ? $pass['pass_kv_reads'] / $pass['requests'] : null,
            'pass_cache_hit_rate' => $cacheTotal > 0 ? ($pass['pass_config_cache_hit'] / $cacheTotal) * 100 : null,
            'd1_writes_per_1000_requests' => $all['requests'] > 0 ? ($all['d1_rows_written'] / $all['requests']) * 1000 : 0.0,
            'last_synced_at' => $summary['last_synced_at'] ?? null,
            'outcomes' => [
                'pass' => $pass,
                'challenge_issued' => $challenge,
                'blocked' => $blocked,
                'legacy' => $legacy,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function rollupOutcome(array $rows): array
    {
        $totals = [
            'requests' => 0,
            'd1_rows_read' => 0,
            'd1_rows_written' => 0,
            'kv_reads' => 0,
            'kv_writes' => 0,
            'pass_kv_reads' => 0,
            'pass_kv_writes' => 0,
            'pass_d1_writes' => 0,
            'pass_config_cache_hit' => 0,
            'pass_config_cache_miss' => 0,
        ];

        foreach ($rows as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (int) ($row[$key] ?? 0);
            }
        }

        return $totals;
    }
}
