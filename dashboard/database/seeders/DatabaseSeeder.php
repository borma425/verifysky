<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\TenantLoginPath;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $resetPasswords = filter_var(config('dashboard.seed_reset_passwords', false), FILTER_VALIDATE_BOOL);

        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default Tenant', 'plan' => 'enterprise', 'status' => 'active']
        );
        $this->ensureLoginPath($tenant);

        $admin = $this->seedUser(
            'admin@verifysky.test',
            'VerifySky Admin',
            'admin',
            (string) config('dashboard.seed_admin_password', 'Admin123!'),
            $resetPasswords
        );

        TenantMembership::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $admin->id],
            ['role' => 'owner']
        );

        $user = $this->seedUser(
            'user@verifysky.test',
            'VerifySky User',
            'user',
            (string) config('dashboard.seed_user_password', 'User123!'),
            $resetPasswords
        );

        TenantMembership::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['role' => 'member']
        );
    }

    private function seedUser(
        string $email,
        string $name,
        string $role,
        string $password,
        bool $resetPassword
    ): User {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->fill([
            'name' => $name,
            'role' => $role,
        ]);

        if (! $user->exists || $resetPassword) {
            $user->password = Hash::make($password);
        }

        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
        }

        $user->save();

        return $user;
    }

    private function ensureLoginPath(Tenant $tenant): void
    {
        if (! Schema::hasColumn('tenants', 'login_path') || trim((string) $tenant->login_path) !== '') {
            return;
        }

        $tenant->forceFill([
            'login_path' => TenantLoginPath::defaultForTenant((int) $tenant->getKey(), (string) $tenant->slug),
        ])->save();
    }
}
