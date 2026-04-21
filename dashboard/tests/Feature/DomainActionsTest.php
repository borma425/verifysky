<?php

namespace Tests\Feature;

use App\Repositories\DomainConfigRepository;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainActionsTest extends TestCase
{
    private PlanLimitsService|MockInterface $planLimits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        Cache::flush();

        $this->planLimits = Mockery::mock(PlanLimitsService::class);
        $this->planLimits->shouldIgnoreMissing();
        $this->app->instance(PlanLimitsService::class, $this->planLimits);
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

    public function test_admin_is_redirected_away_from_customer_domains_index(): void
    {
        $repository = Mockery::mock(DomainConfigRepository::class);
        $this->app->instance(DomainConfigRepository::class, $repository);
        $repository->shouldNotReceive('listForTenant');
        $this->planLimits->shouldReceive('planDefinitionForTenant')->never();

        $response = $this->withSession(['is_admin' => true])->get('/domains');

        $response->assertRedirect(route('admin.overview'));
    }

    public function test_admin_is_redirected_away_from_customer_domain_tuning_route(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldNotReceive('getDomainConfig');

        $response = $this->withSession(['is_admin' => true])->get('/domains/example.com/tuning');

        $response->assertRedirect(route('admin.overview'));
    }

    public function test_refresh_hostname_status_endpoint_works(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('refreshSaasCustomHostname')->once()->with('example.com')->andReturn([
            'ok' => true,
            'error' => null,
            'custom_hostname' => [],
        ]);
        $mock->shouldReceive('purgeDomainConfigCache')->once()->with('example.com')->andReturn(['ok' => true]);

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false])->post('/domains/example.com/sync-route');

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_store_apex_domain_provisions_www_hostname_for_universal_dns_setup(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('saasHostnamesForInput')->once()->with('cashup.cash')->andReturn([
            'www.cashup.cash',
        ]);
        $mock->shouldReceive('validateOriginServerForHostname')->once()->with('www.cashup.cash', '192.0.2.100')->andReturn([
            'ok' => true,
            'error' => null,
        ]);
        $mock->shouldReceive('provisionSaasCustomHostname')->once()->with('www.cashup.cash', '192.0.2.100')->andReturn([
            'ok' => true,
            'domain_name' => 'www.cashup.cash',
            'zone_id' => 'zone1',
            'turnstile_sitekey' => 'sitekey2',
            'turnstile_secret' => 'secret2',
            'custom_hostname_id' => 'custom-hostname-2',
            'cname_target' => 'customers.verifysky.com',
            'hostname_status' => 'pending',
            'ssl_status' => 'pending_validation',
            'ownership_verification_json' => 'null',
        ]);
        $mock->shouldReceive('queryD1')->once()->andReturn([
            'ok' => true,
            'output' => '',
            'error' => null,
        ]);
        $mock->shouldReceive('ensureWorkerRoute')->once()->with('zone1', 'www.cashup.cash')->andReturn([
            'ok' => true,
            'error' => null,
            'action' => 'www.cashup.cash/*:created, cashup.cash/*:created',
        ]);
        $mock->shouldReceive('saasCnameTarget')->once()->andReturn('customers.verifysky.com');

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false])->post(route('domains.store'), [
            'domain_name' => 'cashup.cash',
            'origin_server' => '192.0.2.100',
            'security_mode' => 'balanced',
        ]);

        $response->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'www.cashup.cash'));
    }

    public function test_store_domain_auto_detects_origin_when_manual_origin_is_omitted(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('saasHostnamesForInput')->once()->with('cashup.cash')->andReturn([
            'www.cashup.cash',
        ]);
        $mock->shouldReceive('detectOriginServerForInput')->once()->with('cashup.cash')->andReturn([
            'ok' => true,
            'origin_server' => '192.0.2.100',
        ]);
        $mock->shouldReceive('validateOriginServerForHostname')->once()->with('www.cashup.cash', '192.0.2.100')->andReturn([
            'ok' => true,
            'error' => null,
        ]);
        $mock->shouldReceive('provisionSaasCustomHostname')->once()->with('www.cashup.cash', '192.0.2.100')->andReturn([
            'ok' => true,
            'domain_name' => 'www.cashup.cash',
            'zone_id' => 'zone1',
            'turnstile_sitekey' => 'sitekey2',
            'turnstile_secret' => 'secret2',
            'custom_hostname_id' => 'custom-hostname-2',
            'cname_target' => 'customers.verifysky.com',
            'hostname_status' => 'pending',
            'ssl_status' => 'pending_validation',
            'ownership_verification_json' => 'null',
        ]);
        $mock->shouldReceive('queryD1')->once()->andReturn([
            'ok' => true,
            'output' => '',
            'error' => null,
        ]);
        $mock->shouldReceive('ensureWorkerRoute')->once()->with('zone1', 'www.cashup.cash')->andReturn([
            'ok' => true,
            'error' => null,
            'action' => 'www.cashup.cash/*:created, cashup.cash/*:created',
        ]);
        $mock->shouldReceive('saasCnameTarget')->once()->andReturn('customers.verifysky.com');

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false])->post(route('domains.store'), [
            'domain_name' => 'cashup.cash',
            'security_mode' => 'balanced',
        ]);

        $response->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'detected automatically'));
    }

    public function test_store_domain_returns_partial_error_when_worker_route_sync_fails(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('saasHostnamesForInput')->once()->with('cashup.cash')->andReturn([
            'www.cashup.cash',
        ]);
        $mock->shouldReceive('validateOriginServerForHostname')->once()->with('www.cashup.cash', '192.0.2.100')->andReturn([
            'ok' => true,
            'error' => null,
        ]);
        $mock->shouldReceive('provisionSaasCustomHostname')->once()->with('www.cashup.cash', '192.0.2.100')->andReturn([
            'ok' => true,
            'domain_name' => 'www.cashup.cash',
            'zone_id' => 'zone1',
            'turnstile_sitekey' => 'sitekey2',
            'turnstile_secret' => 'secret2',
            'custom_hostname_id' => 'custom-hostname-2',
            'cname_target' => 'customers.verifysky.com',
            'hostname_status' => 'pending',
            'ssl_status' => 'pending_validation',
            'ownership_verification_json' => 'null',
        ]);
        $mock->shouldReceive('queryD1')->once()->andReturn([
            'ok' => true,
            'output' => '',
            'error' => null,
        ]);
        $mock->shouldReceive('ensureWorkerRoute')->once()->with('zone1', 'www.cashup.cash')->andReturn([
            'ok' => false,
            'error' => 'Cloudflare route API failed.',
        ]);

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false, 'current_tenant_id' => 'tenant-1'])
            ->from(route('domains.index'))
            ->post(route('domains.store'), [
                'domain_name' => 'cashup.cash',
                'origin_server' => '192.0.2.100',
                'security_mode' => 'balanced',
            ]);

        $response->assertRedirect(route('domains.index'))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'could not route traffic through VerifySky Worker'));
    }

    public function test_store_domain_returns_clear_error_when_auto_detection_fails(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('saasHostnamesForInput')->once()->with('cashup.cash')->andReturn([
            'www.cashup.cash',
        ]);
        $mock->shouldReceive('detectOriginServerForInput')->once()->with('cashup.cash')->andReturn([
            'ok' => false,
            'error' => 'We could not automatically detect the backend origin for this domain. Open Manual Origin and enter the backend IP or hostname.',
        ]);

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false])->from(route('domains.index'))->post(route('domains.store'), [
            'domain_name' => 'cashup.cash',
            'security_mode' => 'balanced',
        ]);

        $response->assertRedirect(route('domains.index'))
            ->assertSessionHas('domain_origin_detection_failed', true)
            ->assertSessionHas('error', 'We could not automatically detect the backend origin for this domain. Open Manual Origin and enter the backend IP or hostname.');
    }

    public function test_store_domain_rejects_invalid_manual_origin_before_provisioning(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('saasHostnamesForInput')->once()->with('cashup.cash')->andReturn([
            'www.cashup.cash',
        ]);
        $mock->shouldReceive('validateOriginServerForHostname')->once()->with('www.cashup.cash', '203.0.113.77')->andReturn([
            'ok' => false,
            'error' => 'We could not reach this backend for the selected domain. Enter a valid hosting IP or backend hostname before continuing.',
        ]);
        $mock->shouldReceive('provisionSaasCustomHostname')->never();

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false])
            ->from(route('domains.index'))
            ->post(route('domains.store'), [
                'domain_name' => 'cashup.cash',
                'origin_server' => '203.0.113.77',
                'security_mode' => 'balanced',
            ]);

        $response->assertRedirect(route('domains.index'))
            ->assertSessionHas('error', 'We could not reach this backend for the selected domain. Enter a valid hosting IP or backend hostname before continuing.');
    }

    public function test_disable_forced_captcha_button_endpoint_works(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')->once()->andReturn([
            'ok' => true,
            'output' => '',
            'error' => null,
        ]);
        $mock->shouldReceive('purgeDomainConfigCache')->once()->with('example.com')->andReturn(['ok' => true]);

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false, 'current_tenant_id' => 'tenant-1'])->post('/domains/example.com/force-captcha', [
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
        $mock->shouldReceive('purgeDomainConfigCache')->once()->with('example.com')->andReturn(['ok' => true]);

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false, 'current_tenant_id' => 'tenant-1'])->post('/domains/example.com/status', [
            'status' => 'paused',
        ]);

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_regular_user_without_tenant_cannot_update_domain_runtime_state(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')->never();
        $mock->shouldReceive('purgeDomainConfigCache')->never();

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false])
            ->post('/domains/example.com/status', [
                'status' => 'paused',
            ]);

        $response->assertRedirect()
            ->assertSessionHas('error', 'Tenant context is required to update this domain.');
    }

    public function test_regular_user_can_update_domain_tuning(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('updateDomainThresholds')->once()->andReturn([
            'ok' => true,
            'error' => null,
        ]);

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false, 'current_tenant_id' => 'tenant-1'])->post('/domains/example.com/tuning', [
            'visit_captcha_threshold' => 12,
            'daily_visit_limit' => 8000,
            'asn_hourly_visit_limit' => 200,
            'flood_burst_challenge' => 40,
            'flood_burst_block' => 80,
            'flood_sustained_challenge' => 120,
            'flood_sustained_block' => 200,
            'ip_hard_ban_rate' => 50,
            'max_challenge_failures' => 4,
            'temp_ban_ttl_hours' => 6,
            'ai_rule_ttl_days' => 7,
            'session_ttl_hours' => 8,
            'auto_aggr_pressure_minutes' => 5,
            'auto_aggr_active_minutes' => 15,
            'auto_aggr_trigger_subnets' => 3,
            'challenge_min_solve_ms_balanced' => 150,
            'challenge_min_telemetry_points_balanced' => 3,
            'challenge_x_tolerance_balanced' => 24,
            'challenge_min_solve_ms_aggressive' => 200,
            'challenge_min_telemetry_points_aggressive' => 4,
            'challenge_x_tolerance_aggressive' => 24,
            'api_count' => 5,
        ]);

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_regular_user_can_change_security_mode_after_domain_is_active(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')->twice()->andReturn(
            [
                'ok' => true,
                'output' => 'domain-status',
                'error' => null,
            ],
            [
                'ok' => true,
                'output' => 'mode-updated',
                'error' => null,
            ]
        );
        $mock->shouldReceive('parseWranglerJson')->once()->andReturn([[
            'results' => [[
                'hostname_status' => 'active',
                'ssl_status' => 'active',
            ]],
        ]]);
        $mock->shouldReceive('purgeDomainConfigCache')->once()->with('www.example.com')->andReturn(['ok' => true]);

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false, 'current_tenant_id' => 'tenant-1'])
            ->post('/domains/www.example.com/security-mode', [
                'security_mode' => 'aggressive',
            ]);

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_regular_user_cannot_change_security_mode_before_domain_is_active(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')->once()->andReturn([
            'ok' => true,
            'output' => 'domain-status',
            'error' => null,
        ]);
        $mock->shouldReceive('parseWranglerJson')->once()->andReturn([[
            'results' => [[
                'hostname_status' => 'pending',
                'ssl_status' => 'pending_validation',
            ]],
        ]]);

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false, 'current_tenant_id' => 'tenant-1'])
            ->post('/domains/www.example.com/security-mode', [
                'security_mode' => 'aggressive',
            ]);

        $response->assertRedirect()->assertSessionHas('error');
    }

    public function test_admin_is_redirected_away_from_customer_domain_delete_route(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldNotReceive('queryD1');
        $mock->shouldNotReceive('parseWranglerJson');
        $mock->shouldNotReceive('removeDomainSecurityArtifacts');
        $mock->shouldNotReceive('deleteSaasCustomHostname');
        $mock->shouldNotReceive('purgeDomainConfigCache');

        $response = $this->withSession(['is_admin' => true])->delete('/domains/example.com');

        $response->assertRedirect(route('admin.overview'));
    }

    public function test_regular_user_can_delete_unverified_domain(): void
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
                'domain_name' => 'www.example.com',
                'zone_id' => 'zone1',
                'turnstile_sitekey' => 'sitekey1',
                'custom_hostname_id' => 'custom-hostname-1',
                'hostname_status' => 'pending',
                'ssl_status' => 'pending_validation',
            ]],
        ]]);
        $mock->shouldReceive('removeDomainSecurityArtifacts')->once()->with('zone1', 'www.example.com', 'sitekey1')->andReturn([
            'ok' => true,
            'error' => null,
            'details' => [],
        ]);
        $mock->shouldReceive('deleteSaasCustomHostname')->once()->with('custom-hostname-1')->andReturn([
            'ok' => true,
            'action' => 'deleted',
            'error' => null,
        ]);
        $mock->shouldReceive('purgeDomainConfigCache')->once()->with('www.example.com')->andReturn(['ok' => true]);

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false, 'current_tenant_id' => 'tenant-1'])->delete('/domains/www.example.com');

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_regular_user_can_delete_active_domain_within_same_tenant(): void
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
                'domain_name' => 'www.example.com',
                'zone_id' => 'zone1',
                'turnstile_sitekey' => 'sitekey1',
                'custom_hostname_id' => 'custom-hostname-1',
                'hostname_status' => 'active',
                'ssl_status' => 'active',
            ]],
        ]]);
        $mock->shouldReceive('removeDomainSecurityArtifacts')->once()->with('zone1', 'www.example.com', 'sitekey1')->andReturn([
            'ok' => true,
            'error' => null,
            'details' => [],
        ]);
        $mock->shouldReceive('deleteSaasCustomHostname')->once()->with('custom-hostname-1')->andReturn([
            'ok' => true,
            'action' => 'deleted',
            'error' => null,
        ]);
        $mock->shouldReceive('purgeDomainConfigCache')->once()->with('www.example.com')->andReturn(['ok' => true]);

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false, 'current_tenant_id' => 'tenant-1'])->delete('/domains/www.example.com');

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_regular_user_without_tenant_cannot_delete_domain(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')->never();
        $mock->shouldReceive('removeDomainSecurityArtifacts')->never();
        $mock->shouldReceive('deleteSaasCustomHostname')->never();
        $mock->shouldReceive('purgeDomainConfigCache')->never();

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false])
            ->delete('/domains/www.example.com');

        $response->assertRedirect()
            ->assertSessionHas('error', 'You do not have permission to remove this domain.');
    }

    public function test_regular_user_group_delete_removes_root_and_www_when_unverified(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')->times(3)->andReturn(
            [
                'ok' => true,
                'output' => 'group-read',
                'error' => null,
            ],
            [
                'ok' => true,
                'output' => 'group-delete',
                'error' => null,
            ],
            [
                'ok' => true,
                'output' => 'group-verify',
                'error' => null,
            ]
        );
        $mock->shouldReceive('parseWranglerJson')->twice()->andReturn(
            [[
                'results' => [
                    [
                        'domain_name' => 'example.com',
                        'zone_id' => 'zone1',
                        'turnstile_sitekey' => 'sitekey-root',
                        'custom_hostname_id' => 'custom-root',
                        'hostname_status' => 'pending',
                        'ssl_status' => 'pending_validation',
                    ],
                    [
                        'domain_name' => 'www.example.com',
                        'zone_id' => 'zone1',
                        'turnstile_sitekey' => 'sitekey-www',
                        'custom_hostname_id' => 'custom-www',
                        'hostname_status' => 'pending',
                        'ssl_status' => 'initializing',
                    ],
                ],
            ]],
            [[
                'results' => [],
            ]]
        );
        $mock->shouldReceive('removeDomainSecurityArtifacts')->once()->with('zone1', 'example.com', 'sitekey-root')->andReturn([
            'ok' => true,
            'error' => null,
            'details' => [],
        ]);
        $mock->shouldReceive('removeDomainSecurityArtifacts')->once()->with('zone1', 'www.example.com', 'sitekey-www')->andReturn([
            'ok' => true,
            'error' => null,
            'details' => [],
        ]);
        $mock->shouldReceive('deleteSaasCustomHostname')->once()->with('custom-root')->andReturn([
            'ok' => true,
            'action' => 'deleted',
            'error' => null,
        ]);
        $mock->shouldReceive('deleteSaasCustomHostname')->once()->with('custom-www')->andReturn([
            'ok' => true,
            'action' => 'deleted',
            'error' => null,
        ]);
        $mock->shouldReceive('purgeDomainConfigCache')->once()->with('example.com')->andReturn(['ok' => true]);
        $mock->shouldReceive('purgeDomainConfigCache')->once()->with('www.example.com')->andReturn(['ok' => true]);

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false, 'current_tenant_id' => 'tenant-1'])->delete('/domains/example.com/group');

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_regular_user_group_delete_allows_active_hostnames_within_same_tenant(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')->times(3)->andReturn(
            [
                'ok' => true,
                'output' => 'group-read',
                'error' => null,
            ],
            [
                'ok' => true,
                'output' => 'group-delete',
                'error' => null,
            ],
            [
                'ok' => true,
                'output' => 'group-verify',
                'error' => null,
            ]
        );
        $mock->shouldReceive('parseWranglerJson')->twice()->andReturn(
            [[
                'results' => [
                    [
                        'domain_name' => 'example.com',
                        'zone_id' => 'zone1',
                        'turnstile_sitekey' => 'sitekey-root',
                        'custom_hostname_id' => 'custom-root',
                        'hostname_status' => 'pending',
                        'ssl_status' => 'pending_validation',
                    ],
                    [
                        'domain_name' => 'www.example.com',
                        'zone_id' => 'zone1',
                        'turnstile_sitekey' => 'sitekey-www',
                        'custom_hostname_id' => 'custom-www',
                        'hostname_status' => 'active',
                        'ssl_status' => 'active',
                    ],
                ],
            ]],
            [[
                'results' => [],
            ]]
        );
        $mock->shouldReceive('removeDomainSecurityArtifacts')->once()->with('zone1', 'example.com', 'sitekey-root')->andReturn([
            'ok' => true,
            'error' => null,
            'details' => [],
        ]);
        $mock->shouldReceive('removeDomainSecurityArtifacts')->once()->with('zone1', 'www.example.com', 'sitekey-www')->andReturn([
            'ok' => true,
            'error' => null,
            'details' => [],
        ]);
        $mock->shouldReceive('deleteSaasCustomHostname')->once()->with('custom-root')->andReturn([
            'ok' => true,
            'action' => 'deleted',
            'error' => null,
        ]);
        $mock->shouldReceive('deleteSaasCustomHostname')->once()->with('custom-www')->andReturn([
            'ok' => true,
            'action' => 'deleted',
            'error' => null,
        ]);
        $mock->shouldReceive('purgeDomainConfigCache')->once()->with('example.com')->andReturn(['ok' => true]);
        $mock->shouldReceive('purgeDomainConfigCache')->once()->with('www.example.com')->andReturn(['ok' => true]);

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false, 'current_tenant_id' => 'tenant-1'])->delete('/domains/example.com/group');

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_admin_is_redirected_away_from_customer_logs_page(): void
    {
        $mock = $this->bindServiceMock();
        $mock->shouldNotReceive('listIpFarmRules');
        $mock->shouldNotReceive('queryD1');
        $mock->shouldNotReceive('parseWranglerJson');

        $response = $this->withSession(['is_admin' => true])->get('/logs');

        $response->assertRedirect(route('admin.overview'));
    }
}
