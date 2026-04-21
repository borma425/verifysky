<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class LocalE2eSeeder extends Seeder
{
    /**
     * Seed local-only records needed for end-to-end dashboard testing.
     */
    public function run(): void
    {
        $tenant = $this->resolveLocalTenant();

        $this->ensureMembership($tenant, 'admin@verifysky.test', 'owner');
        $this->ensureMembership($tenant, 'user@verifysky.test', 'member');
        $this->ensureTenantUsage($tenant);
        $this->ensureLocalDomain($tenant);
    }

    private function resolveLocalTenant(): Tenant
    {
        if (Schema::hasTable('tenant_domains')) {
            $domain = TenantDomain::query()
                ->with('tenant')
                ->where('hostname', 'www.cashup.cash')
                ->first();

            if ($domain instanceof TenantDomain && $domain->tenant instanceof Tenant) {
                return $domain->tenant;
            }
        }

        return Tenant::query()->firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default Tenant', 'plan' => 'enterprise', 'status' => 'active']
        );
    }

    private function ensureMembership(Tenant $tenant, string $email, string $role): void
    {
        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User) {
            return;
        }

        TenantMembership::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            ['role' => $role]
        );
    }

    private function ensureTenantUsage(Tenant $tenant): void
    {
        if (! Schema::hasTable('tenant_usage') || ! Schema::hasColumn('tenants', 'billing_start_at')) {
            return;
        }

        $billingStartAt = $tenant->getAttribute('billing_start_at');
        if ($billingStartAt === null || $billingStartAt === '') {
            $tenant->forceFill(['billing_start_at' => now()->utc()->startOfMonth()])->save();
        }

        $tenant->currentUsageCycle();
    }

    private function ensureLocalDomain(Tenant $tenant): void
    {
        if (! Schema::hasTable('tenant_domains')) {
            return;
        }

        TenantDomain::query()->firstOrCreate(
            ['hostname' => 'www.cashup.cash'],
            [
                'tenant_id' => $tenant->id,
                'cname_target' => (string) config('edgeshield.saas_cname_target', 'customers.verifysky.com'),
                'hostname_status' => 'active',
                'ssl_status' => 'active',
                'security_mode' => 'balanced',
                'force_captcha' => false,
                'verified_at' => now(),
            ]
        );
    }
}
