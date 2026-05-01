<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Billing\TenantBillingStatusService;
use App\Services\Billing\TenantSubscriptionService;
use App\Services\EdgeShieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class SettingsSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_customer_settings_show_account_fields_only(): void
    {
        [$tenant, $user] = $this->tenantUser('client', 'account/client');
        $this->bindLayoutBillingStatus();

        $response = $this->withTenantSession($tenant, $user)->get('/settings');

        $response->assertOk()
            ->assertSee('Account Settings')
            ->assertSee('User Profile')
            ->assertSee('User Name')
            ->assertSee('Email')
            ->assertSee('Avatar')
            ->assertSee($user->name)
            ->assertSee($user->email)
            ->assertSee('Security')
            ->assertSee('Current Password')
            ->assertSee('New Password')
            ->assertSee('Confirm Password')
            ->assertSee('Tenant Access')
            ->assertSee('Account Name')
            ->assertSee('Login Slug')
            ->assertSee(url('/account').'/')
            ->assertDontSee('name="user_name"', false)
            ->assertDontSee('OpenRouter')
            ->assertDontSee('Cloudflare')
            ->assertDontSee('Worker Script')
            ->assertDontSee('JWT Secret')
            ->assertDontSee('ES Admin Token');
    }

    public function test_customer_cannot_open_admin_platform_settings(): void
    {
        [$tenant, $user] = $this->tenantUser('client', 'account/client');
        $this->bindLayoutBillingStatus();

        $this->withTenantSession($tenant, $user)->get('/admin/settings')->assertNotFound();
    }

    public function test_admin_platform_settings_read_environment_state_without_secret_inputs(): void
    {
        Config::set('edgeshield.cloudflare_api_token', 'cf-token');
        Config::set('edgeshield.runtime.openrouter_api_key', 'openrouter-key');
        Config::set('edgeshield.runtime.jwt_secret', str_repeat('j', 32));
        Config::set('edgeshield.runtime.es_admin_token', 'admin-token');

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
            'user_role' => 'admin',
        ])->get('/admin/settings');

        $response->assertOk()
            ->assertSee('Platform Settings')
            ->assertSee('OpenRouter API Key')
            ->assertSee('Configured')
            ->assertDontSee('name="openrouter_api_key"', false)
            ->assertDontSee('name="cf_api_token"', false)
            ->assertDontSee('name="jwt_secret"', false);
    }

    public function test_customer_can_update_only_own_account_login_path(): void
    {
        [$tenant, $user] = $this->tenantUser('client', 'account/client');
        $otherTenant = Tenant::query()->create([
            'name' => 'Other Tenant',
            'slug' => 'other',
            'plan' => 'starter',
            'status' => 'active',
            'login_path' => 'account/other',
        ]);
        $this->bindLayoutBillingStatus();

        $response = $this->withTenantSession($tenant, $user)->post('/settings', [
            'email' => 'owner@example.test',
            'current_password' => 'User123!',
            'name' => 'Client Renamed',
            'login_slug' => 'Client Private!!',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Account settings saved.');
        $tenant->refresh();
        $user->refresh();
        $otherTenant->refresh();
        $this->assertSame('Client User', $user->name);
        $this->assertSame('owner@example.test', $user->email);
        $this->assertSame('Client Renamed', $tenant->name);
        $this->assertSame('account/client-private', $tenant->login_path);
        $this->assertSame('account/other', $otherTenant->login_path);
    }

    public function test_customer_can_update_profile_avatar(): void
    {
        [$tenant, $user] = $this->tenantUser('client', 'account/client');
        Storage::fake('public');
        $this->bindLayoutBillingStatus();

        $response = $this->withTenantSession($tenant, $user)->post('/settings', [
            'email' => 'client-owner@example.test',
            'current_password' => 'User123!',
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 128, 128),
            'name' => 'Client Tenant',
            'login_slug' => 'client',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Account settings saved.');
        $response->assertSessionHas('user_email', 'client-owner@example.test');

        $user->refresh();
        $this->assertSame('Client User', $user->name);
        $this->assertSame('client-owner@example.test', $user->email);
        $this->assertNotNull($user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_customer_can_remove_profile_avatar_without_current_password(): void
    {
        [$tenant, $user] = $this->tenantUser('client', 'account/client');
        Storage::fake('public');
        $user->forceFill(['avatar_path' => 'avatars/current.jpg'])->save();
        Storage::disk('public')->put('avatars/current.jpg', 'avatar');
        $this->bindLayoutBillingStatus();

        $response = $this->withTenantSession($tenant, $user)->post('/settings', [
            'email' => 'client@example.test',
            'remove_avatar' => '1',
            'name' => 'Client Tenant',
            'login_slug' => 'client',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Account settings saved.');
        $user->refresh();
        $this->assertNull($user->avatar_path);
        Storage::disk('public')->assertMissing('avatars/current.jpg');
    }

    public function test_customer_email_change_rejects_duplicate_or_invalid_current_password(): void
    {
        [$tenant, $user] = $this->tenantUser('client', 'account/client');
        User::query()->create([
            'name' => 'Other User',
            'email' => 'used@example.test',
            'password' => Hash::make('User123!'),
            'role' => 'user',
        ]);
        $this->bindLayoutBillingStatus();

        $duplicate = $this->withTenantSession($tenant, $user)->from('/settings')->post('/settings', [
            'email' => 'used@example.test',
            'current_password' => 'User123!',
            'name' => 'Client Tenant',
            'login_slug' => 'client',
        ]);
        $duplicate->assertRedirect('/settings')->assertSessionHasErrors('email');

        $wrongPassword = $this->withTenantSession($tenant, $user)->from('/settings')->post('/settings', [
            'email' => 'new-client@example.test',
            'current_password' => 'wrong-password',
            'name' => 'Client Tenant',
            'login_slug' => 'client',
        ]);
        $wrongPassword->assertRedirect('/settings')->assertSessionHasErrors('current_password');

        $user->refresh();
        $this->assertSame('client@example.test', $user->email);
    }

    public function test_customer_can_change_password_and_login_with_new_password_only(): void
    {
        [$tenant, $user] = $this->tenantUser('client', 'account/client');
        $this->bindLayoutBillingStatus();

        $response = $this->withTenantSession($tenant, $user)->post('/settings', [
            'email' => 'client@example.test',
            'current_password' => 'User123!',
            'password' => 'NewSecure123!',
            'password_confirmation' => 'NewSecure123!',
            'name' => 'Client Tenant',
            'login_slug' => 'client',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Account settings saved.');
        $user->refresh();
        $this->assertFalse(Hash::check('User123!', (string) $user->password));
        $this->assertTrue(Hash::check('NewSecure123!', (string) $user->password));

        session()->flush();

        $oldLogin = $this->post('/'.$tenant->login_path, [
            'username' => 'client@example.test',
            'password' => 'User123!',
        ]);
        $oldLogin->assertSessionHasErrors('credentials');

        session()->flush();

        $newLogin = $this->post('/'.$tenant->login_path, [
            'username' => 'client@example.test',
            'password' => 'NewSecure123!',
        ]);
        $newLogin->assertRedirect('/dashboard');
        $this->assertSame((string) $tenant->getKey(), session('current_tenant_id'));
    }

    public function test_customer_profile_changes_do_not_reassign_domains_or_billing(): void
    {
        [$tenant, $user] = $this->tenantUser('client', 'account/client');
        $domain = TenantDomain::query()->create([
            'tenant_id' => $tenant->getKey(),
            'hostname' => 'client.example.test',
            'cname_target' => 'customers.verifysky.com',
        ]);
        $subscription = TenantSubscription::query()->create([
            'tenant_id' => $tenant->getKey(),
            'provider' => TenantSubscription::PROVIDER_PAYPAL,
            'provider_subscription_id' => 'I-LINKED',
            'plan_key' => 'growth',
            'provider_plan_id' => 'P-GROWTH',
            'status' => TenantSubscription::STATUS_ACTIVE,
            'payer_email' => 'client@example.test',
            'metadata_json' => [
                'buyer_user_id' => $user->getKey(),
                'buyer_email' => 'client@example.test',
            ],
        ]);
        $membershipId = TenantMembership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->value('id');
        $this->bindLayoutBillingStatus();

        $response = $this->withTenantSession($tenant, $user)->post('/settings', [
            'email' => 'renamed-owner@example.test',
            'current_password' => 'User123!',
            'password' => 'NewSecure123!',
            'password_confirmation' => 'NewSecure123!',
            'name' => 'Client Tenant Renamed',
            'login_slug' => 'client-renamed',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Account settings saved.');

        $tenant->refresh();
        $domain->refresh();
        $subscription->refresh();

        $this->assertSame((string) $tenant->getKey(), (string) $domain->tenant_id);
        $this->assertSame((string) $tenant->getKey(), (string) $subscription->tenant_id);
        $this->assertSame('client.example.test', $domain->hostname);
        $this->assertSame('I-LINKED', $subscription->provider_subscription_id);
        $this->assertSame('client@example.test', $subscription->payer_email);
        $user->refresh();
        $this->assertSame('Client User', $user->name);
        $this->assertSame('renamed-owner@example.test', $user->email);
        $this->assertSame($membershipId, TenantMembership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->value('id'));
        $this->assertTrue(app(TenantSubscriptionService::class)->userCanManageBilling($tenant, (int) $user->getKey()));
    }

    public function test_customer_login_path_rejects_reserved_and_duplicate_paths(): void
    {
        [$tenant, $user] = $this->tenantUser('client', 'account/client');
        Tenant::query()->create([
            'name' => 'Other Tenant',
            'slug' => 'other',
            'plan' => 'starter',
            'status' => 'active',
            'login_path' => 'account/other',
        ]);
        $this->bindLayoutBillingStatus();

        $reserved = $this->withTenantSession($tenant, $user)->from('/settings')->post('/settings', [
            'email' => 'client@example.test',
            'name' => 'Client Tenant',
            'login_slug' => 'admin',
        ]);
        $reserved->assertRedirect('/settings')->assertSessionHasErrors('login_slug');

        $duplicate = $this->withTenantSession($tenant, $user)->from('/settings')->post('/settings', [
            'email' => 'client@example.test',
            'name' => 'Client Tenant',
            'login_slug' => 'other',
        ]);
        $duplicate->assertRedirect('/settings')->assertSessionHasErrors('login_slug');
    }

    public function test_admin_platform_sync_uses_admin_route_only(): void
    {
        $edge = Mockery::mock(EdgeShieldService::class);
        $edge->shouldReceive('syncCloudflareFromDashboardSettings')
            ->once()
            ->andReturn(['ok' => true, 'errors' => [], 'logs' => [], 'deploy' => ['ok' => true]]);
        $this->app->instance(EdgeShieldService::class, $edge);

        $response = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
            'user_role' => 'admin',
        ])->post('/admin/settings');

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Environment settings synced to edge services successfully.');
    }

    private function tenantUser(string $slug, string $loginPath): array
    {
        $tenant = Tenant::query()->create([
            'name' => ucfirst($slug).' Tenant',
            'slug' => $slug,
            'plan' => 'starter',
            'status' => 'active',
            'login_path' => $loginPath,
        ]);
        $user = User::query()->create([
            'name' => ucfirst($slug).' User',
            'email' => $slug.'@example.test',
            'password' => Hash::make('User123!'),
            'role' => 'user',
        ]);
        TenantMembership::query()->create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'role' => 'owner',
        ]);

        return [$tenant, $user];
    }

    private function withTenantSession(Tenant $tenant, User $user): self
    {
        return $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'current_tenant_id' => (string) $tenant->getKey(),
            'user_id' => $user->getKey(),
            'user_role' => 'user',
            'user_name' => $user->name,
            'user_email' => $user->email,
        ]);
    }

    private function bindLayoutBillingStatus(): void
    {
        $billingStatus = Mockery::mock(TenantBillingStatusService::class);
        $billingStatus->shouldReceive('forTenantId')->andReturn(null);
        $this->app->instance(TenantBillingStatusService::class, $billingStatus);
    }
}
