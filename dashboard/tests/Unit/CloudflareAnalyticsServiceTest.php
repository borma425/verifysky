<?php

namespace Tests\Unit;

use App\Services\Billing\CloudflareAnalyticsService;
use App\Services\EdgeShield\CloudflareApiClient;
use App\Services\EdgeShield\EdgeShieldConfig;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class CloudflareAnalyticsServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_builds_graphql_request_and_aggregates_counts_per_hostname(): void
    {
        $client = Mockery::mock(CloudflareApiClient::class);
        $config = Mockery::mock(EdgeShieldConfig::class);

        $config->shouldReceive('saasZoneId')->once()->andReturn('zone-123');
        $client->shouldReceive('graphql')
            ->once()
            ->with(
                Mockery::on(static fn (string $query): bool => str_contains($query, 'httpRequestsAdaptiveGroups')
                    && str_contains($query, 'clientRequestHTTPHost')),
                Mockery::on(static function (array $variables): bool {
                    return $variables['zoneTag'] === 'zone-123'
                        && $variables['limit'] === 2
                        && $variables['filter']['requestSource'] === 'eyeball'
                        && $variables['filter']['clientRequestHTTPHost_in'] === ['example.com', 'www.example.com']
                        && $variables['filter']['securityAction_in'] === ['block', 'challenge', 'js_challenge', 'managed_challenge']
                        && $variables['filter']['datetime_geq'] === '2026-04-01T00:00:00Z'
                        && $variables['filter']['datetime_lt'] === '2026-05-01T00:00:00Z';
                })
            )
            ->andReturn([
                'ok' => true,
                'data' => [
                    'viewer' => [
                        'zones' => [[
                            'botUsage' => [
                                [
                                    'count' => 9,
                                    'dimensions' => ['clientRequestHTTPHost' => 'example.com'],
                                ],
                            ],
                        ]],
                    ],
                ],
            ]);

        $service = new CloudflareAnalyticsService($client, $config);
        $usage = $service->getBotRequestsUsage(
            ['example.com', 'www.example.com'],
            CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-05-01 00:00:00', 'UTC')
        );

        $this->assertTrue($usage['ok']);
        $this->assertSame(9, $usage['total']);
        $this->assertSame([
            'example.com' => 9,
            'www.example.com' => 0,
        ], $usage['by_hostname']);
    }

    public function test_it_returns_error_when_zone_id_is_missing(): void
    {
        $client = Mockery::mock(CloudflareApiClient::class);
        $config = Mockery::mock(EdgeShieldConfig::class);

        $config->shouldReceive('saasZoneId')->once()->andReturnNull();
        $client->shouldReceive('graphql')->never();

        $service = new CloudflareAnalyticsService($client, $config);
        $usage = $service->getBotRequestsUsage(
            ['example.com'],
            CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-05-01 00:00:00', 'UTC')
        );

        $this->assertFalse($usage['ok']);
        $this->assertSame(0, $usage['total']);
        $this->assertSame([], $usage['by_hostname']);
    }
}
