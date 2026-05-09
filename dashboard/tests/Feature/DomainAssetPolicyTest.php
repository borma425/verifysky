<?php

namespace Tests\Feature;

use App\Actions\Domains\DeleteDomainAction;
use App\Actions\Domains\ProvisionTenantDomainAction;
use App\Jobs\Domains\EnsureCloudflareWorkerRouteJob;
use App\Jobs\Domains\ProvisionCloudflareSaasHostnameJob;
use App\Jobs\Domains\SyncDomainConfigToD1Job;
use App\Jobs\Domains\SyncSaasSecurityArtifactsJob;
use App\Jobs\Domains\ValidateOriginServerJob;
use App\Models\DomainAssetHistory;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantPlanGrant;
use App\Models\TenantSubscription;
use App\Services\Domains\DomainAssetPolicyService;
use App\Services\Domains\DomainProvisioningService;
use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class DomainAssetPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_registrable_domain_uses_public_suffix_list(): void
    {
        $asset = app(DomainAssetPolicyService::class)->describe('app.mycompany.co.uk');

        $this->assertSame(DomainAssetHistory::TYPE_REGISTRABLE_DOMAIN, $asset['asset_type']);
        $this->assertSame('mycompany.co.uk', $asset['asset_key']);
        $this->assertSame('mycompany.co.uk', $asset['registrable_domain']);
    }

    public function test_www_and_apex_share_one_trial_asset(): void
    {
        $policy = app(DomainAssetPolicyService::class);

        $this->assertSame($policy->describe('example.com')['asset_key'], $policy->describe('www.example.com')['asset_key']);
    }

    public function test_verified_regular_domain_gets_one_pro_trial(): void
    {
        Bus::fake();

        $tenant = $this->tenant();
        $domain = TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'www.example.com',
            'hostname_status' => 'active',
            'ssl_status' => 'active',
            'provisioning_status' => TenantDomain::PROVISIONING_PROVISIONING,
        ]);

        $this->app->instance(EdgeShieldService::class, Mockery::mock(EdgeShieldService::class));

        app(DomainProvisioningService::class)->markActiveIfVerified($domain);
        app(DomainProvisioningService::class)->markActiveIfVerified($domain->refresh());

        $this->assertDatabaseHas('domain_asset_histories', [
            'asset_key' => 'example.com',
            'asset_type' => DomainAssetHistory::TYPE_REGISTRABLE_DOMAIN,
            'pro_trial_tenant_id' => $tenant->id,
        ]);
        $this->assertSame(1, TenantPlanGrant::query()->where('source', 'trial')->count());

        $grant = TenantPlanGrant::query()->where('source', 'trial')->sole();
        $this->assertSame('pro', $grant->granted_plan_key);
        $this->assertSame(TenantPlanGrant::STATUS_ACTIVE, $grant->status);
    }

    public function test_shared_hostname_can_be_added_but_does_not_get_trial(): void
    {
        Bus::fake();

        $tenant = $this->tenant();
        $domain = TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'my-store.vercel.app',
            'hostname_status' => 'active',
            'ssl_status' => 'active',
            'provisioning_status' => TenantDomain::PROVISIONING_PROVISIONING,
        ]);

        $this->app->instance(EdgeShieldService::class, Mockery::mock(EdgeShieldService::class));

        app(DomainProvisioningService::class)->markActiveIfVerified($domain);

        $this->assertDatabaseHas('domain_asset_histories', [
            'asset_key' => 'my-store.vercel.app',
            'asset_type' => DomainAssetHistory::TYPE_SHARED_HOSTNAME,
            'pro_trial_granted_at' => null,
        ]);
        $this->assertSame(0, TenantPlanGrant::query()->where('source', 'trial')->count());
    }

    public function test_quarantine_blocks_starter_but_paid_tenant_can_bypass(): void
    {
        Bus::fake();

        $starter = $this->tenant('starter');
        $paid = $this->tenant('growth');
        app(DomainAssetPolicyService::class)->quarantineRemovedHostnames(['www.example.com'], (string) $starter->id, 'domain_deleted');

        $edgeShield = Mockery::mock(EdgeShieldService::class);
        $edgeShield->shouldReceive('saasHostnamesForInput')->twice()->with('example.com')->andReturn(['www.example.com']);
        $this->app->instance(EdgeShieldService::class, $edgeShield);
        $this->app->instance(PlanLimitsService::class, $this->planLimits());

        $blocked = app(ProvisionTenantDomainAction::class)->execute([
            'domain_name' => 'example.com',
            'origin_server' => '192.0.2.10',
            'security_mode' => 'balanced',
        ], (string) $starter->id);

        $allowed = app(ProvisionTenantDomainAction::class)->execute([
            'domain_name' => 'example.com',
            'origin_server' => '192.0.2.10',
            'security_mode' => 'balanced',
        ], (string) $paid->id);

        $this->assertFalse($blocked['ok']);
        $this->assertStringContainsString('recently removed', $blocked['error']);
        $this->assertTrue($blocked['quarantine_blocked']);
        $this->assertSame('example.com', $blocked['asset_key']);
        $this->assertNotEmpty($blocked['quarantined_until']);
        $this->assertTrue($allowed['ok']);

        Bus::assertChained([
            ValidateOriginServerJob::class,
            EnsureCloudflareWorkerRouteJob::class,
            ProvisionCloudflareSaasHostnameJob::class,
            SyncDomainConfigToD1Job::class,
            SyncSaasSecurityArtifactsJob::class,
        ]);
    }

    public function test_shared_hostname_quarantine_does_not_block_neighbor_hostname(): void
    {
        $tenant = $this->tenant();
        app(DomainAssetPolicyService::class)->quarantineRemovedHostnames(['my-store.vercel.app'], (string) $tenant->id, 'domain_deleted');

        $blocked = app(DomainAssetPolicyService::class)->quarantineStatusForTenant('my-store.vercel.app', $tenant);
        $neighbor = app(DomainAssetPolicyService::class)->quarantineStatusForTenant('another-store.vercel.app', $tenant);

        $this->assertTrue($blocked['blocked']);
        $this->assertFalse($neighbor['blocked']);
        $this->assertDatabaseHas('domain_asset_histories', [
            'asset_key' => 'my-store.vercel.app',
            'asset_type' => DomainAssetHistory::TYPE_SHARED_HOSTNAME,
        ]);
    }

    public function test_active_subscription_can_bypass_quarantine(): void
    {
        Bus::fake();

        $tenant = $this->tenant();
        TenantSubscription::query()->create([
            'tenant_id' => $tenant->id,
            'provider' => TenantSubscription::PROVIDER_PAYPAL,
            'provider_subscription_id' => 'sub_paid',
            'plan_key' => 'pro',
            'status' => TenantSubscription::STATUS_ACTIVE,
        ]);

        app(DomainAssetPolicyService::class)->quarantineRemovedHostnames(['example.com'], (string) $tenant->id, 'domain_deleted');

        $status = app(DomainAssetPolicyService::class)->quarantineStatusForTenant('www.example.com', $tenant);

        $this->assertFalse($status['blocked']);
    }

    public function test_active_manual_plan_grant_can_bypass_quarantine(): void
    {
        Bus::fake();

        $tenant = $this->tenant();
        TenantPlanGrant::query()->create([
            'tenant_id' => $tenant->id,
            'granted_plan_key' => 'pro',
            'source' => 'manual',
            'status' => TenantPlanGrant::STATUS_ACTIVE,
            'starts_at' => now('UTC')->subMinute()->toDateTimeString(),
            'ends_at' => now('UTC')->addDays(14)->toDateTimeString(),
        ]);

        app(DomainAssetPolicyService::class)->quarantineRemovedHostnames(['example.com'], (string) $tenant->id, 'domain_deleted');

        $status = app(DomainAssetPolicyService::class)->quarantineStatusForTenant('www.example.com', $tenant);

        $this->assertFalse($status['blocked']);
    }

    public function test_domain_delete_records_quarantine_before_local_row_removal(): void
    {
        $tenant = $this->tenant();
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'www.example.com',
        ]);

        $edgeShield = Mockery::mock(EdgeShieldService::class);
        $edgeShield->shouldReceive('queryD1')->once()->with(Mockery::on(
            fn (string $sql): bool => str_contains($sql, 'SELECT domain_name')
        ))->andReturn([
            'ok' => true,
            'output' => 'read-output',
            'error' => null,
        ]);
        $edgeShield->shouldReceive('parseWranglerJson')->once()->with('read-output')->andReturn([['results' => [[
            'domain_name' => 'www.example.com',
            'zone_id' => 'zone',
            'turnstile_sitekey' => 'site',
            'custom_hostname_id' => 'custom',
        ]]]]);
        $edgeShield->shouldReceive('removeDomainSecurityArtifacts')->once()->andReturn(['ok' => true]);
        $edgeShield->shouldReceive('deleteSaasCustomHostname')->once()->andReturn(['ok' => true]);
        $edgeShield->shouldReceive('queryD1')->once()->with(Mockery::on(
            fn (string $sql): bool => str_starts_with($sql, 'DELETE FROM domain_configs')
        ))->andReturn(['ok' => true, 'error' => null]);
        $edgeShield->shouldReceive('purgeDomainConfigCache')->once()->with('www.example.com')->andReturn(['ok' => true]);
        $this->app->instance(EdgeShieldService::class, $edgeShield);

        $result = app(DeleteDomainAction::class)->execute('www.example.com', false, (string) $tenant->id);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseMissing('tenant_domains', ['hostname' => 'www.example.com']);
        $this->assertDatabaseHas('domain_asset_histories', [
            'asset_key' => 'example.com',
            'last_removed_tenant_id' => $tenant->id,
            'last_removal_reason' => 'domain_deleted',
        ]);
        $this->assertNotNull(DomainAssetHistory::query()->where('asset_key', 'example.com')->value('quarantined_until'));
    }

    private function tenant(string $plan = 'starter'): Tenant
    {
        return Tenant::query()->create([
            'name' => ucfirst($plan).' Tenant',
            'slug' => $plan.'-tenant-'.strtolower(fake()->bothify('??##')),
            'plan' => $plan,
            'status' => 'active',
        ]);
    }

    private function planLimits(): PlanLimitsService
    {
        return new PlanLimitsService(Mockery::mock(D1DatabaseClient::class));
    }
}
