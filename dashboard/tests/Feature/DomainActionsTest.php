<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Repositories\DomainConfigRepository;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainActionsTest extends TestCase
{
    use RefreshDatabase;

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

    private function tenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Customer Tenant',
            'slug' => 'customer-tenant-'.strtolower(str()->random(8)),
            'plan' => 'starter',
            'status' => 'active',
        ]);
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
        $tenant = $this->tenant();
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);

        $mock = $this->bindServiceMock();
        $mock->shouldReceive('refreshSaasCustomHostname')->once()->with('example.com')->andReturn([
            'ok' => true,
            'error' => null,
            'custom_hostname' => [],
        ]);
        $mock->shouldReceive('purgeDomainConfigCache')->once()->with('example.com')->andReturn(['ok' => true]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->post('/domains/example.com/sync-route');

        $response->assertRedirect()->assertSessionHas('status');
    }

    public function test_domain_status_polling_endpoint_returns_lifecycle_payload(): void
    {
        $tenant = $this->tenant();
        $repository = Mockery::mock(DomainConfigRepository::class);
        $repository->shouldReceive('listForTenant')->once()->with((string) $tenant->id, false)->andReturn([
            'ok' => true,
            'error' => null,
            'domains' => [[
                'domain_name' => 'www.example.com',
                'status' => 'pending',
                'security_mode' => 'balanced',
                'hostname_status' => 'pending',
                'ssl_status' => 'pending_validation',
                'provisioning_status' => TenantDomain::PROVISIONING_PROVISIONING,
                'provisioning_error' => '',
                'cname_target' => 'customers.verifysky.com',
                'created_at' => '2026-04-28 10:00:00',
                'updated_at' => '2026-04-28 10:00:00',
            ]],
        ]);
        $this->app->instance(DomainConfigRepository::class, $repository);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->getJson(route('domains.statuses'));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('polling', true)
            ->assertJsonPath('groups.0.display_domain', 'example.com')
            ->assertJsonPath('groups.0.live_status.label', 'SETTING UP')
            ->assertJsonPath('groups.0.live_status.locked', true)
            ->assertJsonPath('groups.0.health_score', 0);
    }

    public function test_non_admin_cannot_refresh_another_tenant_domain(): void
    {
        $owner = $this->tenant();
        $other = $this->tenant();
        TenantDomain::query()->create([
            'tenant_id' => $owner->id,
            'hostname' => 'locked.example.com',
        ]);

        $mock = $this->bindServiceMock();
        $mock->shouldReceive('refreshSaasCustomHostname')->never();
        $mock->shouldReceive('purgeDomainConfigCache')->never();

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $other->id,
        ])->post('/domains/locked.example.com/sync-route');

        $response->assertRedirect()->assertSessionHas('error', 'We could not refresh this domain yet. Please try again in a few minutes.');
    }

    public function test_store_apex_domain_provisions_www_hostname_for_universal_dns_setup(): void
    {
        Bus::fake();
        $tenant = $this->tenant();
        $this->planLimits->shouldReceive('getDomainsUsage')->twice()->with(Mockery::type(Tenant::class))->andReturn(['can_add' => true]);

        $mock = $this->bindServiceMock();
        $mock->shouldReceive('saasHostnamesForInput')->once()->with('cashup.cash')->andReturn([
            'www.cashup.cash',
        ]);
        $mock->shouldReceive('validateOriginServerForHostname')->never();
        $mock->shouldReceive('provisionSaasCustomHostname')->never();
        $mock->shouldReceive('queryD1')->never();
        $mock->shouldReceive('ensureWorkerRoute')->never();

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->post(route('domains.store'), [
            'domain_name' => 'cashup.cash',
            'origin_server' => '192.0.2.100',
            'security_mode' => 'balanced',
        ]);

        $response->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Setup started for www.cashup.cash'));

        $this->assertDatabaseHas('tenant_domains', [
            'tenant_id' => $tenant->id,
            'hostname' => 'www.cashup.cash',
            'provisioning_status' => TenantDomain::PROVISIONING_PENDING,
        ]);
    }

    public function test_store_domain_defers_origin_detection_when_manual_origin_is_omitted(): void
    {
        Bus::fake();
        $tenant = $this->tenant();
        $this->planLimits->shouldReceive('getDomainsUsage')->twice()->with(Mockery::type(Tenant::class))->andReturn(['can_add' => true]);

        $mock = $this->bindServiceMock();
        $mock->shouldReceive('saasHostnamesForInput')->once()->with('cashup.cash')->andReturn([
            'www.cashup.cash',
        ]);
        $mock->shouldReceive('detectOriginServerForInput')->never();

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->post(route('domains.store'), [
            'domain_name' => 'cashup.cash',
            'security_mode' => 'balanced',
        ]);

        $response->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'VerifySky will try to find your server automatically.'));
    }

    public function test_store_domain_defers_worker_route_sync_to_queue(): void
    {
        Bus::fake();
        $tenant = $this->tenant();
        $this->planLimits->shouldReceive('getDomainsUsage')->twice()->with(Mockery::type(Tenant::class))->andReturn(['can_add' => true]);

        $mock = $this->bindServiceMock();
        $mock->shouldReceive('saasHostnamesForInput')->once()->with('cashup.cash')->andReturn([
            'www.cashup.cash',
        ]);
        $mock->shouldReceive('ensureWorkerRoute')->never();

        $response = $this->withSession(['is_authenticated' => true, 'is_admin' => false, 'current_tenant_id' => (string) $tenant->id])
            ->from(route('domains.index'))
            ->post(route('domains.store'), [
                'domain_name' => 'cashup.cash',
                'origin_server' => '192.0.2.100',
                'security_mode' => 'balanced',
            ]);

        $response->assertRedirect(route('domains.index'))
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Setup started for www.cashup.cash'))
            ->assertSessionMissing('warning')
            ->assertSessionHas('domain_setup', fn (array $setup): bool => ($setup['domains'] ?? []) === ['www.cashup.cash']);
    }

    public function test_domains_index_shows_quarantine_alert_with_billing_link(): void
    {
        $tenant = $this->tenant();
        $this->planLimits->shouldReceive('getDomainsUsage')->once()->with(Mockery::type(Tenant::class))->andReturn([
            'used' => 0,
            'limit' => 1,
            'remaining' => 1,
            'can_add' => true,
            'plan_key' => 'starter',
            'message' => null,
        ]);
        $this->planLimits->shouldReceive('getBillingUsageLimits')->once()->with(Mockery::type(Tenant::class))->andReturn([
            'plan_key' => 'starter',
            'plan_name' => 'Free',
            'protected_sessions' => 5000,
            'bot_fair_use' => 5000,
        ]);

        $repository = Mockery::mock(DomainConfigRepository::class);
        $repository->shouldReceive('listForTenant')->once()->with((string) $tenant->id, false)->andReturn([
            'ok' => true,
            'error' => null,
            'domains' => [],
        ]);
        $this->app->instance(DomainConfigRepository::class, $repository);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
            'error' => 'Generic provisioning failure.',
            'domain_quarantine' => [
                'asset_key' => 'example.com',
                'quarantined_until' => '2026-05-07 12:00:00',
            ],
        ])->get(route('domains.index'));

        $response->assertOk()
            ->assertSee('Domain temporarily locked')
            ->assertSee('example.com')
            ->assertSee('2026-05-07 12:00:00 UTC')
            ->assertSee('Open Billing');
    }

    public function test_store_domain_no_longer_fails_synchronously_when_auto_detection_will_fail_later(): void
    {
        Bus::fake();
        $tenant = $this->tenant();
        $this->planLimits->shouldReceive('getDomainsUsage')->twice()->with(Mockery::type(Tenant::class))->andReturn(['can_add' => true]);

        $mock = $this->bindServiceMock();
        $mock->shouldReceive('saasHostnamesForInput')->once()->with('cashup.cash')->andReturn([
            'www.cashup.cash',
        ]);
        $mock->shouldReceive('detectOriginServerForInput')->never();

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->from(route('domains.index'))->post(route('domains.store'), [
            'domain_name' => 'cashup.cash',
            'security_mode' => 'balanced',
        ]);

        $response->assertRedirect(route('domains.index'))
            ->assertSessionMissing('domain_origin_detection_failed')
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Setup started for www.cashup.cash'));
    }

    public function test_store_domain_defers_manual_origin_validation_to_queue(): void
    {
        Bus::fake();
        $tenant = $this->tenant();
        $this->planLimits->shouldReceive('getDomainsUsage')->twice()->with(Mockery::type(Tenant::class))->andReturn(['can_add' => true]);

        $mock = $this->bindServiceMock();
        $mock->shouldReceive('saasHostnamesForInput')->once()->with('cashup.cash')->andReturn([
            'www.cashup.cash',
        ]);
        $mock->shouldReceive('validateOriginServerForHostname')->never();
        $mock->shouldReceive('provisionSaasCustomHostname')->never();

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])
            ->from(route('domains.index'))
            ->post(route('domains.store'), [
                'domain_name' => 'cashup.cash',
                'origin_server' => '203.0.113.77',
                'security_mode' => 'balanced',
            ]);

        $response->assertRedirect(route('domains.index'))
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Setup started for www.cashup.cash'));
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
            ->assertSessionHas('error', 'Please sign in again before changing this domain.');
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

    public function test_www_redirect_apex_tuning_route_uses_protected_hostname(): void
    {
        $tenant = $this->tenant();
        $this->planLimits->shouldReceive('getBillingUsageLimits')->andReturn([
            'plan_key' => 'starter',
            'plan_name' => 'Free',
            'protected_sessions' => 5000,
            'bot_fair_use' => 5000,
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'www.cashup.cash',
            'apex_mode' => 'www_redirect',
            'hostname_status' => 'active',
            'ssl_status' => 'active',
            'provisioning_status' => TenantDomain::PROVISIONING_ACTIVE,
        ]);

        $mock = $this->bindServiceMock();
        $mock->shouldReceive('getDomainConfig')
            ->once()
            ->with('www.cashup.cash', (string) $tenant->id, false)
            ->andReturn([
                'ok' => true,
                'error' => null,
                'config' => [
                    'domain_name' => 'www.cashup.cash',
                    'origin_server' => '152.53.247.192',
                    'security_mode' => 'balanced',
                    'thresholds_json' => null,
                ],
            ]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get('/domains/cashup.cash/tuning');

        $response->assertOk()
            ->assertSee('Protection settings for www.cashup.cash');
    }

    public function test_www_redirect_apex_runtime_action_uses_protected_hostname(): void
    {
        $tenant = $this->tenant();
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'www.cashup.cash',
            'apex_mode' => 'www_redirect',
        ]);

        $mock = $this->bindServiceMock();
        $mock->shouldReceive('queryD1')
            ->once()
            ->with(Mockery::on(fn (string $sql): bool => str_contains($sql, "domain_name = 'www.cashup.cash'")))
            ->andReturn([
                'ok' => true,
                'output' => '',
                'error' => null,
            ]);
        $mock->shouldReceive('purgeDomainConfigCache')->once()->with('www.cashup.cash')->andReturn(['ok' => true]);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->post('/domains/cashup.cash/status', [
            'status' => 'paused',
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
