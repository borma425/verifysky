<?php

namespace App\Services\Billing;

use App\Models\CloudflareBillingSnapshot;
use App\Models\CloudflareCostDaily;
use App\Models\CloudflareUsageDaily;
use App\Models\Tenant;
use App\Services\EdgeShield\CloudflareApiClient;
use App\Services\EdgeShield\EdgeShieldConfig;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class CloudflareCostAttributionService
{
    public function __construct(private readonly EdgeShieldConfig $config) {}

    public function storageReady(): bool
    {
        return Schema::hasColumn('tenants', 'is_vip')
            && Schema::hasTable('cloudflare_usage_daily')
            && Schema::hasTable('cloudflare_cost_daily')
            && Schema::hasTable('cloudflare_billing_snapshots')
            && Schema::hasColumn('cloudflare_usage_daily', 'outcome')
            && Schema::hasColumn('cloudflare_cost_daily', 'outcome');
    }

    /**
     * @return array{ok:bool,error:?string,rows:int,dry_run:bool}
     */
    public function syncUsage(CarbonInterface $start, CarbonInterface $end, string $environment = 'production', bool $dryRun = false): array
    {
        if (! $this->storageReady()) {
            return ['ok' => false, 'error' => 'Cloudflare cost attribution storage is not ready.', 'rows' => 0, 'dry_run' => $dryRun];
        }

        $usage = $this->queryWorkersAnalyticsEngine(
            CarbonImmutable::instance($start)->utc(),
            CarbonImmutable::instance($end)->utc(),
            $environment
        );
        if (! $usage['ok']) {
            return ['ok' => false, 'error' => $usage['error'], 'rows' => 0, 'dry_run' => $dryRun];
        }

        $rows = $usage['rows'];
        if ($dryRun) {
            return ['ok' => true, 'error' => null, 'rows' => count($rows), 'dry_run' => true];
        }

        $now = CarbonImmutable::now('UTC')->toDateTimeString();

        DB::transaction(function () use ($rows, $now, $environment): void {
            foreach ($rows as $row) {
                $tenantId = (int) ($row['tenant_id'] ?? 0);
                $domainName = strtolower(trim((string) ($row['domain_name'] ?? '')));
                $usageDate = $this->usageDateForStorage($row['usage_date'] ?? '');
                $outcome = $this->normalizeOutcome($row['outcome'] ?? 'legacy');

                if ($tenantId <= 0 || $domainName === '' || $usageDate === '') {
                    continue;
                }

                if (! Tenant::query()->whereKey($tenantId)->exists()) {
                    continue;
                }

                $usageValues = [
                    'requests' => $this->integerMetric($row['requests'] ?? 0),
                    'd1_rows_read' => $this->integerMetric($row['d1_rows_read'] ?? 0),
                    'd1_rows_written' => $this->integerMetric($row['d1_rows_written'] ?? 0),
                    'd1_query_count' => $this->integerMetric($row['d1_query_count'] ?? 0),
                    'kv_reads' => $this->integerMetric($row['kv_reads'] ?? 0),
                    'kv_writes' => $this->integerMetric($row['kv_writes'] ?? 0),
                    'kv_deletes' => $this->integerMetric($row['kv_deletes'] ?? 0),
                    'kv_lists' => $this->integerMetric($row['kv_lists'] ?? 0),
                    'kv_write_bytes' => $this->integerMetric($row['kv_write_bytes'] ?? 0),
                    'pass_d1_writes' => $this->integerMetric($row['pass_d1_writes'] ?? 0),
                    'pass_kv_writes' => $this->integerMetric($row['pass_kv_writes'] ?? 0),
                    'pass_kv_reads' => $this->integerMetric($row['pass_kv_reads'] ?? 0),
                    'pass_config_cache_hit' => $this->integerMetric($row['pass_config_cache_hit'] ?? 0),
                    'pass_config_cache_miss' => $this->integerMetric($row['pass_config_cache_miss'] ?? 0),
                    'last_synced_at' => $now,
                ];

                if ($outcome !== 'legacy') {
                    CloudflareUsageDaily::query()
                        ->where('usage_date', $usageDate)
                        ->where('tenant_id', $tenantId)
                        ->where('domain_name', $domainName)
                        ->where('environment', $environment)
                        ->where('outcome', 'legacy')
                        ->delete();

                    CloudflareCostDaily::query()
                        ->where('usage_date', $usageDate)
                        ->where('tenant_id', $tenantId)
                        ->where('domain_name', $domainName)
                        ->where('environment', $environment)
                        ->where('outcome', 'legacy')
                        ->delete();
                }

                CloudflareUsageDaily::query()->updateOrCreate(
                    [
                        'usage_date' => $usageDate,
                        'tenant_id' => $tenantId,
                        'domain_name' => $domainName,
                        'environment' => $environment,
                        'outcome' => $outcome,
                    ],
                    $usageValues
                );

                CloudflareCostDaily::query()->updateOrCreate(
                    [
                        'usage_date' => $usageDate,
                        'tenant_id' => $tenantId,
                        'domain_name' => $domainName,
                        'environment' => $environment,
                        'outcome' => $outcome,
                    ],
                    array_merge($this->estimateCosts($usageValues), [
                        'last_synced_at' => $now,
                    ])
                );
            }
        });

        return ['ok' => true, 'error' => null, 'rows' => count($rows), 'dry_run' => false];
    }

    /**
     * @return array{ok:bool,error:?string,rows:array<int,array<string,mixed>>}
     */
    public function queryWorkersAnalyticsEngine(CarbonImmutable $start, CarbonImmutable $end, string $environment): array
    {
        $accountId = $this->config->cloudflareAccountId();
        $token = $this->config->cloudflareApiToken();
        if ($accountId === null || $token === null) {
            return ['ok' => false, 'error' => 'Cloudflare account ID or API token is missing.', 'rows' => []];
        }

        $dataset = $this->datasetName($environment);
        $sql = $this->usageSql($dataset, $start, $end, $environment);

        try {
            $response = Http::timeout(45)
                ->acceptJson()
                ->withToken($token)
                ->withBody($sql, 'text/plain')
                ->post(CloudflareApiClient::API_BASE.'/accounts/'.$accountId.'/analytics_engine/sql');
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Workers Analytics Engine SQL request failed.', 'rows' => []];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'Workers Analytics Engine SQL HTTP error: '.$response->status(), 'rows' => []];
        }

        return ['ok' => true, 'error' => null, 'rows' => $this->parseSqlRows((string) $response->body())];
    }

    /**
     * @return array{summary:array<string,mixed>,domains:array<int,array<string,mixed>>,resources:array<string,string>,last_synced_at:?CarbonImmutable}
     */
    public function summaryForTenant(Tenant $tenant, ?CarbonInterface $start = null, ?CarbonInterface $end = null, string $environment = 'production'): array
    {
        if (! $this->storageReady()) {
            return $this->emptySummary();
        }

        $startAt = $start ? CarbonImmutable::instance($start)->utc() : CarbonImmutable::parse((string) $tenant->billing_start_at, 'UTC')->utc();
        $endAt = $end ? CarbonImmutable::instance($end)->utc() : CarbonImmutable::now('UTC');

        $costRows = CloudflareCostDaily::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('environment', $environment)
            ->whereDate('usage_date', '>=', $startAt->toDateString())
            ->whereDate('usage_date', '<', $endAt->toDateString())
            ->get();

        $usageRows = CloudflareUsageDaily::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('environment', $environment)
            ->whereDate('usage_date', '>=', $startAt->toDateString())
            ->whereDate('usage_date', '<', $endAt->toDateString())
            ->get();

        $usageByDomain = $usageRows->groupBy('domain_name');
        $costsByDomainOutcome = $costRows->groupBy(fn (CloudflareCostDaily $row): string => $row->domain_name.'|'.$row->outcome);
        $domains = $costRows
            ->groupBy('domain_name')
            ->map(function (Collection $rows, string $domain) use ($usageByDomain): array {
                $usage = $usageByDomain->get($domain, collect());

                return [
                    'domain_name' => $domain,
                    'requests' => (int) $usage->sum('requests'),
                    'd1_rows_read' => (int) $usage->sum('d1_rows_read'),
                    'd1_rows_written' => (int) $usage->sum('d1_rows_written'),
                    'kv_operations' => (int) $usage->sum('kv_reads') + (int) $usage->sum('kv_writes') + (int) $usage->sum('kv_deletes') + (int) $usage->sum('kv_lists'),
                    'estimated_cost_usd' => (float) $rows->sum('total_estimated_cost_usd'),
                    'final_reconciled_cost_usd' => $rows->whereNotNull('final_reconciled_cost_usd')->isNotEmpty()
                        ? (float) $rows->sum('final_reconciled_cost_usd')
                        : null,
                ];
            })
            ->sortByDesc('estimated_cost_usd')
            ->values()
            ->all();

        $outcomes = $usageRows
            ->groupBy(fn (CloudflareUsageDaily $row): string => $row->domain_name.'|'.$row->outcome)
            ->map(function (Collection $rows, string $key) use ($costsByDomainOutcome): array {
                [$domain, $outcome] = explode('|', $key, 2);
                $costs = $costsByDomainOutcome->get($key, collect());
                $requests = (int) $rows->sum('requests');
                $estimatedCost = (float) $costs->sum('total_estimated_cost_usd');

                return [
                    'domain_name' => $domain,
                    'outcome' => $outcome,
                    'requests' => $requests,
                    'estimated_cost_usd' => $estimatedCost,
                    'cost_per_million_requests_usd' => $requests > 0 ? round(($estimatedCost / $requests) * 1_000_000, 6) : 0.0,
                    'd1_rows_read' => (int) $rows->sum('d1_rows_read'),
                    'd1_rows_written' => (int) $rows->sum('d1_rows_written'),
                    'kv_reads' => (int) $rows->sum('kv_reads'),
                    'kv_writes' => (int) $rows->sum('kv_writes'),
                    'kv_write_bytes' => (int) $rows->sum('kv_write_bytes'),
                    'pass_d1_writes' => (int) $rows->sum('pass_d1_writes'),
                    'pass_kv_writes' => (int) $rows->sum('pass_kv_writes'),
                    'pass_kv_reads' => (int) $rows->sum('pass_kv_reads'),
                    'pass_config_cache_hit' => (int) $rows->sum('pass_config_cache_hit'),
                    'pass_config_cache_miss' => (int) $rows->sum('pass_config_cache_miss'),
                ];
            })
            ->sortBy([['domain_name', 'asc'], ['outcome', 'asc']])
            ->values()
            ->all();

        $lastSynced = $costRows->max('last_synced_at');

        return [
            'summary' => [
                'requests' => (int) $usageRows->sum('requests'),
                'estimated_cost_usd' => (float) $costRows->sum('total_estimated_cost_usd'),
                'final_reconciled_cost_usd' => $costRows->whereNotNull('final_reconciled_cost_usd')->isNotEmpty()
                    ? (float) $costRows->sum('final_reconciled_cost_usd')
                    : null,
            ],
            'domains' => $domains,
            'outcomes' => $outcomes,
            'resources' => [
                'workers' => $this->formatMoney((float) $costRows->sum('workers_requests_cost_usd') + (float) $costRows->sum('workers_cpu_cost_usd')),
                'd1' => $this->formatMoney((float) $costRows->sum('d1_cost_usd')),
                'kv' => $this->formatMoney((float) $costRows->sum('kv_cost_usd')),
                'wae' => $this->formatMoney((float) $costRows->sum('wae_cost_usd')),
            ],
            'last_synced_at' => $lastSynced ? CarbonImmutable::parse((string) $lastSynced, 'UTC')->utc() : null,
        ];
    }

    /**
     * @return array{ok:bool,error:?string,total_estimated:float,total_actual:float,updated:int}
     */
    public function reconcilePeriod(CarbonInterface $periodStart, CarbonInterface $periodEnd, float $actualCostUsd, string $environment = 'production', string $resource = 'cloudflare_total', string $source = 'manual_reconciliation'): array
    {
        if (! $this->storageReady()) {
            return ['ok' => false, 'error' => 'Cloudflare cost attribution storage is not ready.', 'total_estimated' => 0.0, 'total_actual' => $actualCostUsd, 'updated' => 0];
        }

        $start = CarbonImmutable::instance($periodStart)->utc()->startOfDay();
        $end = CarbonImmutable::instance($periodEnd)->utc()->startOfDay();
        $rows = CloudflareCostDaily::query()
            ->where('environment', $environment)
            ->whereDate('usage_date', '>=', $start->toDateString())
            ->whereDate('usage_date', '<', $end->toDateString())
            ->get();

        $totalEstimated = (float) $rows->sum('total_estimated_cost_usd');
        if ($rows->isEmpty() || $totalEstimated <= 0.0) {
            return ['ok' => false, 'error' => 'No estimated Cloudflare costs exist for this period.', 'total_estimated' => $totalEstimated, 'total_actual' => $actualCostUsd, 'updated' => 0];
        }

        $remaining = round($actualCostUsd, 6);
        $lastId = (int) $rows->max('id');
        $updated = 0;

        DB::transaction(function () use ($rows, $totalEstimated, $actualCostUsd, $remaining, $lastId, $environment, $resource, $source, $start, $end, &$updated): void {
            foreach ($rows as $row) {
                $allocated = (int) $row->getKey() === $lastId
                    ? $remaining
                    : round(((float) $row->total_estimated_cost_usd / $totalEstimated) * $actualCostUsd, 6);

                $row->forceFill(['final_reconciled_cost_usd' => $allocated])->save();
                $remaining = round($remaining - $allocated, 6);
                $updated++;
            }

            CloudflareBillingSnapshot::query()->updateOrCreate(
                [
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'environment' => $environment,
                    'source' => $source,
                    'resource' => $resource,
                ],
                [
                    'currency' => 'USD',
                    'amount_usd' => $actualCostUsd,
                    'usage_quantity' => $totalEstimated,
                    'raw_payload' => ['allocated_rows' => $updated],
                    'final_reconciled_at' => CarbonImmutable::now('UTC')->toDateTimeString(),
                ]
            );
        });

        return ['ok' => true, 'error' => null, 'total_estimated' => $totalEstimated, 'total_actual' => $actualCostUsd, 'updated' => $updated];
    }

    /**
     * @return array{ok:bool,error:?string,total:float,rows:array<int,array<string,mixed>>}
     */
    public function fetchPaygoCostForPeriod(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $accountId = $this->config->cloudflareAccountId();
        $token = $this->config->cloudflareApiToken();
        if ($accountId === null || $token === null) {
            return ['ok' => false, 'error' => 'Cloudflare account ID or API token is missing.', 'total' => 0.0, 'rows' => []];
        }

        try {
            $response = Http::timeout(45)
                ->acceptJson()
                ->withToken($token)
                ->get(CloudflareApiClient::API_BASE.'/accounts/'.$accountId.'/billing/usage/paygo');
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'Cloudflare PayGo billing request failed.', 'total' => 0.0, 'rows' => []];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'Cloudflare PayGo billing HTTP error: '.$response->status(), 'total' => 0.0, 'rows' => []];
        }

        $payload = $response->json();
        $rows = is_array($payload) && array_is_list($payload)
            ? $payload
            : (is_array($payload) && is_array($payload['result'] ?? null) ? $payload['result'] : []);

        $start = CarbonImmutable::instance($periodStart)->utc();
        $end = CarbonImmutable::instance($periodEnd)->utc();
        $matched = [];
        $total = 0.0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $chargeStart = $this->dateFromPayload($row['ChargePeriodStart'] ?? null);
            $chargeEnd = $this->dateFromPayload($row['ChargePeriodEnd'] ?? null);
            if ($chargeStart === null || $chargeEnd === null || $chargeEnd->lte($start) || $chargeStart->gte($end)) {
                continue;
            }

            $matched[] = $row;
            $total += (float) ($row['ContractedCost'] ?? 0);
        }

        return ['ok' => true, 'error' => null, 'total' => round($total, 6), 'rows' => $matched];
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, float>
     */
    public function estimateCosts(array $usage): array
    {
        $rates = (array) config('cloudflare_costs.rates', []);
        $workers = $this->perMillion($usage['requests'] ?? 0, (float) ($rates['workers_requests_per_million'] ?? 0.30));
        $d1 = $this->perMillion($usage['d1_rows_read'] ?? 0, (float) ($rates['d1_rows_read_per_million'] ?? 0.001))
            + $this->perMillion($usage['d1_rows_written'] ?? 0, (float) ($rates['d1_rows_written_per_million'] ?? 1.00));
        $kv = $this->perMillion($usage['kv_reads'] ?? 0, (float) ($rates['kv_reads_per_million'] ?? 0.50))
            + $this->perMillion($usage['kv_writes'] ?? 0, (float) ($rates['kv_writes_per_million'] ?? 5.00))
            + $this->perMillion($usage['kv_deletes'] ?? 0, (float) ($rates['kv_deletes_per_million'] ?? 5.00))
            + $this->perMillion($usage['kv_lists'] ?? 0, (float) ($rates['kv_lists_per_million'] ?? 5.00));
        $wae = $this->perMillion($usage['requests'] ?? 0, 0.25);

        return [
            'workers_requests_cost_usd' => round($workers, 6),
            'workers_cpu_cost_usd' => 0.0,
            'd1_cost_usd' => round($d1, 6),
            'kv_cost_usd' => round($kv, 6),
            'wae_cost_usd' => round($wae, 6),
            'total_estimated_cost_usd' => round($workers + $d1 + $kv + $wae, 6),
        ];
    }

    public function formatMoney(float $amount): string
    {
        if ($amount > 0.0 && $amount < 0.01) {
            return '< $0.01';
        }

        return '$'.number_format($amount, 2);
    }

    private function datasetName(string $environment): string
    {
        return $environment === 'staging'
            ? (string) config('cloudflare_costs.wae_staging_dataset', 'verifysky_usage_staging')
            : (string) config('cloudflare_costs.wae_dataset', 'verifysky_usage');
    }

    private function usageSql(string $dataset, CarbonImmutable $start, CarbonImmutable $end, string $environment): string
    {
        return sprintf(
            "SELECT
  toDate(timestamp) AS usage_date,
  index1 AS tenant_id,
  blob1 AS domain_name,
  blob2 AS environment,
  if(blob3 = '', 'legacy', blob3) AS outcome,
  SUM(_sample_interval * double1) AS requests,
  SUM(_sample_interval * double2) AS d1_rows_read,
  SUM(_sample_interval * double3) AS d1_rows_written,
  SUM(_sample_interval * double4) AS d1_query_count,
  SUM(_sample_interval * double5) AS kv_reads,
  SUM(_sample_interval * double6) AS kv_writes,
  SUM(_sample_interval * double7) AS kv_deletes,
  SUM(_sample_interval * double8) AS kv_lists,
  SUM(_sample_interval * double9) AS kv_write_bytes,
  SUM(_sample_interval * double10) AS pass_d1_writes,
  SUM(_sample_interval * double11) AS pass_kv_writes,
  SUM(_sample_interval * double12) AS pass_kv_reads,
  SUM(_sample_interval * double13) AS pass_config_cache_hit,
  SUM(_sample_interval * double14) AS pass_config_cache_miss
FROM %s
WHERE timestamp >= toDateTime('%s')
  AND timestamp < toDateTime('%s')
  AND blob2 = '%s'
GROUP BY usage_date, tenant_id, domain_name, environment, outcome
ORDER BY usage_date ASC",
            $this->quoteIdentifier($dataset),
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
            str_replace("'", "''", $environment)
        );
    }

    private function quoteIdentifier(string $identifier): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $identifier) ?: 'verifysky_usage';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseSqlRows(string $body): array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            if (array_is_list($decoded)) {
                return array_values(array_filter($decoded, 'is_array'));
            }

            foreach (['data', 'result', 'rows'] as $key) {
                if (isset($decoded[$key]) && is_array($decoded[$key])) {
                    return array_values(array_filter($decoded[$key], 'is_array'));
                }
            }
        }

        $rows = [];
        foreach (preg_split('/\R/', trim($body)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function integerMetric(mixed $value): int
    {
        return max(0, (int) round((float) $value));
    }

    private function normalizeOutcome(mixed $value): string
    {
        $outcome = strtolower(trim((string) $value));
        $allowed = ['pass', 'challenge_issued', 'challenge_passed', 'challenge_failed', 'blocked', 'legacy'];

        return in_array($outcome, $allowed, true) ? $outcome : 'legacy';
    }

    private function usageDateForStorage(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc()->startOfDay()->toDateTimeString();
        } catch (\Throwable) {
            return '';
        }
    }

    private function perMillion(mixed $quantity, float $rate): float
    {
        return (((float) $quantity) / 1_000_000) * $rate;
    }

    private function dateFromPayload(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function emptySummary(): array
    {
        return [
            'summary' => [
                'requests' => 0,
                'estimated_cost_usd' => 0.0,
                'final_reconciled_cost_usd' => null,
            ],
            'domains' => [],
            'outcomes' => [],
            'resources' => [
                'workers' => '$0.00',
                'd1' => '$0.00',
                'kv' => '$0.00',
                'wae' => '$0.00',
            ],
            'last_synced_at' => null,
        ];
    }
}
