<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Repositories\SecurityLogRepository;
use App\Services\Billing\TenantBillingStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class LogsAnalyticsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_non_admin_logs_page_shows_only_customer_safe_analytics(): void
    {
        $tenant = $this->makeTenant('logs-tenant');
        $this->bindLayoutBillingStatus();

        $repository = Mockery::mock(SecurityLogRepository::class);
        $repository->shouldReceive('fetchIndexPayload')
            ->once()
            ->with([], (string) $tenant->id, false)
            ->andReturn([
                'ok' => true,
                'error' => null,
                'page' => 1,
                'per_page' => 50,
                'total' => 1,
                'rows' => [[
                    'domain_name' => 'tenant.example.com',
                    'worst_event_type' => 'challenge_failed',
                    'ip_address' => '203.0.113.10',
                    'country' => 'US',
                    'asn' => 'AS64500',
                    'requests_today' => 7,
                    'requests_yesterday' => 3,
                    'requests_month' => 20,
                    'flagged_events' => 6,
                    'solved_or_passed_events' => 1,
                    'recent_paths_json' => json_encode(['/login', '/checkout']),
                    'details' => json_encode(['rule' => 'Managed Challenge']),
                    'created_at' => '2026-04-21 10:00:00',
                ]],
                'all_farm_ips' => [],
                'domain_configs' => [],
                'filter_options' => [
                    'domains' => ['tenant.example.com'],
                    'events' => ['challenge_failed', 'session_created'],
                ],
                'general_stats' => [
                    'total_attacks' => 13,
                    'total_visitors' => 22,
                    'top_countries' => [['country' => 'US', 'attack_count' => 10]],
                ],
                'filters' => [
                    'event_type' => '',
                    'domain_name' => '',
                    'ip_address' => '',
                ],
                'tenant_scoped' => true,
                'accessible_domains' => ['tenant.example.com'],
            ]);
        $this->app->instance(SecurityLogRepository::class, $repository);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get('/logs');

        $response->assertOk()
            ->assertSee('Security Activity')
            ->assertSee('Attacks Blocked This Month')
            ->assertSee('Verified Users This Month')
            ->assertSee('Recent Security Events')
            ->assertSee('YOUR DOMAINS')
            ->assertSee('tenant.example.com')
            ->assertDontSee('other.example.com')
            ->assertDontSee('Allow-list IP and reset ban')
            ->assertDontSee('Block IP for 24h')
            ->assertDontSee('Clear');
    }

    public function test_admin_is_redirected_away_from_customer_logs_page(): void
    {
        $repository = Mockery::mock(SecurityLogRepository::class);
        $repository->shouldNotReceive('fetchIndexPayload');
        $this->app->instance(SecurityLogRepository::class, $repository);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
        ])->get('/logs');

        $response->assertRedirect(route('admin.overview'));
    }

    private function bindLayoutBillingStatus(): void
    {
        $billingStatus = Mockery::mock(TenantBillingStatusService::class);
        $billingStatus->shouldReceive('forTenantId')->andReturn(null);
        $this->app->instance(TenantBillingStatusService::class, $billingStatus);
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'plan' => 'starter',
            'status' => 'active',
            'billing_start_at' => '2026-04-01 00:00:00',
        ]);
    }
}
