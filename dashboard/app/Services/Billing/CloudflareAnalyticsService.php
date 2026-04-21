<?php

namespace App\Services\Billing;

use App\Services\EdgeShield\CloudflareApiClient;
use App\Services\EdgeShield\EdgeShieldConfig;
use Carbon\CarbonInterface;

class CloudflareAnalyticsService
{
    /**
     * @var array<int, string>
     */
    private const SECURITY_ACTIONS = ['block', 'challenge', 'js_challenge', 'managed_challenge'];

    public function __construct(
        private readonly CloudflareApiClient $cloudflare,
        private readonly EdgeShieldConfig $config
    ) {}

    /**
     * @param  array<int, string>  $hostnames
     * @return array{ok:bool,error:?string,total:int,by_hostname:array<string,int>}
     */
    public function getBotRequestsUsage(array $hostnames, CarbonInterface $start, CarbonInterface $end): array
    {
        $normalizedHostnames = $this->normalizeHostnames($hostnames);
        if ($normalizedHostnames === []) {
            return [
                'ok' => true,
                'error' => null,
                'total' => 0,
                'by_hostname' => [],
            ];
        }

        $zoneId = $this->config->saasZoneId();
        if ($zoneId === null) {
            return [
                'ok' => false,
                'error' => 'Cloudflare Zone ID is missing. Add CLOUDFLARE_ZONE_ID in Settings.',
                'total' => 0,
                'by_hostname' => [],
            ];
        }

        $query = <<<'GRAPHQL'
query BillingBotUsage($zoneTag: string, $limit: Int, $filter: HttpRequestsAdaptiveGroupsFilter_InputObject) {
  viewer {
    zones(filter: { zoneTag: $zoneTag }) {
      botUsage: httpRequestsAdaptiveGroups(
        limit: $limit
        filter: $filter
        orderBy: [count_DESC]
      ) {
        count
        dimensions {
          clientRequestHTTPHost
        }
      }
    }
  }
}
GRAPHQL;

        $response = $this->cloudflare->graphql($query, [
            'zoneTag' => $zoneId,
            'limit' => count($normalizedHostnames),
            'filter' => [
                'datetime_geq' => $start->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'datetime_lt' => $end->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'requestSource' => 'eyeball',
                'clientRequestHTTPHost_in' => $normalizedHostnames,
                'securityAction_in' => self::SECURITY_ACTIONS,
            ],
        ]);

        if (! ($response['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => $response['error'] ?? 'Cloudflare Analytics request failed.',
                'total' => 0,
                'by_hostname' => [],
            ];
        }

        $rows = $response['data']['viewer']['zones'][0]['botUsage'] ?? [];
        if (! is_array($rows)) {
            return [
                'ok' => false,
                'error' => 'Unexpected Cloudflare Analytics payload.',
                'total' => 0,
                'by_hostname' => [],
            ];
        }

        $counts = array_fill_keys($normalizedHostnames, 0);

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $hostname = strtolower(trim((string) ($row['dimensions']['clientRequestHTTPHost'] ?? '')));
            if ($hostname === '' || ! array_key_exists($hostname, $counts)) {
                continue;
            }

            $counts[$hostname] = (int) ($row['count'] ?? 0);
        }

        return [
            'ok' => true,
            'error' => null,
            'total' => array_sum($counts),
            'by_hostname' => $counts,
        ];
    }

    /**
     * @param  array<int, string>  $hostnames
     * @return array<int, string>
     */
    private function normalizeHostnames(array $hostnames): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $hostname): string => strtolower(trim($hostname)),
            $hostnames
        ))));
    }
}
