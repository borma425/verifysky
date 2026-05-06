<?php

namespace Tests\Feature;

use App\Jobs\SendAccountActivationMailJob;
use App\Jobs\SendWelcomeCustomerMailJob;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_page_is_available_before_tenant_catch_all(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
        $response->assertSee('Start defending your traffic');
        $response->assertSee('Create Account');
        $response->assertDontSee('Sign in');
        $response->assertDontSee(route('admin.login'));
    }

    public function test_registration_creates_inactive_user_tenant_membership_and_queues_activation_email(): void
    {
        Queue::fake();

        $response = $this->post('/register', [
            'name' => 'Nora Customer',
            'email' => 'nora@example.test',
            'workspace_name' => 'Nora Media',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ]);

        $response->assertRedirect(route('register.pending'));
        $response->assertSessionHas('status', 'Your account has been created. We sent your login details and activation link to your email.');

        $user = User::query()->where('email', 'nora@example.test')->firstOrFail();
        $tenant = Tenant::query()->where('slug', 'nora-media')->firstOrFail();

        $this->assertSame('user', $user->role);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('starter', $tenant->plan);
        $this->assertSame('active', $tenant->status);
        $this->assertStringStartsWith('account/nora-media-', (string) $tenant->login_path);
        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'role' => 'owner',
        ]);
        $this->assertFalse((bool) session('is_authenticated'));
        $this->assertNull(session('current_tenant_id'));
        Queue::assertPushed(SendAccountActivationMailJob::class, fn (SendAccountActivationMailJob $job): bool => $job->userId === $user->getKey());
        Queue::assertNotPushed(SendWelcomeCustomerMailJob::class);
    }

    public function test_registration_pending_page_tells_user_to_check_email(): void
    {
        $response = $this->get(route('register.pending'));

        $response->assertOk();
        $response->assertSee('Check your email');
        $response->assertSee('We sent your login details and activation link');
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'used@example.test']);

        $response = $this->from('/register')->post('/register', [
            'name' => 'Duplicate Customer',
            'email' => 'used@example.test',
            'workspace_name' => 'Duplicate Media',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('tenants', ['slug' => 'duplicate-media']);
    }

    public function test_activation_link_marks_user_active_and_redirects_to_tenant_login_path(): void
    {
        [$tenant, $user] = $this->pendingTenantUser();
        $url = URL::temporarySignedRoute('account.activate', now()->addDays(7), [
            'user' => $user->getKey(),
            'hash' => sha1((string) $user->email),
        ]);

        $response = $this->get($url);

        $response->assertRedirect(url('/'.$tenant->login_path));
        $response->assertSessionHas('status', 'Your account is active. You can sign in now.');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_activation_link_can_be_opened_more_than_once(): void
    {
        [$tenant, $user] = $this->pendingTenantUser();
        $url = URL::temporarySignedRoute('account.activate', now()->addDays(7), [
            'user' => $user->getKey(),
            'hash' => sha1((string) $user->email),
        ]);

        $this->get($url)->assertRedirect(url('/'.$tenant->login_path));
        $firstActivatedAt = $user->fresh()->email_verified_at;

        $this->get($url)->assertRedirect(url('/'.$tenant->login_path));
        $this->assertTrue($firstActivatedAt->equalTo($user->fresh()->email_verified_at));
    }

    public function test_activation_link_rejects_invalid_hash(): void
    {
        [, $user] = $this->pendingTenantUser();
        $url = URL::temporarySignedRoute('account.activate', now()->addDays(7), [
            'user' => $user->getKey(),
            'hash' => 'invalid-hash',
        ]);

        $this->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_activation_link_rejects_expired_signature(): void
    {
        [, $user] = $this->pendingTenantUser();
        $url = URL::temporarySignedRoute('account.activate', now()->subMinute(), [
            'user' => $user->getKey(),
            'hash' => sha1((string) $user->email),
        ]);

        $this->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_unactivated_user_cannot_login_until_activation_link_is_opened(): void
    {
        [$tenant, $user] = $this->pendingTenantUser();

        $this->post('/'.$tenant->login_path, [
            'username' => $user->email,
            'password' => 'SecurePass123',
        ])->assertSessionHasErrors('credentials');
        $this->assertFalse((bool) session('is_authenticated'));

        $this->get(URL::temporarySignedRoute('account.activate', now()->addDays(7), [
            'user' => $user->getKey(),
            'hash' => sha1((string) $user->email),
        ]))->assertRedirect(url('/'.$tenant->login_path));

        $this->post('/'.$tenant->login_path, [
            'username' => $user->email,
            'password' => 'SecurePass123',
        ])->assertRedirect('/dashboard');
        $this->assertTrue((bool) session('is_authenticated'));
    }

    private function pendingTenantUser(): array
    {
        $tenant = Tenant::query()->create([
            'name' => 'Nora Media',
            'slug' => 'nora-media',
            'plan' => 'starter',
            'status' => 'active',
            'login_path' => 'account/nora-media',
        ]);
        $user = User::query()->create([
            'name' => 'Nora Customer',
            'email' => 'nora@example.test',
            'password' => 'SecurePass123',
            'role' => 'user',
            'email_verified_at' => null,
        ]);
        TenantMembership::query()->create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'role' => 'owner',
        ]);

        return [$tenant, $user];
    }
}
