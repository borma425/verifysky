<?php

namespace Tests\Feature;

use App\Models\CloudflareCostDaily;
use App\Models\CloudflareUsageDaily;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareCostAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_command_imports_wae_usage_and_estimates_domain_costs(): void
    {
        config([
            'edgeshield.cloudflare_account_id' => 'account-123',
            'edgeshield.cloudflare_api_token' => 'token-123',
        ]);

        $tenant = $this->makeTenant();

        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/account-123/analytics_engine/sql' => Http::response([
                [
                    'usage_date' => '2026-05-01',
                    'tenant_id' => (string) $tenant->id,
                    'domain_name' => 'example.com',
                    'environment' => 'production',
                    'outcome' => 'pass',
                    'requests' => 100000,
                    'd1_rows_read' => 1000,
                    'd1_rows_written' => 100,
                    'd1_query_count' => 200,
                    'kv_reads' => 20,
                    'kv_writes' => 2,
                    'kv_deletes' => 1,
                    'kv_lists' => 0,
                    'kv_write_bytes' => 500,
                ],
            ]),
        ]);

        $this->artisan('billing:sync-cloudflare-costs', [
            '--from' => '2026-05-01 00:00:00',
            '--to' => '2026-05-02 00:00:00',
        ])->assertExitCode(0);

        $usage = CloudflareUsageDaily::query()->sole();
        $cost = CloudflareCostDaily::query()->sole();

        $this->assertSame($tenant->id, (int) $usage->tenant_id);
        $this->assertSame('example.com', $usage->domain_name);
        $this->assertSame('pass', $usage->outcome);
        $this->assertSame(100000, $usage->requests);
        $this->assertGreaterThan(0, (float) $cost->total_estimated_cost_usd);
        $this->assertSame('0.030000', $cost->workers_requests_cost_usd);

        Http::assertSent(function ($request): bool {
            $sql = (string) $request->body();

            return str_contains($sql, 'blob3')
                && str_contains($sql, 'AS outcome')
                && str_contains($sql, 'GROUP BY usage_date, tenant_id, domain_name, environment, outcome')
                && str_contains($sql, 'SUM(_sample_interval * double1) AS requests')
                && str_contains($sql, 'SUM(_sample_interval * double9) AS kv_write_bytes');
        });
    }

    public function test_sync_command_upserts_by_outcome_and_removes_legacy_rows(): void
    {
        config([
            'edgeshield.cloudflare_account_id' => 'account-123',
            'edgeshield.cloudflare_api_token' => 'token-123',
        ]);

        $tenant = $this->makeTenant();
        CloudflareUsageDaily::query()->create([
            'usage_date' => '2026-05-01',
            'tenant_id' => $tenant->id,
            'domain_name' => 'example.com',
            'environment' => 'production',
            'outcome' => 'legacy',
            'requests' => 1000,
        ]);
        CloudflareCostDaily::query()->create([
            'usage_date' => '2026-05-01',
            'tenant_id' => $tenant->id,
            'domain_name' => 'example.com',
            'environment' => 'production',
            'outcome' => 'legacy',
            'workers_requests_cost_usd' => 0.01,
            'total_estimated_cost_usd' => 0.01,
        ]);

        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/account-123/analytics_engine/sql' => Http::sequence()
                ->push([
                    [
                        'usage_date' => '2026-05-01',
                        'tenant_id' => (string) $tenant->id,
                        'domain_name' => 'example.com',
                        'environment' => 'production',
                        'outcome' => 'pass',
                        'requests' => 2000,
                        'd1_rows_read' => 100,
                        'd1_rows_written' => 10,
                        'd1_query_count' => 20,
                        'kv_reads' => 5,
                        'kv_writes' => 1,
                        'kv_deletes' => 0,
                        'kv_lists' => 0,
                        'kv_write_bytes' => 25,
                    ],
                    [
                        'usage_date' => '2026-05-01',
                        'tenant_id' => (string) $tenant->id,
                        'domain_name' => 'example.com',
                        'environment' => 'production',
                        'outcome' => 'challenge_issued',
                        'requests' => 100,
                        'd1_rows_read' => 50,
                        'd1_rows_written' => 5,
                        'd1_query_count' => 10,
                        'kv_reads' => 3,
                        'kv_writes' => 2,
                        'kv_deletes' => 0,
                        'kv_lists' => 0,
                        'kv_write_bytes' => 75,
                    ],
                ])
                ->push([
                    [
                        'usage_date' => '2026-05-01',
                        'tenant_id' => (string) $tenant->id,
                        'domain_name' => 'example.com',
                        'environment' => 'production',
                        'outcome' => 'pass',
                        'requests' => 3000,
                        'd1_rows_read' => 120,
                        'd1_rows_written' => 12,
                        'd1_query_count' => 22,
                        'kv_reads' => 6,
                        'kv_writes' => 1,
                        'kv_deletes' => 0,
                        'kv_lists' => 0,
                        'kv_write_bytes' => 30,
                    ],
                    [
                        'usage_date' => '2026-05-01',
                        'tenant_id' => (string) $tenant->id,
                        'domain_name' => 'example.com',
                        'environment' => 'production',
                        'outcome' => 'challenge_issued',
                        'requests' => 100,
                        'd1_rows_read' => 50,
                        'd1_rows_written' => 5,
                        'd1_query_count' => 10,
                        'kv_reads' => 3,
                        'kv_writes' => 2,
                        'kv_deletes' => 0,
                        'kv_lists' => 0,
                        'kv_write_bytes' => 75,
                    ],
                ]),
        ]);

        $arguments = [
            '--from' => '2026-05-01 00:00:00',
            '--to' => '2026-05-02 00:00:00',
        ];

        $this->artisan('billing:sync-cloudflare-costs', $arguments)->assertExitCode(0);
        $this->artisan('billing:sync-cloudflare-costs', $arguments)->assertExitCode(0);

        $this->assertDatabaseMissing('cloudflare_usage_daily', [
            'tenant_id' => $tenant->id,
            'domain_name' => 'example.com',
            'outcome' => 'legacy',
        ]);
        $this->assertSame(2, CloudflareUsageDaily::query()->count());
        $this->assertSame(2, CloudflareCostDaily::query()->count());
        $this->assertDatabaseHas('cloudflare_usage_daily', [
            'tenant_id' => $tenant->id,
            'domain_name' => 'example.com',
            'outcome' => 'pass',
            'requests' => 3000,
        ]);
        $this->assertDatabaseHas('cloudflare_usage_daily', [
            'tenant_id' => $tenant->id,
            'domain_name' => 'example.com',
            'outcome' => 'challenge_issued',
            'requests' => 100,
        ]);
    }

    public function test_reconcile_command_allocates_actual_cost_pro_rata(): void
    {
        $tenant = $this->makeTenant();
        CloudflareCostDaily::query()->create([
            'usage_date' => '2026-05-01',
            'tenant_id' => $tenant->id,
            'domain_name' => 'example.com',
            'environment' => 'production',
            'outcome' => 'pass',
            'workers_requests_cost_usd' => 0.10,
            'd1_cost_usd' => 0.00,
            'kv_cost_usd' => 0.00,
            'wae_cost_usd' => 0.00,
            'total_estimated_cost_usd' => 0.10,
        ]);
        CloudflareCostDaily::query()->create([
            'usage_date' => '2026-05-02',
            'tenant_id' => $tenant->id,
            'domain_name' => 'www.example.com',
            'environment' => 'production',
            'outcome' => 'challenge_issued',
            'workers_requests_cost_usd' => 0.30,
            'd1_cost_usd' => 0.00,
            'kv_cost_usd' => 0.00,
            'wae_cost_usd' => 0.00,
            'total_estimated_cost_usd' => 0.30,
        ]);

        $this->artisan('billing:reconcile-cloudflare-costs', [
            '--period' => '2026-05',
            '--actual-cost' => '2.00',
        ])->assertExitCode(0);

        $this->assertSame(2.0, round((float) CloudflareCostDaily::query()->sum('final_reconciled_cost_usd'), 6));
        $this->assertDatabaseHas('cloudflare_billing_snapshots', [
            'period_start' => '2026-05-01 00:00:00',
            'period_end' => '2026-06-01 00:00:00',
            'amount_usd' => '2.000000',
        ]);
    }

    public function test_reconcile_command_can_use_cloudflare_paygo_actual_cost(): void
    {
        config([
            'edgeshield.cloudflare_account_id' => 'account-123',
            'edgeshield.cloudflare_api_token' => 'token-123',
        ]);

        $tenant = $this->makeTenant();
        CloudflareCostDaily::query()->create([
            'usage_date' => '2026-05-01',
            'tenant_id' => $tenant->id,
            'domain_name' => 'example.com',
            'environment' => 'production',
            'outcome' => 'pass',
            'workers_requests_cost_usd' => 0.40,
            'd1_cost_usd' => 0.00,
            'kv_cost_usd' => 0.00,
            'wae_cost_usd' => 0.00,
            'total_estimated_cost_usd' => 0.40,
        ]);

        Http::fake([
            'https://api.cloudflare.com/client/v4/accounts/account-123/billing/usage/paygo' => Http::response([
                [
                    'ChargePeriodStart' => '2026-05-01T00:00:00Z',
                    'ChargePeriodEnd' => '2026-06-01T00:00:00Z',
                    'ContractedCost' => 3.25,
                    'ConsumedQuantity' => 100,
                    'ServiceName' => 'Workers',
                ],
            ]),
        ]);

        $this->artisan('billing:reconcile-cloudflare-costs', [
            '--period' => '2026-05',
        ])->assertExitCode(0);

        $this->assertSame(3.25, round((float) CloudflareCostDaily::query()->sum('final_reconciled_cost_usd'), 6));
        $this->assertDatabaseHas('cloudflare_billing_snapshots', [
            'source' => 'cloudflare_paygo',
            'amount_usd' => '3.250000',
        ]);
    }

    public function test_cloudflare_cost_panel_is_visible_only_for_vip_tenants(): void
    {
        [$tenant, $owner] = $this->makeTenantWithOwner();
        $this->seedCostRows($tenant);

        $this->withSession($this->sessionFor($tenant, $owner))
            ->get(route('billing.index'))
            ->assertOk()
            ->assertDontSee('Cloudflare Resource Cost');

        $tenant->forceFill(['is_vip' => true])->save();

        $this->withSession($this->sessionFor($tenant->fresh(), $owner))
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('Cloudflare Resource Cost')
            ->assertSee('example.com')
            ->assertSee('Estimated from edge usage')
            ->assertDontSee('Outcome Breakdown')
            ->assertDontSee('challenge_issued');
    }

    public function test_admin_sees_outcome_breakdown(): void
    {
        $tenant = $this->makeTenant();
        $this->seedCostRows($tenant);

        $this->withSession(['is_authenticated' => true, 'is_admin' => true])
            ->get(route('admin.tenants.show', $tenant))
            ->assertOk()
            ->assertSee('Outcome Breakdown')
            ->assertSee('pass')
            ->assertSee('challenge_issued')
            ->assertSee('Cost / 1M')
            ->assertSee('KV write bytes');
    }

    public function test_admin_can_toggle_vip_cost_visibility(): void
    {
        $tenant = $this->makeTenant();

        $this->withSession(['is_authenticated' => true, 'is_admin' => true])
            ->post(route('admin.tenants.vip.update', $tenant), ['is_vip' => '1'])
            ->assertRedirect();

        $this->assertTrue((bool) $tenant->fresh()->is_vip);
    }

    private function makeTenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'plan' => 'growth',
            'status' => 'active',
            'billing_start_at' => '2026-05-01 00:00:00',
        ]);
    }

    /**
     * @return array{0:Tenant,1:User}
     */
    private function makeTenantWithOwner(): array
    {
        $tenant = $this->makeTenant();
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'password',
            'role' => 'user',
        ]);
        TenantMembership::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);

        return [$tenant, $owner];
    }

    private function seedCostRows(Tenant $tenant): void
    {
        CloudflareUsageDaily::query()->create([
            'usage_date' => '2026-05-01',
            'tenant_id' => $tenant->id,
            'domain_name' => 'example.com',
            'environment' => 'production',
            'outcome' => 'pass',
            'requests' => 1000,
            'd1_rows_read' => 200,
            'd1_rows_written' => 20,
            'd1_query_count' => 50,
            'kv_reads' => 40,
            'kv_writes' => 4,
            'kv_deletes' => 1,
            'kv_lists' => 0,
            'kv_write_bytes' => 128,
            'last_synced_at' => '2026-05-01 01:00:00',
        ]);
        CloudflareCostDaily::query()->create([
            'usage_date' => '2026-05-01',
            'tenant_id' => $tenant->id,
            'domain_name' => 'example.com',
            'environment' => 'production',
            'outcome' => 'pass',
            'workers_requests_cost_usd' => 0.01,
            'd1_cost_usd' => 0.01,
            'kv_cost_usd' => 0.01,
            'wae_cost_usd' => 0.01,
            'total_estimated_cost_usd' => 0.04,
            'last_synced_at' => '2026-05-01 01:00:00',
        ]);
        CloudflareUsageDaily::query()->create([
            'usage_date' => '2026-05-01',
            'tenant_id' => $tenant->id,
            'domain_name' => 'example.com',
            'environment' => 'production',
            'outcome' => 'challenge_issued',
            'requests' => 100,
            'd1_rows_read' => 50,
            'd1_rows_written' => 10,
            'd1_query_count' => 20,
            'kv_reads' => 30,
            'kv_writes' => 5,
            'kv_deletes' => 1,
            'kv_lists' => 0,
            'kv_write_bytes' => 256,
            'last_synced_at' => '2026-05-01 01:00:00',
        ]);
        CloudflareCostDaily::query()->create([
            'usage_date' => '2026-05-01',
            'tenant_id' => $tenant->id,
            'domain_name' => 'example.com',
            'environment' => 'production',
            'outcome' => 'challenge_issued',
            'workers_requests_cost_usd' => 0.02,
            'd1_cost_usd' => 0.02,
            'kv_cost_usd' => 0.02,
            'wae_cost_usd' => 0.02,
            'total_estimated_cost_usd' => 0.08,
            'last_synced_at' => '2026-05-01 01:00:00',
        ]);
    }

    private function sessionFor(Tenant $tenant, User $user): array
    {
        return [
            'is_authenticated' => true,
            'is_admin' => false,
            'user_id' => $user->id,
            'current_tenant_id' => $tenant->id,
        ];
    }
}
