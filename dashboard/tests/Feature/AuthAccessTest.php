<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_can_login_but_cannot_open_admin_settings(): void
    {
        User::query()->create([
            'name' => 'Regular User',
            'email' => 'regular@example.test',
            'password' => Hash::make('User123!'),
            'role' => 'user',
        ]);

        $login = $this->post('/wow/login', [
            'username' => 'regular@example.test',
            'password' => 'User123!',
        ]);

        $login->assertRedirect('/dashboard');
        $this->assertTrue((bool) session('is_authenticated'));
        $this->assertFalse((bool) session('is_admin'));
        $this->assertSame('user', session('user_role'));

        $this->get('/admin/settings')->assertNotFound();
    }

    public function test_regular_user_without_membership_gets_tenant_context_on_login(): void
    {
        User::query()->create([
            'name' => 'Tenantless User',
            'email' => 'tenantless@example.test',
            'password' => Hash::make('User123!'),
            'role' => 'user',
        ]);

        $login = $this->post('/wow/login', [
            'username' => 'tenantless@example.test',
            'password' => 'User123!',
        ]);

        $login->assertRedirect('/dashboard');
        $this->assertTrue((bool) session('is_authenticated'));
        $this->assertFalse((bool) session('is_admin'));
        $this->assertNotEmpty(session('current_tenant_id'));
    }

    public function test_admin_user_is_redirected_to_admin_overview_after_login(): void
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
}
