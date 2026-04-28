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
        $this->assertStringContainsString('Provisioning started', $result['message']);
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
        $this->assertSame('Domain provisioning failed. Please retry or contact support.', $domain->provisioning_error);
        $this->assertNotNull($domain->provisioning_finished_at);
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
