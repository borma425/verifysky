<?php

namespace Tests\Feature;

use App\Actions\Domains\DeleteDomainAction;
use App\Actions\Domains\DeleteDomainGroupAction;
use App\Actions\Domains\ProvisionTenantDomainAction;
use App\Actions\Domains\RefreshDomainGroupVerificationAction;
use App\Actions\Domains\RefreshDomainVerificationAction;
use App\Actions\Domains\ToggleDomainForceCaptchaAction;
use App\Actions\Domains\UpdateDomainOriginAction;
use App\Actions\Domains\UpdateDomainSecurityModeAction;
use App\Actions\Domains\UpdateDomainStatusAction;
use App\Actions\Domains\UpdateDomainThresholdsAction;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Repositories\DomainConfigRepository;
use App\Services\EdgeShield\D1DatabaseClient;
use App\Services\EdgeShieldService;
use App\Services\Plans\PlanLimitsService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class DomainPlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    private MockInterface $edgeShield;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->edgeShield = Mockery::mock(EdgeShieldService::class);
        $this->edgeShield->shouldIgnoreMissing();
        $this->app->instance(EdgeShieldService::class, $this->edgeShield);

        $this->app->instance(RefreshDomainVerificationAction::class, Mockery::mock(RefreshDomainVerificationAction::class));
        $this->app->instance(RefreshDomainGroupVerificationAction::class, Mockery::mock(RefreshDomainGroupVerificationAction::class));
        $this->app->instance(DeleteDomainAction::class, Mockery::mock(DeleteDomainAction::class));
        $this->app->instance(DeleteDomainGroupAction::class, Mockery::mock(DeleteDomainGroupAction::class));
        $this->app->instance(UpdateDomainOriginAction::class, Mockery::mock(UpdateDomainOriginAction::class));
        $this->app->instance(UpdateDomainSecurityModeAction::class, Mockery::mock(UpdateDomainSecurityModeAction::class));
        $this->app->instance(UpdateDomainStatusAction::class, Mockery::mock(UpdateDomainStatusAction::class));
        $this->app->instance(ToggleDomainForceCaptchaAction::class, Mockery::mock(ToggleDomainForceCaptchaAction::class));
        $this->app->instance(UpdateDomainThresholdsAction::class, Mockery::mock(UpdateDomainThresholdsAction::class));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_starter_tenant_cannot_post_second_domain_after_reaching_plan_limit(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Starter Tenant',
            'slug' => 'starter-tenant',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);

        $this->app->instance(PlanLimitsService::class, $this->realPlanLimitsService());

        $action = Mockery::mock(ProvisionTenantDomainAction::class);
        $action->shouldReceive('execute')->never();
        $this->app->instance(ProvisionTenantDomainAction::class, $action);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->from(route('domains.index'))->post(route('domains.store'), [
            'domain_name' => 'second-example.com',
            'origin_server' => '192.0.2.10',
            'security_mode' => 'balanced',
        ]);

        $response->assertRedirect(route('domains.index'));
        $response->assertSessionHasErrors([
            'domain_name' => 'You have reached the maximum number of domains for your current plan. Upgrade to Starter to add more domains.',
        ]);
        $this->assertDatabaseCount('tenant_domains', 1);
    }

    public function test_tenant_with_remaining_capacity_can_add_domain_normally(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Starter Tenant',
            'slug' => 'starter-tenant',
            'plan' => 'starter',
            'status' => 'active',
        ]);

        $this->app->instance(PlanLimitsService::class, $this->realPlanLimitsService());

        $action = Mockery::mock(ProvisionTenantDomainAction::class);
        $action->shouldReceive('execute')->once()->with([
            'domain_name' => 'example.com',
            'origin_server' => '192.0.2.10',
            'security_mode' => 'balanced',
        ], (string) $tenant->id)->andReturn([
            'ok' => true,
            'created' => ['example.com'],
            'origin_mode' => 'manual',
        ]);
        $this->app->instance(ProvisionTenantDomainAction::class, $action);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->from(route('domains.index'))->post(route('domains.store'), [
            'domain_name' => 'example.com',
            'origin_server' => '192.0.2.10',
            'security_mode' => 'balanced',
        ]);

        $response->assertRedirect(route('domains.index'));
        $response->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Setup started for example.com.'));
    }

    public function test_domains_index_shows_usage_and_disables_add_button_when_limit_is_reached(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Starter Tenant',
            'slug' => 'starter-tenant',
            'plan' => 'starter',
            'status' => 'active',
        ]);
        TenantDomain::query()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'example.com',
        ]);

        $repository = Mockery::mock(DomainConfigRepository::class);
        $repository->shouldReceive('listForTenant')->once()->with((string) $tenant->id, false)->andReturn([
            'ok' => true,
            'error' => null,
            'domains' => [[
                'domain_name' => 'example.com',
                'zone_id' => 'zone1',
                'cname_target' => 'customers.verifysky.com',
                'hostname_status' => 'active',
                'ssl_status' => 'active',
                'status' => 'active',
                'force_captcha' => 0,
                'security_mode' => 'balanced',
                'created_at' => '2026-03-22 00:00:00',
            ]],
        ]);
        $this->app->instance(DomainConfigRepository::class, $repository);
        $this->app->instance(PlanLimitsService::class, $this->realPlanLimitsService());
        $this->app->instance(ProvisionTenantDomainAction::class, Mockery::mock(ProvisionTenantDomainAction::class));

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->id,
        ])->get(route('domains.index'));

        $response->assertOk()
            ->assertSee('1 / 1 domains')
            ->assertSee('Upgrade your plan to add more domains.')
            ->assertSee('Plan Limit Reached');

        $this->assertMatchesRegularExpression(
            '/<button[^>]*disabled[^>]*>\s*<img[^>]*>\s*Add domain\s*<\/button>/s',
            $response->getContent()
        );
    }

    private function realPlanLimitsService(): PlanLimitsService
    {
        return new PlanLimitsService(Mockery::mock(D1DatabaseClient::class));
    }
}
