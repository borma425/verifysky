<?php

namespace Tests\Feature;

use App\Actions\Domains\ProvisionTenantDomainAction;
use App\Jobs\Domains\EnsureCloudflareWorkerRouteJob;
use App\Jobs\Domains\ProvisionCloudflareSaasHostnameJob;
use App\Jobs\Domains\SyncDomainConfigToD1Job;
use App\Jobs\Domains\SyncSaasSecurityArtifactsJob;
use App\Jobs\Domains\ValidateOriginServerJob;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\Domains\DomainAssetPolicyService;
use App\Services\Domains\DomainProvisioningService;
use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DomainProvisioningAsyncTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_domain_onboarding_accepts_locally_and_dispatches_async_chain(): void
    {
        Bus::fake();

        $tenant = $this->tenant();
        $edgeShield = Mockery::mock(EdgeShieldService::class);
        $edgeShield->shouldReceive('saasHostnamesForInput')->once()->with('example.com')->andReturn(['www.example.com']);
        $edgeShield->shouldReceive('detectOriginServerForInput')->never();
        $edgeShield->shouldReceive('validateOriginServerForHostname')->never();
        $edgeShield->shouldReceive('provisionSaasCustomHostname')->never();
        $edgeShield->shouldReceive('queryD1')->never();
        $this->app->instance(EdgeShieldService::class, $edgeShield);
        $this->app->instance(PlanLimitsService::class, $this->planLimits());

        $result = app(ProvisionTenantDomainAction::class)->execute([
            'domain_name' => 'example.com',
            'origin_server' => '192.0.2.10',
            'security_mode' => 'balanced',
        ], (string) $tenant->id);

        $this->assertTrue($result['ok']);
        $this->assertSame(['www.example.com'], $result['created']);
        $this->assertStringContainsString('Setup started', $result['message']);
        $this->assertDatabaseHas('tenant_domains', [
            'tenant_id' => $tenant->id,
            'hostname' => 'www.example.com',
            'origin_server' => '192.0.2.10',
            'provisioning_status' => TenantDomain::PROVISIONING_PENDING,
        ]);

        Bus::assertChained([
            ValidateOriginServerJob::class,
            EnsureCloudflareWorkerRouteJob::class,
            ProvisionCloudflareSaasHostnameJob::class,
            SyncDomainConfigToD1Job::class,
            SyncSaasSecurityArtifactsJob::class,
        ]);
    }

    public function test_failed_duplicate_for_same_tenant_is_reused_and_reset(): void
    {
        Bus::fake();

        $tenant = $this->tenant();
        $domain = TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'www.example.com',
            'provisioning_status' => TenantDomain::PROVISIONING_FAILED,
            'provisioning_error' => 'Old failure',
        ]);

        $edgeShield = Mockery::mock(EdgeShieldService::class);
        $edgeShield->shouldReceive('saasHostnamesForInput')->once()->andReturn(['www.example.com']);
        $this->app->instance(EdgeShieldService::class, $edgeShield);
        $this->app->instance(PlanLimitsService::class, $this->planLimits());

        $result = app(ProvisionTenantDomainAction::class)->execute([
            'domain_name' => 'example.com',
            'origin_server' => '',
            'security_mode' => 'monitor',
        ], (string) $tenant->id);

        $this->assertTrue($result['ok']);
        $this->assertSame($domain->id, TenantDomain::query()->where('hostname', 'www.example.com')->value('id'));
        $this->assertDatabaseHas('tenant_domains', [
            'id' => $domain->id,
            'provisioning_status' => TenantDomain::PROVISIONING_PENDING,
            'provisioning_error' => null,
            'security_mode' => 'monitor',
        ]);
    }

    public function test_failed_domains_do_not_count_against_plan_slots(): void
    {
        $tenant = $this->tenant();
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'failed.example.com',
            'provisioning_status' => TenantDomain::PROVISIONING_FAILED,
        ]);

        $usage = $this->planLimits()->getDomainsUsage($tenant);

        $this->assertSame(0, $usage['used']);
        $this->assertTrue($usage['can_add']);
    }

    public function test_chain_failure_marks_domain_failed_and_keeps_safe_error(): void
    {
        $tenant = $this->tenant();
        $domain = TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'www.example.com',
            'provisioning_status' => TenantDomain::PROVISIONING_PROVISIONING,
        ]);

        $this->app->instance(EdgeShieldService::class, Mockery::mock(EdgeShieldService::class));

        (new ValidateOriginServerJob((int) $domain->id, 'example.com'))->failed(
            new RuntimeException('Cloudflare token rejected')
        );

        $domain->refresh();
        $this->assertSame(TenantDomain::PROVISIONING_FAILED, $domain->provisioning_status);
        $this->assertSame('Domain setup failed. Please try again or contact support.', $domain->provisioning_error);
        $this->assertNotNull($domain->provisioning_finished_at);
    }

    public function test_cloudflare_origin_alias_is_kept_internal_after_provisioning(): void
    {
        $tenant = $this->tenant();
        $domain = TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'www.example.com',
            'origin_server' => '192.0.2.10',
            'provisioning_status' => TenantDomain::PROVISIONING_PROVISIONING,
        ]);

        $edgeShield = Mockery::mock(EdgeShieldService::class);
        $edgeShield->shouldReceive('provisionSaasCustomHostname')->once()->with('www.example.com', '192.0.2.10')->andReturn([
            'ok' => true,
            'error' => null,
            'domain_name' => 'www.example.com',
            'zone_id' => 'zone-id',
            'cname_target' => 'customers.verifysky.com',
            'custom_hostname_id' => 'host-id',
            'hostname_status' => 'active',
            'ssl_status' => 'active',
            'ownership_verification_json' => json_encode(['type' => 'txt']),
            'effective_origin_server' => 'origin-www-example-com-12345678.verifysky.com',
        ]);

        $assets = Mockery::mock(DomainAssetPolicyService::class);
        $assets->shouldReceive('grantTrialIfEligible')->once();

        (new DomainProvisioningService($edgeShield, $assets))->provisionCloudflareHostname((int) $domain->id);

        $domain->refresh();
        $this->assertSame('192.0.2.10', $domain->origin_server);
        $this->assertSame('origin-www-example-com-12345678.verifysky.com', $domain->cloudflare_origin_server);
        $this->assertSame(TenantDomain::PROVISIONING_ACTIVE, $domain->provisioning_status);
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Starter Tenant',
            'slug' => 'starter-tenant-'.strtolower(fake()->bothify('??##')),
            'plan' => 'starter',
            'status' => 'active',
        ]);
    }

    private function planLimits(): PlanLimitsService
    {
        return new PlanLimitsService(Mockery::mock(D1DatabaseClient::class));
    }
}
