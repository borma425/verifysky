<?php

namespace Tests\Feature;

use App\Services\EdgeShieldService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function bindServiceMock(): MockInterface
    {
        $mock = Mockery::mock(EdgeShieldService::class);
        $this->app->instance(EdgeShieldService::class, $mock);
        return $mock;
    }

    public function test_domains_index_renders_expected_action_buttons(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('ensureSecurityModeColumn')->once();
        $mock->shouldReceive('ensureThresholdsColumn')->once();
        $mock->shouldReceive('listDomains')->once()->andReturn([
            'ok' => true,
            'error' => null,
            'domains' => [[
                'domain_name' => 'example.com',
                'zone_id' => 'zone1',
                'cname_target' => 'customers.verifysky.com',
                'hostname_status' => 'active',
                'ssl_status' => 'active',
                'status' => 'active',
                'force_captcha' => 1,
                'security_mode' => 'balanced',
                'created_at' => '2026-03-22 00:00:00',
            ]],
        ]);

        $response = $this->withSession(['is_admin' => true])->get('/domains');

        $response->assertOk()
            ->assertSee('Customer hostname')
            ->assertSee('customers.verifysky.com')
            ->assertSee('Refresh Status')
            ->assertSee('Disable Forced CAPTCHA')
            ->assertSee('Pause')
            ->assertSee('Delete');
    }

    public function test_tuning_route_loads_domain_tuning_screen(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('ensureThresholdsColumn')->once();
        $mock->shouldReceive('getDomainConfig')->once()->andReturn([
            'ok' => true,
            'error' => null,
            'config' => [
                'domain_name' => 'example.com',
                'zone_id' => 'zone1',
                'status' => 'active',
                'force_captcha' => 1,
                'thresholds_json' => '{}',
            ],
        ]);

        $response = $this->withSession(['is_admin' => true])->get('/domains/example.com/tuning');

        $response->assertOk()->assertSee('Protection Tuning for example.com');
    }

    public function test_refresh_hostname_status_endpoint_works(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('refreshSaasCustomHostname')->once()->with('example.com')->andReturn([
            'ok' => true,
            'error' => null,
            'custom_hostname' => [],
        ]);

        $response = $this->withSession(['is_admin' => true])->post('/domains/example.com/sync-route');

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_disable_forced_captcha_button_endpoint_works(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')->once()->andReturn([
            'ok' => true,
            'output' => '',
            'error' => null,
        ]);

        $response = $this->withSession(['is_admin' => true])->post('/domains/example.com/force-captcha', [
            'force_captcha' => '0',
        ]);

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_pause_button_endpoint_works(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')->once()->andReturn([
            'ok' => true,
            'output' => '',
            'error' => null,
        ]);

        $response = $this->withSession(['is_admin' => true])->post('/domains/example.com/status', [
            'status' => 'paused',
        ]);

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_delete_button_endpoint_removes_domain_and_artifacts(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')->twice()->andReturn(
            [
                'ok' => true,
                'output' => 'ignored-read',
                'error' => null,
            ],
            [
                'ok' => true,
                'output' => 'ignored-delete',
                'error' => null,
            ]
        );
        $mock->shouldReceive('parseWranglerJson')->once()->andReturn([[
            'results' => [[
                'domain_name' => 'example.com',
                'custom_hostname_id' => 'custom-hostname-1',
            ]],
        ]]);
        $mock->shouldReceive('deleteSaasCustomHostname')->once()->with('custom-hostname-1')->andReturn([
            'ok' => true,
            'action' => 'deleted',
            'error' => null,
        ]);

        $response = $this->withSession(['is_admin' => true])->delete('/domains/example.com');

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_logs_page_shows_domain_column_and_pagination(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('ensureSecurityLogsDomainColumn')->once();
        $mock->shouldReceive('listIpFarmRules')->once()->andReturn([
            'ok' => true,
            'rules' => [],
        ]);
        $mock->shouldReceive('queryD1')->zeroOrMoreTimes()->andReturnUsing(function (string $sql): array {
            $output = match (true) {
                str_contains($sql, 'SELECT expression_json FROM custom_firewall_rules') => 'allowed_ips',
                str_contains($sql, "SELECT 'domain' AS bucket") => 'filter_options',
                str_contains($sql, 'COUNT(*) AS total_rows') => 'count_rows',
                str_contains($sql, 'WITH filtered AS') => 'log_rows',
                str_contains($sql, 'SELECT domain_name, thresholds_json FROM domain_configs') => 'domain_configs',
                str_contains($sql, 'total_attacks') => 'general_stats',
                str_contains($sql, 'SELECT country, COUNT(*) as attack_count') => 'top_countries',
                default => 'empty',
            };

            return [
                'ok' => true,
                'output' => $output,
                'error' => null,
            ];
        });
        $mock->shouldReceive('parseWranglerJson')->zeroOrMoreTimes()->andReturnUsing(function (string $output): array {
            return match ($output) {
                'filter_options' => [[
                    'results' => [
                        [
                            'bucket' => 'domain',
                            'value' => 'example.com',
                        ],
                        [
                            'bucket' => 'event',
                            'value' => 'challenge_issued',
                        ],
                    ],
                ]],
                'count_rows' => [[
                    'results' => [
                        [
                            'total_rows' => 1,
                        ],
                    ],
                ]],
                'log_rows' => [[
                    'results' => [[
                        'event_type' => 'challenge_issued',
                        'ip_address' => '203.0.113.10',
                        'asn' => '13335',
                        'country' => 'US',
                        'domain_name' => 'example.com',
                        'target_path' => '/',
                        'details' => '{"domain":"example.com"}',
                        'created_at' => '2026-03-22 00:00:00',
                        'requests' => 1,
                        'requests_today' => 1,
                        'requests_yesterday' => 0,
                        'requests_month' => 1,
                    ]],
                ]],
                'general_stats' => [[
                    'results' => [[
                        'total_attacks' => 1,
                        'total_visitors' => 1,
                    ]],
                ]],
                default => [[
                    'results' => [],
                ]],
            };
        });

        $response = $this->withSession(['is_admin' => true])->get('/logs');

        $response->assertOk()
            ->assertSee('Domain')
            ->assertSee('example.com')
            ->assertSee('All domains')
            ->assertSee('All events')
            ->assertSee('Filter by IP');
    }
}
