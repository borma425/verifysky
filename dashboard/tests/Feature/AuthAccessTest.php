<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_can_login_only_from_tenant_login_path(): void
    {
        [$tenant, $user] = $this->tenantUser('regular', 'account/regular');

        $adminPathLogin = $this->post('/wow/login', [
            'username' => $user->email,
            'password' => 'User123!',
        ]);
        $adminPathLogin->assertSessionHasErrors('credentials');
        $this->assertFalse((bool) session('is_authenticated'));

        $tenantLogin = $this->post('/'.$tenant->login_path, [
            'username' => $user->email,
            'password' => 'User123!',
        ]);

        $tenantLogin->assertRedirect('/dashboard');
        $this->assertTrue((bool) session('is_authenticated'));
        $this->assertFalse((bool) session('is_admin'));
        $this->assertSame('user', session('user_role'));
        $this->assertSame((string) $tenant->getKey(), session('current_tenant_id'));

        $this->get('/admin/settings')->assertNotFound();
    }

    public function test_regular_user_cannot_login_from_another_tenant_path(): void
    {
        [, $user] = $this->tenantUser('regular', 'account/regular');
        $otherTenant = Tenant::query()->create([
            'name' => 'Other Tenant',
            'slug' => 'other',
            'plan' => 'starter',
            'status' => 'active',
            'login_path' => 'account/other',
        ]);

        $login = $this->post('/'.$otherTenant->login_path, [
            'username' => $user->email,
            'password' => 'User123!',
        ]);

        $login->assertSessionHasErrors('credentials');
        $this->assertFalse((bool) session('is_authenticated'));
    }

    public function test_admin_user_is_redirected_to_admin_overview_after_admin_path_login(): void
    {
        User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => Hash::make('Admin123!'),
            'role' => 'admin',
        ]);

        $login = $this->post('/wow/login', [
            'username' => 'admin@example.test',
            'password' => 'Admin123!',
        ]);

        $login->assertRedirect('/admin');
        $this->assertTrue((bool) session('is_authenticated'));
        $this->assertTrue((bool) session('is_admin'));
        $this->assertSame('admin', session('user_role'));
    }

    public function test_admin_user_cannot_login_from_tenant_path(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Regular Tenant',
            'slug' => 'regular',
            'plan' => 'starter',
            'status' => 'active',
            'login_path' => 'account/regular',
        ]);
        User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => Hash::make('Admin123!'),
            'role' => 'admin',
        ]);

        $login = $this->post('/'.$tenant->login_path, [
            'username' => 'admin@example.test',
            'password' => 'Admin123!',
        ]);

        $login->assertSessionHasErrors('credentials');
        $this->assertFalse((bool) session('is_authenticated'));
    }

    public function test_regular_user_logout_returns_to_tenant_login_path(): void
    {
        [$tenant, $user] = $this->tenantUser('regular', 'account/regular');

        $logout = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => false,
            'user_id' => $user->getKey(),
            'user_role' => 'user',
            'current_tenant_id' => (string) $tenant->getKey(),
        ])->post('/logout');

        $logout->assertRedirect(url('/'.$tenant->login_path));
    }

    public function test_admin_logout_returns_to_admin_login_path(): void
    {
        $logout = $this->withSession([
            'is_authenticated' => true,
            'is_admin' => true,
            'user_role' => 'admin',
        ])->post('/logout');

        $logout->assertRedirect(route('admin.login'));
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
            'name' => 'Regular User',
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
}
