<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Billing\TenantBillingStatusService;
use App\Services\EdgeShieldService;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly EdgeShieldService $edgeShield,
        private readonly TenantBillingStatusService $tenantBillingStatus
    ) {}

    public function index(): View
    {
        $stats = Cache::remember($this->statsCacheKey(), 300, fn (): array => $this->fetchOverviewStats());

        $billingStatus = $this->billingStatusForDashboard();

        return view('dashboard.index', [
            'stats' => $stats,
            'billingStatus' => $billingStatus,
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

    private function billingStatusForDashboard(): ?array
    {
        if ((bool) session('is_admin')) {
            return null;
        }

        $tenantId = trim((string) session('current_tenant_id'));
        if ($tenantId === '') {
            return null;
        }

        $tenant = Tenant::query()->find($tenantId);

        return $tenant instanceof Tenant ? $this->tenantBillingStatus->forTenant($tenant) : null;
    }
}
